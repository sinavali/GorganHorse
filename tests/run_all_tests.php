<?php
declare(strict_types=1);

/**
 * File: tests/run_all_tests.php
 * Purpose: Unified test runner executing all Unit, Integration, and E2E test suites.
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';

echo "====================================================\n";
echo "       Gorgan Horse Federation Panel Test Suite     \n";
echo "====================================================\n\n";

$start = microtime(true);
$suites = [
    BASE_PATH . '/tests/Unit/HelpersAndShimsTest.php' => 'HelpersAndShimsTest',
    BASE_PATH . '/tests/Unit/ModelsAndExceptionsTest.php' => 'ModelsAndExceptionsTest',
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
