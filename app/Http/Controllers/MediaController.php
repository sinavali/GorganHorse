<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/MediaController.php
 *
 * Purpose:
 *   Stream uploaded media (avatars, horse/club galleries, documents) to
 *   authenticated clients. Uploads live outside the public web root, so they
 *   are never served directly by the web server; this controller is the single
 *   authenticated access path (Blueprint §19, Technical §21).
 *
 *   No path leakage: callers reference a media id, the service resolves the
 *   stored path, and the file is streamed with its sniffed MIME type.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: MediaController
 * Purpose: Serve stored media files.
 */
final class MediaController extends BaseController
{
    /**
     * Stream a media file by id.
     *
     * Route:   GET /media/{id}
     * Auth:    auth (any role)
     * Returns: raw file with its MIME type
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $media = $this->c->get('media')->find((int) $request->attr('id'));
        if ($media === null) {
            return $this->fail('NOT_FOUND', 'Media not found', $ctx, 404);
        }

        // Access control: admins, managers, or media owner; or public assets
        $isStaff = $ctx->hasRole('admin', 'super_admin', 'manager');
        $isOwner = ((int) ($media['owner_user_id'] ?? 0) === (int) ($ctx->user['id'] ?? 0));
        $isPublic = in_array($media['kind'] ?? '', ['horse_avatar', 'competition_banner', 'club_logo', 'news_banner'], true);
        if (!$isStaff && !$isOwner && !$isPublic) {
            return $this->fail('FORBIDDEN', 'Access denied to this media file', $ctx, 403);
        }

        $relative = ltrim((string) ($media['path'] ?? ''), '/\\');
        $base = realpath(BASE_PATH . '/uploads');
        $real = realpath(BASE_PATH . '/' . $relative);
        $baseNorm = rtrim(str_replace('\\', '/', (string) $base), '/') . '/';
        $realNorm = str_replace('\\', '/', (string) $real);

        // Defence in depth: never serve anything outside the uploads directory.
        if ($base === false || $real === false || !str_starts_with($realNorm, $baseNorm) || !is_file($real)) {
            return $this->fail('NOT_FOUND', 'Media not found', $ctx, 404);
        }

        $mime = (string) ($media['mime'] ?? 'application/octet-stream');
        $name = (string) ($media['original_name'] ?? basename($real));
        $response = new Response(
            (string) file_get_contents($real),
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $name) . '"',
                'Cache-Control' => 'private, max-age=86400',
            ]
        );
        return $response;
    }
}
