<?php
declare(strict_types=1);

/**
 * File: tests/Integration/ServicesTest.php
 * Purpose: Exhaustive unit and integration tests for all domain services.
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
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Support\Vendor\PhoneValidator;

final class ServicesTest
{
    private static function validNationalId(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $digits = (string) random_int(100000000, 999999999);
            $sum = 0;
            for ($j = 0; $j < 9; $j++) { $sum += ((int) $digits[$j]) * (10 - $j); }
            $rem = $sum % 11;
            $check = $rem < 2 ? $rem : 11 - $rem;
            $cand = $digits . $check;
            if (PhoneValidator::isValidNationalId($cand)) { return $cand; }
        }
        return '0010350829';
    }

    public static function run(): void
    {
        echo "Running Services Integration & Unit Tests...\n";

        $c = Bootstrap::container();

        // 1. InstallerService & DemoSeeder
        $installer = $c->get('installer');
        $inst = $installer->install('admin', '09121111111', 'admin12345', 'مدیر', 'اصلی');
        assert(isset($inst['installed']));

        $demo = $c->get('demo');
        $demoRes = $demo->seed();
        assert(is_array($demoRes));

        $actorAdmin = ['id' => 1, 'role' => 'admin', 'username' => 'admin'];
        $actorManager = ['id' => 2, 'role' => 'manager', 'username' => 'manager'];

        // 2. SettingService & CultureService & CacheService
        $settings = $c->get('settings');
        $settings->set('test.k', 'v');
        assert($settings->get('test.k') === 'v');
        $allSet = $settings->all();
        assert(is_array($allSet));

        $culture = $c->get('culture');
        assert(!empty($culture->translate('AUTH_INVALID', 'Invalid')));

        $cache = $c->get('cache');
        $cache->put('test_ns', 'key1', 'val1', 60);
        assert($cache->get('test_ns', 'key1', 60, fn() => 'fresh') === 'val1');
        $cache->forget('test_ns', 'key1');

        // 3. CaptchaService
        $captcha = $c->get('captcha');
        $cap = $captcha->issue('test_sess_id');
        assert(isset($cap['token'], $cap['code']));

        // 4. SmsService & LogService
        $sms = $c->get('sms');
        $log = $c->get('log');
        $log->app('info', 'Test log message');
        $log->audit(['action' => 'test.action', 'target_type' => 'test', 'target_id' => 1]);
        $smsLogs = $log->smsLogList();
        assert(is_array($smsLogs));

        // 5. AuthService
        $auth = $c->get('auth');
        $userRow = $auth->login('admin', 'admin12345', '127.0.0.1', 'TestAgent');
        assert($userRow['username'] === 'admin');

        $regPhone = '0912' . random_digits(7);
        $newRiderAcc = $auth->signup([
            'first_name' => 'تست',
            'last_name' => 'سوارکار',
            'phone' => $regPhone,
            'password' => 'rider12345',
            'password_confirm' => 'rider12345',
            'national_id' => self::validNationalId(),
        ]);
        assert($newRiderAcc['user_id'] > 0);

        // 6. UserService
        $users = $c->get('users');
        $uList = $users->list([], $actorAdmin);
        assert($uList['total'] > 0);

        $riderUser = $users->get($newRiderAcc['user_id']);
        assert($riderUser['id'] === $newRiderAcc['user_id']);

        $users->update($riderUser['id'], ['first_name' => 'علی_آپدیت'], $actorAdmin);
        $users->verify($riderUser['id'], $actorAdmin);
        $users->setDisableState($riderUser['id'], 'limited', 'تست محدودیت', $actorAdmin);
        $users->setDisableState($riderUser['id'], 'none', 'رفع محدودیت', $actorAdmin);

        $actorRider = ['id' => $riderUser['id'], 'role' => 'rider', 'username' => $riderUser['username']];

        // 7. ClubService
        $clubs = $c->get('clubs');
        $cList = $clubs->list([], $actorAdmin);
        assert($cList['total'] > 0);
        $firstClub = $cList['rows'][0];

        $affRiders = $clubs->affiliatedRiders($firstClub['id']);
        assert(is_array($affRiders));

        // 8. HorseService
        $horses = $c->get('horses');
        $hList = $horses->list([], $actorRider);
        assert(is_array($hList['rows']));

        $newHorse = $horses->create([
            'owner_user_id' => $riderUser['id'],
            'name' => 'اسب تست جدید',
            'microchip_number' => random_digits(15),
            'gender' => 'نری',
            'race' => 'ترکمن',
        ], $actorRider);
        assert($newHorse['id'] > 0);

        // 9. RadeService
        $rades = $c->get('rades');
        $rList = $rades->list();
        assert(!empty($rList));
        $firstRade = $rList[0];

        // 10. PaymentService (Templates & Orders)
        $payments = $c->get('payments');
        $pList = $payments->listTemplates();
        assert(!empty($pList));

        // 11. CompetitionService
        $competitions = $c->get('competitions');
        $newComp = $competitions->create([
            'title' => 'مسابقه جام هیرکان',
            'venue_club_id' => $firstClub['id'],
            'city' => 'گرگان',
            'start_registration_at' => now_utc(),
            'end_registration_at' => utc_iso(time() + 86400 * 5),
            'start_at' => utc_iso(time() + 86400 * 6),
            'end_at' => utc_iso(time() + 86400 * 7),
        ], $actorAdmin);
        assert($newComp['id'] > 0);

        $compRade = $competitions->addRade($newComp['id'], [
            'rade_id' => $firstRade['id'],
            'payment_id' => $pList[0]['id'],
            'capacity' => 30,
        ], $actorAdmin);
        assert($compRade['id'] > 0);

        $competitions->update($newComp['id'], ['status' => 'open'], $actorAdmin);

        // 12. SignupService
        $signups = $c->get('signups');
        $suRes = $signups->create(
            (int) $compRade['id'],
            (int) $newHorse['id'],
            (int) $firstClub['id'],
            $actorRider
        );
        assert($suRes['signup_id'] > 0);

        $signups->markPaid($suRes['signup_id']);
        $signups->confirm($suRes['signup_id'], $actorAdmin);

        // 13. ResultService
        $results = $c->get('results');
        $results->saveDraft($newComp['id'], [
            'rades' => [
                [
                    'comp_rade_id' => $compRade['id'],
                    'signups' => [
                        ['id' => $suRes['signup_id'], 'position' => 1, 'is_winner' => 1, 'result_notes' => 'مقام اول']
                    ]
                ]
            ]
        ], $actorAdmin);

        $results->confirm($newComp['id'], $actorAdmin);
        $results->publish($newComp['id'], $actorAdmin);

        $standings = $results->standings(['rider_user_id' => $riderUser['id']]);
        assert(!empty($standings));

        // 14. NotificationService
        $notifications = $c->get('notifications');
        $nList = $notifications->list($riderUser['id']);
        assert(is_array($nList));

        // 15. BanService
        $bans = $c->get('bans');
        $banRes = $bans->create([
            'target_type' => 'rider',
            'target_id' => $riderUser['id'],
            'scope' => 'global',
            'reason' => 'تست تعلیق'
        ], $actorAdmin);
        assert($banRes['id'] > 0);

        $bans->remove($banRes['id'], $actorAdmin);

        // 16. ReportEngine & KpiService
        $reports = $c->get('reports');
        $repRes = $reports->run(['report' => 'signups'], $actorAdmin);
        assert(isset($repRes['rows']));

        $kpi = $c->get('kpi');
        $staffDash = $kpi->staffDashboard($actorAdmin);
        assert(isset($staffDash['total_riders']));

        // 17. BackupService
        $backup = $c->get('backup');
        $bRes = $backup->create('unit-test', $actorAdmin['id']);
        assert(!empty($bRes['name']));
        $backup->delete($bRes['name']);

        echo "  Services Integration & Unit Tests Passed!\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    ServicesTest::run();
}
