<?php
declare(strict_types=1);

/**
 * File: public/index.php
 *
 * Purpose:
 *   The single front controller for the Gorgan Horse Federation Panel. Defines
 *   BASE_PATH, loads the autoloader and helpers, boots the container and kernel,
 *   and dispatches the incoming request (Technical §4.1, Blueprint §2 P15).
 *
 *   The panel UI is a self-contained single-page app under public/views. The
 *   browser entry points (/) are served here, after the container has booted,
 *   so the guest CSRF cookie is established before the SPA issues any request.
 *   Everything else is JSON API handled by the kernel.
 *
 * Side effects:
 *   - Emits the HTTP response.
 *
 * @package Public
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Support/Helpers.php';
require BASE_PATH . '/app/Bootstrap/App.php';
require BASE_PATH . '/app/Bootstrap/Database.php';
require BASE_PATH . '/app/Bootstrap/Bootstrap.php';

use App\Bootstrap\Bootstrap;
use App\Bootstrap\Request;

/**
 * Browser entry points that render the SPA shell instead of JSON.
 *
 * The client uses clean URLs (History API), so any GET that is not an API
 * route, a public share page, or a real static file must return the shell —
 * otherwise a hard refresh or a shared link like /users/65 would 404.
 * Requests that start with an API prefix keep going to the Kernel.
 */
$spaPaths = ['/', '/index.html', '/install', '/payment/success', '/payment/failed'];
$apiPrefixes = ['/api/', '/auth/', '/panel/', '/media/', '/captcha/', '/c/', '/payment/callback'];
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$spaFile = (__DIR__ . '/views/index.html');
$isApiRequest = false;
foreach ($apiPrefixes as $prefix) {
    if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) { $isApiRequest = true; break; }
}
$isStaticFile = $path !== '/' && is_file(__DIR__ . $path);
$serveSpa = ($method === 'GET' && !$isApiRequest && !$isStaticFile && is_file($spaFile));

/** Emit the SPA shell. */
$emitSpa = static function () use ($spaFile): void {
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache');
    readfile($spaFile);
};

// The installer must be reachable even before the databases exist.
try {
    $container = Bootstrap::container();
} catch (Throwable $e) {
    if ($serveSpa) {
        $emitSpa();
        return;
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bootstrap error: ' . $e->getMessage();
    return;
}

if ($serveSpa) {
    $emitSpa();
    return;
}

$request = Request::capture();
$kernel = Bootstrap::kernel($container);
$kernel->handle($request);
