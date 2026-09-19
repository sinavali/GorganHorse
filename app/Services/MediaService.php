<?php
declare(strict_types=1);

/**
 * File: app/Services/MediaService.php
 *
 * Purpose:
 *   Store, process, and delete uploaded media (avatars, horse/club images,
 *   documents). Enforces per-kind size caps, per-user quota, UUID filenames,
 *   MIME sniffing, extension coercion, image resizing/compression, and SVG
 *   sanitization (Blueprint §19, Technical §21).
 *
 * Dependencies:
 *   - Database (main)
 *   - ImageProcessor (shim)
 *   - HtmlSanitizer (shim)
 *
 * Conventions:
 *   - Filenames are UUIDs; the original name is stored as metadata only.
 *   - No direct path leakage: downloads are served via authenticated routes.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\ValidationException;
use App\Support\Vendor\HtmlSanitizer;
use App\Support\Vendor\ImageProcessor;

/**
 * Class: MediaService
 *
 * Purpose: Handle media uploads and lifecycle.
 */
final class MediaService
{
    private Database $db;
    private string $uploadsDir;
    private SettingService $settings;
    /** @var array<string,string> Allowed MIME => extension. */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    /**
     * @param Database       $db         Main database.
     * @param SettingService $settings   Settings.
     * @param string         $uploadsDir Absolute uploads directory.
     */
    public function __construct(Database $db, SettingService $settings, string $uploadsDir)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->uploadsDir = rtrim($uploadsDir, '/');
    }

    /**
     * Store an uploaded file and record a media row.
     *
     * @param array  $file   A $_FILES entry.
     * @param string $kind   avatar | horse | club | demo | doc.
     * @param int|null $ownerId Uploader user id.
     * @param string $subdir Optional subdirectory (e.g. horses/{id}).
     * @return array{id:int,uuid:string,path:string,mime:string,size_bytes:int,width:?int,height:?int} Media metadata.
     * @throws ValidationException On size/type/quota/sniff failures.
     *
     * Side effects: writes a file under uploads/ and a row in media.
     */
    public function store(array $file, string $kind, ?int $ownerId = null, string $subdir = ''): array
    {
        $this->assertUploadOk($file);

        $mime = $this->sniffMime((string) $file['tmp_name']);
        if ($mime === null || !isset(self::MIME_EXT[$mime])) {
            throw new ValidationException('Unsupported file type', 'file', 'VALIDATION_FAILED');
        }
        $isImage = str_starts_with($mime, 'image/');
        $isSvg = $mime === 'image/svg+xml';

        $maxBytes = $isImage
            ? (int) $this->settings->get('uploads.max_image_mb', 10) * 1024 * 1024
            : (int) $this->settings->get('uploads.max_doc_mb', 25) * 1024 * 1024;
        if ((int) $file['size'] > $maxBytes) {
            throw new ValidationException('File exceeds the size limit', 'file', 'VALIDATION_FAILED');
        }

        if ($ownerId !== null) {
            $this->assertQuota($ownerId, (int) $file['size']);
        }

        $uuid = uuid4();
        $ext = self::MIME_EXT[$mime];
        $relativeDir = trim('uploads/' . ($subdir !== '' ? $subdir : $this->dateFolders()), '/');
        $absoluteDir = BASE_PATH . '/' . $relativeDir;
        if (!is_dir($absoluteDir)) { @mkdir($absoluteDir, 0775, true); }
        $absolutePath = $absoluteDir . '/' . $uuid . '.' . $ext;
        $relativePath = $relativeDir . '/' . $uuid . '.' . $ext;

        if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
            // Fallback for non-upload contexts (e.g. tests / CLI seeding).
            if (!@copy((string) $file['tmp_name'], $absolutePath)) {
                throw new ValidationException('Failed to store the uploaded file', 'file', 'VALIDATION_FAILED');
            }
        }

        $width = null;
        $height = null;
        if ($isSvg) {
            $raw = (string) file_get_contents($absolutePath);
            $clean = HtmlSanitizer::cleanSvg($raw);
            if ($clean === null) {
                @unlink($absolutePath);
                throw new ValidationException('Invalid or unsafe SVG', 'file', 'VALIDATION_FAILED');
            }
            file_put_contents($absolutePath, $clean);
        } elseif ($isImage) {
            $maxDim = (int) $this->settings->get('uploads.image_max_dimension', 2560);
            $quality = (int) $this->settings->get('uploads.image_quality', 82);
            $format = (string) $this->settings->get('uploads.image_format', 'original');
            $result = ImageProcessor::process($absolutePath, $mime, $maxDim, $quality, $format);
            if ($result['path'] !== $absolutePath) {
                @unlink($absolutePath);
                $absolutePath = $result['path'];
                $relativePath = $relativeDir . '/' . basename($absolutePath);
            }
            $width = $result['width'];
            $height = $result['height'];
        }

        $size = is_file($absolutePath) ? (int) filesize($absolutePath) : (int) $file['size'];
        $id = $this->db->insert('media', [
            'uuid' => $uuid,
            'owner_user_id' => $ownerId,
            'kind' => $kind,
            'original_name' => (string) ($file['name'] ?? ''),
            'path' => $relativePath,
            'mime' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'is_demo' => 0,
            'created_at' => now_utc(),
        ]);

        return [
            'id' => $id,
            'uuid' => $uuid,
            'path' => $relativePath,
            'mime' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Delete a media row and its file.
     *
     * @param int $mediaId Media id.
     * @return void
     *
     * Side effects: removes the file and the media row.
     */
    public function delete(int $mediaId): void
    {
        $row = $this->db->selectOne('SELECT * FROM media WHERE id = :id', ['id' => $mediaId]);
        if ($row === null) { return; }
        $absolute = BASE_PATH . '/' . $row['path'];
        if (is_file($absolute)) { @unlink($absolute); }
        $this->db->delete('media', 'id = :id', ['id' => $mediaId]);
    }

    /**
     * Read a media row by id.
     *
     * @param int $mediaId Media id.
     * @return array|null
     */
    public function find(int $mediaId): ?array
    {
        return $this->db->selectOne('SELECT * FROM media WHERE id = :id', ['id' => $mediaId]);
    }

    /**
     * Fetch all media rows for a horse gallery (ordered).
     *
     * @param int $horseId Horse id.
     * @return array<int,array>
     */
    public function horseImages(int $horseId): array
    {
        return $this->db->select(
            'SELECT hi.id AS link_id, hi.sort_order, m.* FROM horse_images hi
             JOIN media m ON m.id = hi.media_id
             WHERE hi.horse_id = :h ORDER BY hi.sort_order ASC, hi.id ASC',
            ['h' => $horseId]
        );
    }

    /**
     * Count horse gallery images.
     *
     * @param int $horseId Horse id.
     * @return int
     */
    public function horseImageCount(int $horseId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM horse_images WHERE horse_id = :h', ['h' => $horseId]);
    }

    /**
     * Enforce the per-user storage quota.
     *
     * @param int $ownerId User id.
     * @param int $newBytes Additional bytes.
     * @return void
     * @throws ValidationException When the quota would be exceeded.
     */
    private function assertQuota(int $ownerId, int $newBytes): void
    {
        $quotaMb = (int) $this->settings->get('uploads.user_quota_mb', 500);
        if ($quotaMb <= 0) { return; }
        $used = (int) $this->db->scalar('SELECT COALESCE(SUM(size_bytes),0) FROM media WHERE owner_user_id = :u', ['u' => $ownerId]);
        if ($used + $newBytes > $quotaMb * 1024 * 1024) {
            throw new ValidationException('Storage quota exceeded', 'file', 'VALIDATION_FAILED');
        }
    }

    /**
     * Validate a $_FILES entry for basic upload errors.
     *
     * @param array $file $_FILES entry.
     * @return void
     * @throws ValidationException
     */
    private function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException('Upload failed (error ' . $error . ')', 'file', 'VALIDATION_FAILED');
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? '')) && !is_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ValidationException('Uploaded file is missing', 'file', 'VALIDATION_FAILED');
        }
    }

    /**
     * Sniff the MIME type of a file server-side.
     *
     * @param string $path File path.
     * @return string|null
     */
    private function sniffMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) { return null; }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) { return null; }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        if ($mime === 'image/svg') { $mime = 'image/svg+xml'; }
        return is_string($mime) ? $mime : null;
    }

    /**
     * Build a {yyyy}/{mm} folder suffix for the current date.
     *
     * @return string
     */
    private function dateFolders(): string
    {
        return gmdate('Y') . '/' . gmdate('m');
    }
}
