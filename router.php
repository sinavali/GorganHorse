<?php
declare(strict_types=1);

/**
 * File: router.php
 *
 * Purpose:
 *   Development router for PHP's built-in web server (`php -S 0.0.0.0:PORT router.php`).
 *   This file is NOT part of production deployment — the production web server (nginx
 *   / Apache) points the document root at public/ and routes all non-static paths to
 *   public/index.php directly.
 *
 *   Behavior:
 *   - Requests for files that exist under public/ (e.g. /views/assets/js/app.js) are
 *     read from disk and emitted explicitly (the built-in server's docroot is the
 *     workspace root, not public/, so `return false` cannot be relied upon).
 *   - All other paths (/, /payment/success, /panel/..., /auth/...) are handled by
 *     public/index.php, the single front controller.
 *
 * @package Dev
 */

/** @var string $uri Path (+ query) requested; PHP CLI server sets this. */
$uri = urldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

/** Common static MIME types used by the SPA shell and its assets. */
$mimes = [
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'html' => 'text/html; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'json' => 'application/json; charset=UTF-8',
    'map' => 'application/json; charset=UTF-8',
];

/** Serve an existing file under public/ with its MIME type. */
if ($uri !== '/') {
    $file = __DIR__ . '/public' . $uri;
    if (is_file($file)) {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($file));
        // Dev-only: always revalidate so the preview never serves stale JS/CSS.
        header('Cache-Control: no-cache');
        readfile($file);
        return true;
    }
}

/** Everything else goes through the front controller. */
require __DIR__ . '/public/index.php';
