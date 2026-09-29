<?php
declare(strict_types=1);

/**
 * File: tests/E2E/HttpRoutesAndControllersTest.php
 * Purpose: End-to-End HTTP Route and Controller testing via Kernel process().
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

        // 1. Guest Routes
        echo "  [1] Testing Guest Auth Routes...\n";
        $loginGet = self::makeRequest('GET', '/auth/login');
        $res = $kernel->process($loginGet);
        assert($res->status() === 200);
        assert(str_contains($res->body(), 'ورود به پنل'));

        $signupGet = self::makeRequest('GET', '/auth/signup');
        $res2 = $kernel->process($signupGet);
        assert($res2->status() === 200);

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

        // Helper to create authenticated panel requests
        $panelReq = function (string $method, string $path, array $data = []) use ($sessId, $kernel) {
            $req = self::makeRequest($method, $path, $data, [
                'accept' => 'text/html,application/xhtml+xml,application/xml',
                'user-agent' => 'PHPUnit/Test',
            ], ['session_id' => $sessId]);
            return $kernel->process($req);
        };

        // Helper to create JSON panel API requests
        $apiReq = function (string $method, string $path, array $data = []) use ($sessId, $kernel) {
            $req = self::makeRequest($method, $path, $data, [
                'accept' => 'application/json',
                'user-agent' => 'PHPUnit/Test',
            ], ['session_id' => $sessId]);
            return $kernel->process($req);
        };

        // 3. Panel Dashboard & Navigation Pages
        echo "  [3] Testing Panel Dashboard & List Pages...\n";
        $resDash = $panelReq('GET', '/panel');
        assert($resDash->status() === 200);

        $resUsers = $panelReq('GET', '/panel/users');
        assert($resUsers->status() === 200);

        $resClubs = $panelReq('GET', '/panel/clubs');
        assert($resClubs->status() === 200);

        $resHorses = $panelReq('GET', '/panel/horses');
        assert($resHorses->status() === 200);

        $resComps = $panelReq('GET', '/panel/competitions');
        assert($resComps->status() === 200);

        $resRades = $panelReq('GET', '/panel/rades');
        assert($resRades->status() === 200);

        $resSignups = $panelReq('GET', '/panel/signups');
        assert($resSignups->status() === 200);

        $resPayments = $panelReq('GET', '/panel/payments');
        assert($resPayments->status() === 200);

        $resOrders = $panelReq('GET', '/panel/payment-orders');
        assert($resOrders->status() === 200);

        $resReports = $panelReq('GET', '/panel/reports');
        assert($resReports->status() === 200);

        $resNotifs = $panelReq('GET', '/panel/notifications');
        assert($resNotifs->status() === 200);

        $resMsgs = $panelReq('GET', '/panel/messages');
        assert($resMsgs->status() === 200);

        $resSettings = $panelReq('GET', '/panel/settings');
        assert($resSettings->status() === 200);

        $resAudit = $panelReq('GET', '/panel/audit');
        assert($resAudit->status() === 200);

        $resSmsLog = $panelReq('GET', '/panel/sms-log');
        assert($resSmsLog->status() === 200);

        $resBackups = $panelReq('GET', '/panel/backups');
        assert($resBackups->status() === 200);

        // 4. Panel APIs & Reports Execution
        echo "  [4] Testing Panel Reports API...\n";
        $repPost = $apiReq('POST', '/panel/reports/run', [
            'report' => 'signups',
            'page' => 1,
            'per_page' => 10,
        ]);
        assert($repPost->status() === 200);
        $repJson = json_decode($repPost->body(), true);
        assert(isset($repJson['data']['rows']));

        // 5. Printable Entity Views
        echo "  [5] Testing Print Views...\n";
        $compRow = $db->selectOne('SELECT id FROM competitions LIMIT 1');
        if ($compRow) {
            $printComp = $panelReq('GET', '/print/competition/' . $compRow['id']);
            assert($printComp->status() === 200);
            $printSheet = $panelReq('GET', '/print/signup-sheet/' . $compRow['id']);
            assert($printSheet->status() === 200);
            $printStandings = $panelReq('GET', '/print/standings/' . $compRow['id']);
            assert($printStandings->status() === 200);
        }

        echo "  E2E HTTP Routes & Controllers Tests Passed!\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    HttpRoutesAndControllersTest::run();
}
