<?php
declare(strict_types=1);

/**
 * File: vendor/autoload.php
 *
 * Purpose:
 *   Custom PSR-4-ish autoloader for the Gorgan Horse Federation Panel.
 *   There is no Composer. This file maps namespaces to folders and includes
 *   vendored third-party libraries bundled as plain files under vendor/.
 *
 *   Because the project merges multiple classes into single files (Principle
 *   P23), the autoloader first tries the PSR-4 path and, when the file is
 *   absent, falls back to the merged-file location (e.g. App\Exceptions\*
 *   resolves to app/Exceptions/Exceptions.php).
 *
 * Mapping:
 *   - "App\"            -> /app
 *   - "Morilog\Jalali\" -> /vendor/morilog/jalali/src
 *   - "libphonenumber\" -> /vendor/giggsey/libphonenumber-for-php/src
 *
 * Behaviour:
 *   - Registers an spl_autoload_register callback.
 *   - Loads lightweight, self-written vendored shims listed below.
 *   - Never throws; unmatched classes simply fall through so PHP can report
 *     a clear "class not found" error.
 *
 * Side effects:
 *   - Registers an autoloader with the SPL stack.
 *
 * @package Vendor
 */

if (defined('GORGAN_AUTOLOADER_LOADED')) {
    return;
}
define('GORGAN_AUTOLOADER_LOADED', true);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Register the App\ namespace root and vendor library roots.
 *
 * @return void
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\'            => BASE_PATH . '/app',
        'Morilog\\Jalali\\' => BASE_PATH . '/vendor/morilog/jalali/src',
        'libphonenumber\\' => BASE_PATH . '/vendor/giggsey/libphonenumber-for-php/src',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relative = substr($class, $len);
        $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
        // Matched a prefix but the PSR-4 file is absent; fall through to the
        // merged-file fallbacks below.
        break;
    }

    // Merged-file fallbacks (P23). Each entry maps a class-name prefix to the
    // single file that contains the merged classes for that namespace.
    $merged = [
        'App\\Exceptions\\'            => BASE_PATH . '/app/Exceptions/Exceptions.php',
        'App\\Models\\'                => BASE_PATH . '/app/Models/Models.php',
        'App\\Services\\Report\\'      => BASE_PATH . '/app/Services/Report/Reports.php',
        'App\\Http\\MiddlewareContext' => BASE_PATH . '/app/Http/Middleware.php',
        'App\\Http\\Middleware'        => BASE_PATH . '/app/Http/Middleware.php',
    ];

    foreach ($merged as $prefix => $file) {
        if (strncmp($prefix, $class, strlen($prefix)) === 0 && is_file($file)) {
            require $file;
            return;
        }
    }
});

/**
 * Lightweight bundled shims.
 *
 * These are minimal, dependency-free implementations of the vendored
 * libraries (jalali conversion, Iranian phone/national-id validation,
 * a basic HTML sanitizer, a GD-backed image helper, and a tiny Markdown
 * renderer). They live under app/Support/vendor-shims/ and are always
 * loaded so that services can rely on them without a package manager.
 */
$shimDir = BASE_PATH . '/app/Support/vendor-shims';
if (is_dir($shimDir)) {
    foreach ([
        'Jalali.php',
        'PhoneValidator.php',
        'HtmlSanitizer.php',
        'ImageProcessor.php',
        'Markdown.php',
    ] as $shim) {
        $path = $shimDir . '/' . $shim;
        if (is_file($path)) {
            require_once $path;
        }
    }
}