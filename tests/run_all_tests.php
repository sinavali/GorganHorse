<?php
declare(strict_types=1);

/**
 * File: tests/run_all_tests.php
 * Purpose: Unified test runner executing all Unit, Integration, and E2E test suites.
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';

/**
 * Isolate test state from runtime data.
 *
 * The suites boot the real container (which installs and seeds the app), so
 * they must never touch database/app.sqlite, database/logs.sqlite, cache/, or
 * logs/ used by the live panel. The GORGAN_* overrides (supported by
 * Bootstrap.php) redirect every suite to a throwaway directory that is wiped
 * before each run. The install credentials used by the suites live in the
 * suite files; the runtime installer is untouched.
 */
$testStateDir = BASE_PATH . '/tests/_state';
if (is_dir($testStateDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testStateDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
    }
    @rmdir($testStateDir);
}
foreach ([
    'GORGAN_DB_FILE'      => $testStateDir . '/app.sqlite',
    'GORGAN_LOGS_DB_FILE' => $testStateDir . '/logs.sqlite',
    'GORGAN_CACHE_DIR'    => $testStateDir . '/cache',
    'GORGAN_LOGS_DIR'     => $testStateDir . '/logs',
] as $name => $value) {
    putenv("$name=$value");
}
@mkdir($testStateDir . '/cache', 0775, true);
@mkdir($testStateDir . '/logs', 0775, true);

echo "====================================================\n";
echo "       Gorgan Horse Federation Panel Test Suite     \n";
echo "====================================================\n\n";

$start = microtime(true);
$suites = [
    BASE_PATH . '/tests/Unit/HelpersAndShimsTest.php' => 'HelpersAndShimsTest',
    BASE_PATH . '/tests/Unit/ModelsAndExceptionsTest.php' => 'ModelsAndExceptionsTest',
    BASE_PATH . '/tests/Unit/FrontendContractTest.php' => 'FrontendContractTest',
    BASE_PATH . '/tests/Integration/ServicesTest.php' => 'ServicesTest',
    BASE_PATH . '/tests/E2E/HttpRoutesAndControllersTest.php' => 'HttpRoutesAndControllersTest',
];

$passed = 0;
$failed = 0;

foreach ($suites as $file => $class) {
    if (is_file($file)) {
        require_once $file;
        try {
            $class::run();
            $passed++;
        } catch (\Throwable $e) {
            echo "FAILED [{$class}]: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $failed++;
        }
    } else {
        echo "MISSING: {$file}\n";
        $failed++;
    }
}

$elapsed = round(microtime(true) - $start, 3);
echo "\n====================================================\n";
echo "SUMMARY: Passed {$passed}/" . count($suites) . " test suites in {$elapsed}s\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
