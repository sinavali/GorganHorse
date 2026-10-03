<?php
declare(strict_types=1);

/**
 * File: tests/Unit/FrontendContractTest.php
 *
 * Purpose:
 *   Guards the contracts between the PHP kernel and the self-contained SPA in
 *   public/views/. These are the failure modes that break whole pages at
 *   runtime rather than failing one assertion:
 *
 *   1. Controller arity. The Kernel invokes every controller as
 *      method($request, $ctx) and exposes matched {params} as request
 *      attributes. A controller declaring a third required parameter throws
 *      "Too few arguments" and 500s every route that uses it.
 *   2. Picker markup. UI.initPicks() reads hidden.value on each [data-spk]
 *      block; a block missing its hidden input throws "Cannot read properties
 *      of null (reading 'value')" and blanks the page. This is verified by
 *      RUNNING the real helpers (tests/Frontend/filter-bar.test.js), not by
 *      grepping the source.
 *   3. Route/API-prefix parity. public/index.php decides which paths return
 *      the SPA shell and which go to the JSON kernel; app.js mirrors that list
 *      for its internal-link handler. The installer must stay an SPA page
 *      rather than a JSON endpoint.
 *   4. JS syntax. Every SPA script is parsed with `node --check`.
 *
 * Note on assertions: this file deliberately uses explicit exceptions rather
 * than assert(). The project's php.ini runs with zend.assertions=-1, which
 * compiles assert() away entirely, so an assert()-based check here would
 * silently pass no matter what the code does.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/app/Support/Helpers.php';
require_once BASE_PATH . '/app/Bootstrap/App.php';
require_once BASE_PATH . '/app/Bootstrap/Database.php';
require_once BASE_PATH . '/app/Bootstrap/Bootstrap.php';

final class FrontendContractTest
{
    /** @return void */
    public static function run(): void
    {
        echo "Running Frontend Contract Tests...\n";

        $node = self::findNode();
        if ($node === null) {
            echo "  (node not available, skipping the JS contracts)\n";
            return;
        }

        self::controllerArity();
        self::spaRouteParity();
        self::runNode($node, 'tests/Frontend/filter-bar.test.js', 'filter-bar contract');
        self::runNode($node, 'tests/Frontend/picker-contract.test.js', 'picker contract');
        self::runNode($node, 'tests/Frontend/router-contract.test.js', 'router contract');
        self::runNode($node, 'tests/Frontend/inbox-contract.test.js', 'inbox contract');
        self::runNode($node, 'tests/Frontend/spa-contract.test.js', 'SPA contract');
        self::jsSyntax($node);

        echo "  Frontend Contract Tests Passed!\n";
    }

    /**
     * Fail loudly. Never use assert(): it is disabled in this project's php.ini.
     *
     * @param bool   $ok      Condition that must hold.
     * @param string $message Failure detail.
     * @return void
     */
    private static function require(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Locate the node binary, if this host has one.
     *
     * @return string|null
     */
    private static function findNode(): ?string
    {
        $out = [];
        $code = 0;
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'where.exe node 2>nul' : 'command -v node 2>/dev/null';
        @exec($cmd, $out, $code);
        $path = trim((string) ($out[0] ?? ''));
        return ($code === 0 && $path !== '') ? $path : null;
    }

    /**
     * Run one Node test script and surface its output on failure.
     *
     * @param string $node   Node binary path.
     * @param string $script Repo-relative script path.
     * @param string $label  Human label for the failure message.
     * @return void
     */
    private static function runNode(string $node, string $script, string $label): void
    {
        $path = BASE_PATH . '/' . $script;
        self::require(is_file($path), $label . ': missing ' . $script);
        $out = [];
        $code = 0;
        @exec(escapeshellarg($node) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        self::require(
            $code === 0,
            $label . ' failed (exit ' . $code . '): ' . trim(implode("\n", $out))
        );
        echo '  ' . trim(implode(' ', $out)) . "\n";
    }

    /**
     * Every public controller method must be callable as method($request, $ctx).
     *
     * @return void
     */
    private static function controllerArity(): void
    {
        $violations = [];
        foreach (glob(BASE_PATH . '/app/Http/Controllers/*.php') ?: [] as $file) {
            $class = 'App\\Http\\Controllers\\' . basename((string) $file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $rc = new ReflectionClass($class);
            foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getNumberOfRequiredParameters() <= 2) {
                    continue;
                }
                $violations[] = $class . '::' . $method->getName()
                    . '() requires ' . $method->getNumberOfRequiredParameters()
                    . ' args; the Kernel only passes ($request, $ctx) — read route params via $request->attr()';
            }
        }
        self::require(
            $violations === [],
            'Controller arity violations (these 500 at runtime): ' . implode(' | ', $violations)
        );
    }

    /**
     * The install wizard is an SPA page; its JSON endpoints stay under the API.
     *
     * @return void
     */
    private static function spaRouteParity(): void
    {
        $routes = (string) file_get_contents(BASE_PATH . '/app/Http/routes.php');
        self::require(
            !preg_match("/\['GET',\s*'\/install'/", $routes),
            'GET /install must not be a JSON route; the installer is an SPA page served by public/index.php'
        );
        self::require(
            (bool) preg_match("/\['POST',\s*'\/install\/run'/", $routes),
            'POST /install/run route is missing'
        );
        self::require(
            (bool) preg_match("/\['GET',\s*'\/panel\/requirements'/", $routes),
            'GET /panel/requirements route is missing (the wizard needs its checklist)'
        );

        $front = (string) file_get_contents(BASE_PATH . '/public/index.php');
        self::require(
            strpos($front, "'/install',") !== false,
            'public/index.php must list /install as an SPA path so it serves the shell'
        );
        self::require(
            !preg_match('/\$apiPrefixes\s*=\s*\[[^\]]*\'\/install\'/', $front),
            'public/index.php must not treat /install as an API prefix, or the wizard 404s as JSON'
        );

        $app = (string) file_get_contents(BASE_PATH . '/public/views/assets/js/app.js');
        self::require(
            (bool) preg_match('/var API_PREFIXES=\[(.*?)\]/s', $app, $m),
            'API_PREFIXES not found in app.js'
        );
        self::require(
            strpos($m[1], "'/install'") === false,
            "app.js API_PREFIXES must not contain '/install': the installer is a page, not an API call"
        );
        self::require(
            (bool) preg_match('/API\.get\(\'\/panel\/requirements\'\)/', $app),
            'app.js must read install state from /panel/requirements'
        );
    }

    /**
     * Parse every SPA script with Node.
     *
     * @param string $node Node binary path.
     * @return void
     */
    private static function jsSyntax(string $node): void
    {
        $files = glob(BASE_PATH . '/public/views/assets/js/*.js') ?: [];
        self::require($files !== [], 'No SPA scripts found under public/views/assets/js');
        foreach ($files as $file) {
            $out = [];
            $code = 0;
            @exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::require(
                $code === 0,
                'JS syntax error in ' . basename($file) . ': ' . trim(implode(' ', $out))
            );
        }
        echo '  node --check clean on ' . count($files) . " scripts\n";
    }
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    FrontendContractTest::run();
}