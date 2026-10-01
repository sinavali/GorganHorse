<?php
declare(strict_types=1);

/**
 * File: tests/E2E/HttpRoutesAndControllersTest.php
 * Purpose: End-to-End HTTP Route and Controller testing via Kernel process().
 *          The backend is a pure JSON API: every response is a JSON envelope.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/app/Support/Helpers.php';
require_once BASE_PATH . '/app/Bootstrap/App.php';
require_once BASE_PATH . '/app/Bootstrap/Database.php';
require_once BASE_PATH . '/app/Bootstrap/Bootstrap.php';

use App\Bootstrap\Bootstrap;
use App\Bootstrap\Request;

final class HttpRoutesAndControllersTest
{
    private static function makeRequest(string $method, string $path, array $post = [], array $headers = [], array $cookies = []): Request
    {
        return new Request($method, $path, [], $post, [], $headers, $cookies, ['REMOTE_ADDR' => '127.0.0.1']);
    }

    public static function run(): void
    {
        echo "Running E2E HTTP Routes & Controllers Tests...\n";

        $c = Bootstrap::container();

        // Ensure database is installed & seeded
        $installer = $c->get('installer');
        try {
            $installer->install('admin', '09121111111', 'admin12345', 'مدیر', 'سیستم');
        } catch (\Throwable) {}

        $demo = $c->get('demo');
        try {
            $demo->seed();
        } catch (\Throwable) {}

        $kernel = Bootstrap::kernel($c);

        // Helper: assert the response is a JSON envelope.
        $assertEnvelope = function (App\Bootstrap\Response $res): array {
            assert(str_contains((string) ($res->headers()['Content-Type'] ?? ''), 'application/json'));
            $json = json_decode($res->body(), true);
            assert(is_array($json));
            assert(array_key_exists('ok', $json));
            assert(array_key_exists('data', $json));
            return $json;
        };

        // 1. Guest Routes
        echo "  [1] Testing Guest Auth Routes...\n";
        $loginGet = self::makeRequest('GET', '/auth/login');
        $res = $kernel->process($loginGet);
        assert($res->status() === 200);
        $loginCfg = $assertEnvelope($res);
        assert(isset($loginCfg['data']['allow_signup']));
        assert(isset($loginCfg['data']['csrf']));

        $signupGet = self::makeRequest('GET', '/auth/signup');
        $res2 = $kernel->process($signupGet);
        assert($res2->status() === 200);
        $assertEnvelope($res2);

        // Captcha issue
        $guestCsrf = $c->get('guest_csrf');
        $captchaReq = self::makeRequest('POST', '/captcha/issue', [], ['accept' => 'application/json'], ['guest_csrf' => $guestCsrf]);
        $res3 = $kernel->process($captchaReq);
        assert($res3->status() === 200);
        $capData = json_decode($res3->body(), true);
        assert(isset($capData['data']['token']));

        // 2. Login & Session Cookie
        echo "  [2] Testing Login & Authenticated Session...\n";
        $loginPost = self::makeRequest('POST', '/auth/login', [
            'identifier' => 'admin',
            'password' => 'admin12345',
            '_csrf' => $guestCsrf,
        ], ['accept' => 'application/json'], ['guest_csrf' => $guestCsrf]);
        $resLogin = $kernel->process($loginPost);
        assert($resLogin->status() === 200);
        $loginJson = json_decode($resLogin->body(), true);
        assert(isset($loginJson['data']['redirect']));

        $db = $c->get('db');
        $sessRow = $db->selectOne('SELECT id FROM sessions WHERE user_id = 1 ORDER BY created_at DESC LIMIT 1');
        $sessId = $sessRow['id'] ?? '';

        // Helper to create authenticated JSON API requests
        $apiReq = function (string $method, string $path, array $data = []) use ($sessId, $kernel) {
            $req = self::makeRequest($method, $path, $data, [
                'accept' => 'application/json',
                'user-agent' => 'PHPUnit/Test',
            ], ['session_id' => $sessId]);
            return $kernel->process($req);
        };

        // 3. Panel JSON endpoints (former page routes now return envelopes)
        echo "  [3] Testing Panel JSON Endpoints...\n";
        $resDash = $apiReq('GET', '/panel');
        assert($resDash->status() === 200);
        $dashJson = json_decode($resDash->body(), true);
        assert(isset($dashJson['data']['role']) && isset($dashJson['data']['kpi']));

        $resUsers = $apiReq('GET', '/panel/users');
        assert($resUsers->status() === 200);
        assert(isset(json_decode($resUsers->body(), true)['data']['rows']));

        $resClubs = $apiReq('GET', '/panel/clubs');
        assert($resClubs->status() === 200);

        $resHorses = $apiReq('GET', '/panel/horses');
        assert($resHorses->status() === 200);

        $resComps = $apiReq('GET', '/panel/competitions');
        assert($resComps->status() === 200);

        $resRades = $apiReq('GET', '/panel/rades');
        assert($resRades->status() === 200);

        $resSignups = $apiReq('GET', '/panel/signups');
        assert($resSignups->status() === 200);

        $resPayments = $apiReq('GET', '/panel/payments');
        assert($resPayments->status() === 200);

        $resOrders = $apiReq('GET', '/panel/payment-orders');
        assert($resOrders->status() === 200);

        $resReports = $apiReq('GET', '/panel/reports');
        assert($resReports->status() === 200);

        $resNotifs = $apiReq('GET', '/panel/notifications');
        assert($resNotifs->status() === 200);

        $resMsgs = $apiReq('GET', '/panel/messages');
        assert($resMsgs->status() === 200);

        $resSettings = $apiReq('GET', '/panel/settings');
        assert($resSettings->status() === 200);

        $resAudit = $apiReq('GET', '/panel/audit');
        assert($resAudit->status() === 200);

        $resSmsLog = $apiReq('GET', '/panel/sms/log');
        assert($resSmsLog->status() === 200);

        $resBackups = $apiReq('GET', '/panel/backups');
        assert($resBackups->status() === 200);

        // Route-parameter endpoints. The Kernel calls every controller as
        // method($request, $ctx) and exposes {params} as request attributes,
        // so a controller declaring extra positional arguments would 500.
        $resLookups = $apiReq('GET', '/panel/lookups');
        assert($resLookups->status() === 200);
        $resLookupColors = $apiReq('GET', '/panel/lookups/colors');
        assert($resLookupColors->status() === 200);
        assert(is_array(json_decode($resLookupColors->body(), true)['data']));

        $resCultures = $apiReq('GET', '/panel/cultures');
        assert($resCultures->status() === 200);
        $culturesJson = json_decode($resCultures->body(), true)['data'];
        assert(isset($culturesJson['active']) && !empty($culturesJson['cultures']));

        // Installer pre-flight checklist (guest route behind the /install page).
        $resReq = $apiReq('GET', '/panel/requirements');
        assert($resReq->status() === 200);
        $reqJson = json_decode($resReq->body(), true)['data'];
        assert(array_key_exists('installed', $reqJson));
        assert(!empty($reqJson['requirements']));

        // 4. Panel Reports API
        echo "  [4] Testing Panel Reports API...\n";
        $repPost = $apiReq('POST', '/panel/reports/data', [
            'report' => 'signups',
            'page' => 1,
            'per_page' => 10,
        ]);
        assert($repPost->status() === 200);
        $repJson = json_decode($repPost->body(), true);
        assert(isset($repJson['data']['rows']));

        // 5. JSON error envelope (unknown route → 404 JSON)
        echo "  [5] Testing JSON Error Envelope...\n";
        $nf = $apiReq('GET', '/panel/definitely-not-a-route');
        assert($nf->status() === 404);
        $nfJson = json_decode($nf->body(), true);
        assert($nfJson['ok'] === false);
        assert(!empty($nfJson['errors']));

        echo "  E2E HTTP Routes & Controllers Tests Passed!\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    HttpRoutesAndControllersTest::run();
}
