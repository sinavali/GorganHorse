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
     /**
     * Upload an image used inside a rich-text field.
     *
     * Route:   POST /panel/media
     * Auth:    role:admin,manager
     * Body:    multipart/form-data with `file`
     * Returns: JSON envelope { data: { id, url } }
     *
     * Purpose: the content editor lets staff drag an image straight onto the
     * page. There is no per-entity endpoint that fits a description body, so
     * this stores the file and hands back the authenticated URL the editor
     * embeds. Images referenced from content are served through the same
     * authenticated `GET /media/{id}` route as every other upload.
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        $file = $request->files('file');
        if (!is_array($file)) { return $this->fail('VALIDATION_FAILED', 'No file uploaded', $ctx, 422, 'file'); }
        $stored = $this->c->get('media')->store($file, 'editor', (int) $ctx->actor()['id'], 'editor');
        if (!str_starts_with((string) $stored['mime'], 'image/')) {
            $this->c->get('media')->delete((int) $stored['id']);
            return $this->fail('VALIDATION_FAILED', 'Only images can be embedded in rich text', $ctx, 422, 'file');
        }
        return $this->ok([
            'id' => (int) $stored['id'],
            'url' => '/media/' . (int) $stored['id'],
            'width' => $stored['width'],
            'height' => $stored['height'],
        ], $ctx, 201);
    }

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

        $relative = ltrim((string) ($media['path'] ?? ''), '/');
        $base = realpath(BASE_PATH . '/uploads');
        $real = realpath(BASE_PATH . '/' . $relative);
        // Defence in depth: never serve anything outside the uploads directory.
        if ($base === false || $real === false || !str_starts_with($real, $base . '/') || !is_file($real)) {
            return $this->fail('NOT_FOUND', 'Media not found', $ctx, 404);
        }

        $mime = (string) ($media['mime'] ?? 'application/octet-stream');
        $name = (string) ($media['original_name'] ?? basename($real));
        $etag = '"' . substr(sha1((string) ($media['uuid'] ?? $real) . ':' . (string) filesize($real)), 0, 32) . '"';
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $name) . '"',
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
        ];
        // Conditional request: a browser that already has the bytes sends the
        // ETag back and gets a cheap 304 instead of a second download.
        if (trim((string) $request->header('if-none-match')) === $etag) {
            return new Response('', 304, $headers);
        }
        /* Response::download streams the file with readfile() and a
           Content-Length header, so the whole file (up to 25 MB) is never
           buffered in memory the way file_get_contents() did. */
        $response = Response::download($real, $name);
        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }
        return $response;
    }
}
