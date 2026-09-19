<?php
declare(strict_types=1);

/**
 * File: app/Support/vendor-shims/ImageProcessor.php
 *
 * Purpose:
 *   GD-backed image resize/compress helper, standing in for intervention/image.
 *   Images are downscaled to a maximum dimension and re-encoded at a configured
 *   quality. Falls back to a straight copy when GD cannot decode the source.
 *
 * Used by:
 *   - MediaService
 *
 * @package App\Support\Vendor
 */

namespace App\Support\Vendor;

/**
 * Class: ImageProcessor
 *
 * Purpose: Resize and compress raster images using GD.
 */
final class ImageProcessor
{
    /**
     * Process an image in place: downscale to $maxDimension and re-encode.
     *
     * @param string $path         Absolute source path.
     * @param string $mime         Source MIME type.
     * @param int    $maxDimension Maximum width or height in pixels.
     * @param int    $quality      Encoding quality (0-100).
     * @param string $format       "original" or "webp".
     * @return array{path:string,mime:string,width:int,height:int} Result metadata.
     */
    public static function process(string $path, string $mime, int $maxDimension = 2560, int $quality = 82, string $format = 'original'): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            $size = @getimagesize($path) ?: [0, 0];
            return ['path' => $path, 'mime' => $mime, 'width' => (int) $size[0], 'height' => (int) $size[1]];
        }
        $source = self::create($path, $mime);
        if ($source === null) {
            $size = @getimagesize($path) ?: [0, 0];
            return ['path' => $path, 'mime' => $mime, 'width' => (int) $size[0], 'height' => (int) $size[1]];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = 1.0;
        if ($maxDimension > 0 && max($width, $height) > $maxDimension) {
            $scale = $maxDimension / (float) max($width, $height);
        }
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $targetMime = $mime;
        $targetPath = $path;
        if ($format === 'webp' && function_exists('imagewebp')) {
            $targetMime = 'image/webp';
            $targetPath = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $path) ?? ($path . '.webp');
        }

        self::save($canvas, $targetPath, $targetMime, $quality);
        imagedestroy($canvas);
        imagedestroy($source);

        return ['path' => $targetPath, 'mime' => $targetMime, 'width' => $newWidth, 'height' => $newHeight];
    }

    /**
     * Create a GD image resource from a file.
     *
     * @param string $path Absolute path.
     * @param string $mime MIME type.
     * @return \GdImage|null
     */
    private static function create(string $path, string $mime): ?\GdImage
    {
        try {
            $image = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/gif' => @imagecreatefromgif($path),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
                default => null,
            };
            return $image instanceof \GdImage ? $image : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Persist a GD image to disk in the target format.
     *
     * @param \GdImage $image  Image resource.
     * @param string   $path   Destination path.
     * @param string   $mime   Target MIME.
     * @param int      $quality Encoding quality.
     * @return void
     */
    private static function save(\GdImage $image, string $path, string $mime, int $quality): void
    {
        match ($mime) {
            'image/png' => imagepng($image, $path, (int) round((100 - $quality) / 10)),
            'image/gif' => imagegif($image, $path),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, $quality) : imagejpeg($image, $path, $quality),
            default => imagejpeg($image, $path, $quality),
        };
    }

    /**
     * Generate a square thumbnail (used by the thumbs cache namespace).
     *
     * @param string $path Source path.
     * @param string $mime Source MIME.
     * @param int    $size Thumbnail edge size in pixels.
     * @return \GdImage|null
     */
    public static function thumbnail(string $path, string $mime, int $size = 200): ?\GdImage
    {
        $source = self::create($path, $mime);
        if ($source === null) { return null; }
        $width = imagesx($source);
        $height = imagesy($source);
        $edge = min($width, $height);
        $srcX = (int) (($width - $edge) / 2);
        $srcY = (int) (($height - $edge) / 2);
        $thumb = imagecreatetruecolor($size, $size);
        imagecopyresampled($thumb, $source, 0, 0, $srcX, $srcY, $size, $size, $edge, $edge);
        imagedestroy($source);
        return $thumb;
    }
}
