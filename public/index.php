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

// The installer must be reachable even before the databases exist.
try {
    $container = Bootstrap::container();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bootstrap error: ' . $e->getMessage();
    return;
}

$request = Request::capture();
$kernel = Bootstrap::kernel($container);
$kernel->handle($request);
