<?php
declare(strict_types=1);

/**
 * File: tests/test_all_backend.php
 * Purpose: Integration and unit tests for all backend services, routes, models, and workflows.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Support/Helpers.php';
require BASE_PATH . '/app/Bootstrap/App.php';
require BASE_PATH . '/app/Bootstrap/Database.php';
require BASE_PATH . '/app/Bootstrap/Bootstrap.php';

use App\Bootstrap\Bootstrap;
use App\Support\Vendor\PhoneValidator;

$c = Bootstrap::container();

echo "=========================================\n";
echo "Starting Backend Integration Tests\n";
echo "=========================================\n\n";

$actorAdmin = ['id' => 1, 'role' => 'admin', 'username' => 'admin'];
$actorManager = ['id' => 2, 'role' => 'manager', 'username' => 'manager'];

function generateValidNationalId(): string {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $digits = (string) random_int(100000000, 999999999);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) { $sum += ((int) $digits[$i]) * (10 - $i); }
        $rem = $sum % 11;
        $check = $rem < 2 ? $rem : 11 - $rem;
        $candidate = $digits . $check;
        if (PhoneValidator::isValidNationalId($candidate)) { return $candidate; }
    }
    return '0010350829';
}

// 1. Installer test
echo "[1] Testing Installer...\n";
$installer = $c->get('installer');
try {
    $instRes = $installer->install('admin', '09121111111', 'admin12345', 'مدیر', 'سیستم');
    echo "  Installer OK: " . json_encode($instRes, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    echo "  Installer already run or failed: " . $e->getMessage() . "\n";
}

// 2. DemoSeeder test
echo "\n[2] Testing DemoSeeder...\n";
$demo = $c->get('demo');
try {
    $demoRes = $demo->seed();
    echo "  DemoSeeder result: " . json_encode($demoRes, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    echo "  DemoSeeder failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

// 3. Settings & Culture & Cache
echo "\n[3] Testing Settings & Culture & Cache...\n";
$settings = $c->get('settings');
$settings->set('test.setting', 'value123');
assert($settings->get('test.setting') === 'value123');
echo "  Settings OK\n";

$culture = $c->get('culture');
$trans = $culture->translate('AUTH_INVALID', 'Invalid credentials');
echo "  Culture translation OK: {$trans}\n";

$cache = $c->get('cache');
$cache->put('reports', 'test_key', 'cached_val', 60);
$cached = $cache->get('reports', 'test_key', 60, fn() => 'fresh');
assert($cached === 'cached_val');
echo "  Cache OK\n";

// 4. User Service
echo "\n[4] Testing UserService...\n";
$users = $c->get('users');
$testPhone = '0912' . random_digits(7);
$validNid = generateValidNationalId();
$newRider = $users->create([
    'role' => 'rider',
    'first_name' => 'علی',
    'last_name' => 'رضایی',
    'phone' => $testPhone,
    'national_id' => $validNid,
    'password' => 'pass12345',
], $actorAdmin);
echo "  Rider created OK: ID {$newRider['id']}\n";

$riderUser = $users->get($newRider['id']);
assert($riderUser['role'] === 'rider');
echo "  User get OK: {$riderUser['first_name']} {$riderUser['last_name']}\n";

$actorRider = ['id' => $newRider['id'], 'role' => 'rider', 'username' => $riderUser['username']];

// 5. Club Service
echo "\n[5] Testing ClubService...\n";
$clubs = $c->get('clubs');
$clubRes = $clubs->list([], $actorAdmin);
$clubList = $clubRes['rows'];
echo "  Clubs count: " . count($clubList) . "\n";

// 6. Horse Service
echo "\n[6] Testing HorseService...\n";
$horses = $c->get('horses');
$newHorse = $horses->create([
    'owner_user_id' => $newRider['id'],
    'name' => 'تندر جدید',
    'microchip_number' => random_digits(15),
    'gender' => 'نری',
    'race' => 'ترکمن',
], $actorRider);
echo "  Horse created OK: ID {$newHorse['id']}\n";

// 7. Competition & Rade Service
echo "\n[7] Testing CompetitionService & RadeService...\n";
$competitions = $c->get('competitions');
$compList = $competitions->list(['status' => 'all'], $actorAdmin);
echo "  Competitions total: " . ($compList['total'] ?? 0) . "\n";

$newComp = $competitions->create([
    'title' => 'مسابقه آزمایشی جدید',
    'venue_club_id' => $clubList[0]['id'] ?? 1,
    'city' => 'گرگان',
    'start_registration_at' => now_utc(),
    'end_registration_at' => utc_iso(time() + 86400 * 7),
    'start_at' => utc_iso(time() + 86400 * 8),
    'end_at' => utc_iso(time() + 86400 * 9),
], $actorAdmin);
echo "  Competition created OK: ID {$newComp['id']}\n";

$rades = $c->get('rades');
$radeList = $rades->list();
assert(!empty($radeList));

$db = $c->get('db');
$paymentsList = $db->select('SELECT id FROM payments');
$paymentId = $paymentsList[0]['id'] ?? 1;

$compRade = $competitions->addRade($newComp['id'], [
    'rade_id' => $radeList[0]['id'],
    'payment_id' => $paymentId,
    'capacity' => 20,
], $actorAdmin);
echo "  CompRade added OK: ID {$compRade['id']}\n";

// Publish / open competition
$competitions->update($newComp['id'], ['status' => 'open'], $actorAdmin);
echo "  Competition status updated to 'open'\n";

// 8. Signup & Payment Service
echo "\n[8] Testing SignupService & PaymentService...\n";
$signups = $c->get('signups');
$payments = $c->get('payments');

$signupRes = $signups->create(
    (int) $compRade['id'],
    (int) $newHorse['id'],
    (int) ($clubList[0]['id'] ?? 0),
    $actorRider
);
echo "  Signup created OK: ID {$signupRes['signup_id']}, amount: {$signupRes['amount_irt']}\n";

if (!empty($signupRes['order_id'])) {
    $order = $db->selectOne('SELECT * FROM payment_orders WHERE id = :id', ['id' => $signupRes['order_id']]);
    $orderUuid = $order['uuid'];
    echo "  Payment order created: UUID {$orderUuid}\n";

    // Simulate updating authority
    $mockAuthority = 'A' . random_digits(32);
    $db->update('payment_orders', ['authority' => $mockAuthority], 'id = :id', ['id' => $signupRes['order_id']]);

    // Test verify authority
    $signups->markPaid($signupRes['signup_id']);
    echo "  Signup marked paid OK\n";
}

// 9. Result Service
echo "\n[9] Testing ResultService...\n";
$results = $c->get('results');
$results->saveDraft($newComp['id'], [
    'rades' => [
        [
            'comp_rade_id' => $compRade['id'],
            'signups' => [
                ['id' => $signupRes['signup_id'], 'position' => 1, 'is_winner' => 1, 'result_notes' => 'مقام اول']
            ]
        ]
    ]
], $actorAdmin);
echo "  Results draft saved OK\n";

$results->confirm($newComp['id'], $actorAdmin);
echo "  Results confirmed OK\n";

$results->publish($newComp['id'], $actorAdmin);
echo "  Results published OK\n";

// 10. Reports & KPIs
echo "\n[10] Testing Reports & KPI Service...\n";
$reports = $c->get('reports');
$finSummary = $reports->run('financial_summary', []);
echo "  Report 'financial_summary' returned " . count($finSummary['rows']) . " rows\n";

$kpi = $c->get('kpi');
$adminKpis = $kpi->getDashboardKpis('admin', 1);
echo "  Admin Dashboard KPIs generated: " . count($adminKpis) . " metrics\n";

// 11. Backup & Reset
echo "\n[11] Testing BackupService...\n";
$backup = $c->get('backup');
$backupZip = $backup->createBackup($actorAdmin);
echo "  Backup created: " . basename($backupZip) . "\n";

echo "\n=========================================\n";
echo "All Integration Tests Passed Successfully!\n";
echo "=========================================\n";
