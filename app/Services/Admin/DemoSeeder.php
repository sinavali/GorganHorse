<?php
declare(strict_types=1);

/**
 * File: app/Services/Admin/DemoSeeder.php
 *
 * Purpose:
 *   Rich, idempotent demo seeder representing one year of federation operation
 *   (Blueprint §16). Generates clubs, riders, horses, competitions, competition
 *   rades, signups, payment orders, results, transfers, shares, bans, and
 *   messages. Every seeded row carries is_demo = 1 so it can be cleared in bulk.
 *
 * Dependencies: Database, SettingService, LogService.
 *
 * Conventions:
 *   - Idempotent: re-running never duplicates (guarded by a demo marker).
 *   - All Jalali dates are converted to UTC at seed time (P03).
 *   - Iranian names, cities, phones, national IDs, microchips.
 *
 * @package App\Services\Admin
 */

namespace App\Services\Admin;

use App\Bootstrap\Database;
use App\Services\SettingService;
use App\Support\Vendor\Jalali;
use App\Support\Vendor\PhoneValidator;

/**
 * Class: DemoSeeder
 * Purpose: Seed and clear a realistic one-year demo dataset.
 */
final class DemoSeeder
{
    private Database $db;
    private SettingService $settings;
    private \App\Services\LogService $log;

    /** @var string[] Iranian first names. */
    private const FIRST_NAMES = ['سینا', 'امیر', 'محمد', 'علی', 'رضا', 'حسین', 'مهدی', 'سارا', 'نگار', 'الهام', 'فاطمه', 'زهرا', 'یاسر', 'بهنام', 'کامران', 'شهرام', 'پریسا', 'لیلا', 'آرش', 'فرهاد'];
    /** @var string[] Iranian last names. */
    private const LAST_NAMES = ['محمدی', 'حسینی', 'رضایی', 'کریمی', 'موسوی', 'جعفری', 'احمدی', 'صادقی', 'نوری', 'قاسمی', 'شریفی', 'عبدی', 'زمانی', 'رستمی', 'کاظمی'];
    /** @var string[] Iranian cities. */
    private const CITIES = ['گرگان', 'تهران', 'مشهد', 'اصفهان', 'شیراز', 'بندرعباس', 'ساری', 'بجنورد', 'قزوین'];
    /** @var string[] Horse names. */
    private const HORSE_NAMES = ['برق', 'طوفان', 'آتش', 'ستاره', 'رعد', 'سهراب', 'تندر', 'پگاه', 'دریا', 'کوه', 'باد', 'شاهین', 'تیزپا', 'گل', 'ناز', 'سیمرغ', 'آریا', 'کوروش', 'زال', 'رستم'];
    /** @var string[] Club names. */
    private const CLUB_NAMES = ['باشگاه هیرکان', 'باشگاه شکوه طبیعت', 'باشگاه سزار', 'باشگاه گلستان'];

    /**
     * @param Database       $db       Main DB.
     * @param SettingService $settings Settings.
     * @param \App\Services\LogService $log Logs.
     */
    public function __construct(Database $db, SettingService $settings, \App\Services\LogService $log)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
    }

    /**
     * Seed the demo dataset (idempotent).
     *
     * @return array<string,int> Summary counts.
     *
     * Side effects: inserts demo rows across many tables. Transaction: yes (per phase).
     */
    public function seed(): array
    {
        $existing = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE is_demo = 1 AND role = \'rider\'');
        if ($existing >= 60) {
            return ['skipped' => 1, 'reason' => 'Demo data already present'];
        }

        $now = time();
        $summary = [];

        // Ensure reference data exists first.
        require_once BASE_PATH . '/database/seeds/reference.php';
        if (function_exists('seed_reference')) { seed_reference(); }

        $summary['clubs'] = $this->seedClubs();
        $summary['riders'] = $this->seedRiders();
        $summary['horses'] = $this->seedHorses();
        $summary['competitions'] = $this->seedCompetitions();
        $summary['signups'] = $this->seedSignupsAndOrders();
        $summary['results'] = $this->seedResults();
        $summary['transfers'] = $this->seedTransfers();
        $summary['shares'] = $this->seedShares();
        $summary['bans'] = $this->seedBans();
        $summary['messages'] = $this->seedMessages();

        $this->log->changelog([
            'actor_id' => null, 'actor_role' => 'system', 'action' => 'demo.seed',
            'target_type' => 'system', 'target_id' => 0, 'summary' => 'ایجاد داده‌های نمونه',
        ]);
        return $summary;
    }

    /**
     * Clear all demo rows and demo media.
     *
     * @return int Total rows cleared.
     *
     * Side effects: deletes is_demo = 1 rows and uploads/demo/.
     */
    public function clear(): int
    {
        $tables = ['signups', 'payment_orders', 'competition_rades', 'competitions', 'horse_images', 'horse_shares',
            'horse_transfers', 'horses', 'club_bans', 'rider_bans', 'notifications', 'message_recipients', 'messages',
            'report_shares', 'media', 'clubs', 'rider_profiles', 'users', 'sessions'];
        $total = 0;
        $this->db->transaction(function () use ($tables, &$total): void {
            foreach ($tables as $t) {
                $col = in_array($t, ['signups', 'payment_orders', 'competitions', 'competition_rades', 'horses', 'clubs', 'users', 'media'], true) ? 'is_demo' : null;
                if ($col !== null) {
                    $total += $this->db->execute('DELETE FROM ' . $t . ' WHERE is_demo = 1');
                } else {
                    // Child tables without is_demo: cascade handles cleanup.
                }
            }
        });
        // Remove demo media files.
        foreach (glob(BASE_PATH . '/uploads/demo/*') ?: [] as $file) { @unlink($file); }
        return $total;
    }

    /** @return int Clubs seeded. */
    private function seedClubs(): int
    {
        $now = now_utc();
        $count = 0;
        foreach (self::CLUB_NAMES as $i => $name) {
            if ((int) $this->db->scalar("SELECT COUNT(*) FROM clubs WHERE name = :n AND is_demo = 1", ['n' => $name]) > 0) { continue; }
            $userId = $this->insertUser('club', $name, self::CITIES[$i % count(self::CITIES)], 'club' . ($i + 1), $now);
            $this->db->insert('clubs', [
                'uuid' => uuid4(), 'user_id' => $userId, 'name' => $name,
                'slug' => 'club-' . ($i + 1) . '-' . random_digits(4),
                'city' => self::CITIES[$i % count(self::CITIES)], 'province' => 'گلستان',
                'address' => 'خیابان اصلی، پلاک ' . random_int(1, 200),
                'contact_person' => self::FIRST_NAMES[$i] . ' ' . self::LAST_NAMES[$i],
                'phone' => $this->phone(), 'email' => null,
                'description' => 'باشگاه سوارکاری نمونه', 'is_active' => 1, 'is_demo' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return int Riders seeded. */
    private function seedRiders(): int
    {
        $now = now_utc();
        $count = 0;
        for ($i = 0; $i < 60; $i++) {
            $level = $i < 30 ? 'active' : ($i < 50 ? 'occasional' : 'inactive');
            $userId = $this->insertUser('rider', self::FIRST_NAMES[$i % count(self::FIRST_NAMES)], self::CITIES[$i % count(self::CITIES)], 'rider' . ($i + 1), $now, 'verified');
            if ($userId === 0) { continue; }
            $this->db->insert('rider_profiles', [
                'user_id' => $userId,
                'gender' => $i % 3 === 0 ? 'زن' : 'مرد',
                'birth_date' => utc_iso($now - random_int(20, 40) * 365 * 86400),
                'insurance_number' => 'INS' . random_digits(8),
                'province' => 'گلستان', 'city' => self::CITIES[$i % count(self::CITIES)],
                'address' => 'خیابان نمونه، پلاک ' . random_int(1, 300),
                'bio' => 'سوارکار نمونه', 'experience_level' => $level,
                'my_share_code' => $this->uniqueShareCode(),
                'is_demo' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return int Horses seeded. */
    private function seedHorses(): int
    {
        $now = now_utc();
        $riders = $this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1");
        $genders = array_column($this->db->select('SELECT name FROM horse_genders'), 'name');
        $races = array_column($this->db->select('SELECT name FROM horse_races'), 'name');
        $colors = array_column($this->db->select('SELECT name FROM horse_colors'), 'name');
        $count = 0;
        $microchips = [];
        foreach ($riders as $idx => $rider) {
            if ($count >= 90) { break; }
            $n = random_int(1, 4);
            for ($j = 0; $j < $n && $count < 90; $j++) {
                do { $mc = (string) random_int(100000000000000, 999999999999999); } while (isset($microchips[$mc]));
                $microchips[$mc] = true;
                $this->db->insert('horses', [
                    'uuid' => uuid4(), 'owner_user_id' => (int) $rider['id'],
                    'name' => self::HORSE_NAMES[($count + $j) % count(self::HORSE_NAMES)] . '-' . random_int(1, 999),
                    'microchip_number' => $mc, 'ueln' => null,
                    'gender' => $genders[$count % max(1, count($genders))] ?? null,
                    'race' => $races[$count % max(1, count($races))] ?? null,
                    'color' => $colors[$count % max(1, count($colors))] ?? null,
                    'birth_date' => utc_iso(time() - random_int(3, 12) * 365 * 86400),
                    'ghamari_birthday' => utc_iso(time() - random_int(3, 12) * 365 * 86400),
                    'sire_name' => self::HORSE_NAMES[random_int(0, count(self::HORSE_NAMES) - 1)],
                    'dam_name' => self::HORSE_NAMES[random_int(0, count(self::HORSE_NAMES) - 1)],
                    'breeder' => 'پرورش‌دهنده نمونه', 'registration_no' => 'REG-' . random_digits(6),
                    'status' => 'active', 'transfer_locked' => 0, 'share_code' => random_digits(6),
                    'is_demo' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /** @return int Competitions seeded. */
    private function seedCompetitions(): int
    {
        $clubs = $this->db->select("SELECT id FROM clubs WHERE is_demo = 1");
        $compRades = array_column($this->db->select('SELECT id FROM rades'), 'id');
        $payments = $this->db->select('SELECT id, amount_irt FROM payments');
        // 52 weekly competitions over 12 months, all in the past relative to seed time.
        $count = 0;
        $now = time();
        foreach ($clubs as $clubPtr => $club) {
            // Distribute ~13 per club.
            for ($w = 0; $w < 13; $w++) {
                $weeksAgo = 52 - ($count + 1);
                $start = $now - $weeksAgo * 7 * 86400;
                $startReg = $start - 14 * 86400;
                $endReg = $start - 2 * 86400;
                $status = $weeksAgo > 2 ? 'finished' : ($weeksAgo > 0 ? 'running' : 'open');
                $compId = $this->db->insert('competitions', [
                    'uuid' => uuid4(),
                    'title' => 'مسابقه هفتگی پرش ' . Jalali::format($start, 'YYYY/MM/DD'),
                    'slug' => 'comp-' . $count . '-' . random_digits(4),
                    'venue_club_id' => (int) $club['id'],
                    'city' => 'گرگان',
                    'description' => 'مسابقه نمونه', 'rules' => 'قوانین استاندارد',
                    'start_registration_at' => utc_iso($startReg),
                    'end_registration_at' => utc_iso($endReg),
                    'start_at' => utc_iso($start),
                    'end_at' => utc_iso($start + 86400),
                    'registration_paused' => 0, 'status' => $status,
                    'results_status' => $weeksAgo > 4 ? 'published' : ($weeksAgo > 0 ? 'confirmed' : 'draft'),
                    'results_published_at' => $weeksAgo > 4 ? utc_iso($start + 86400) : null,
                    'is_demo' => 1, 'created_at' => utc_iso($startReg), 'updated_at' => utc_iso($start),
                ]);
                // Bind 3-6 rades.
                $nRades = random_int(3, 6);
                shuffle($compRades);
                for ($k = 0; $k < $nRades && $k < count($compRades); $k++) {
                    $pay = $payments[array_rand($payments)];
                    $this->db->insert('competition_rades', [
                        'uuid' => uuid4(), 'competition_id' => $compId, 'rade_id' => (int) $compRades[$k],
                        'payment_id' => (int) $pay['id'],
                        'capacity' => random_int(10, 40), 'auto_confirm' => random_int(0, 1),
                        'had_barrage' => random_int(0, 4) === 0 ? 1 : 0, 'barrage_notes' => null,
                        'signup_mode' => 'per_rade', 'sort_order' => $k,
                        'is_demo' => 1, 'created_at' => utc_iso($startReg), 'updated_at' => utc_iso($start),
                    ]);
                }
                $count++;
            }
        }
        return $count;
    }

    /** @return int Signups seeded. */
    private function seedSignupsAndOrders(): int
    {
        $compRades = $this->db->select(
            "SELECT cr.id, cr.competition_id, cr.rade_id, cr.payment_id, cr.auto_confirm, c.start_at
             FROM competition_rades cr JOIN competitions c ON c.id = cr.competition_id WHERE c.is_demo = 1"
        );
        $riders = $this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1");
        $horsesByRider = [];
        foreach ($this->db->select("SELECT id, owner_user_id FROM horses WHERE is_demo = 1") as $h) {
            $horsesByRider[(int) $h['owner_user_id']][] = (int) $h['id'];
        }
        $clubs = array_column($this->db->select("SELECT id FROM clubs WHERE is_demo = 1"), 'id');
        $prices = [];
        foreach ($this->db->select('SELECT id, amount_irt FROM payments') as $p) { $prices[(int) $p['id']] = (int) $p['amount_irt']; }
        $now = now_utc();
        $count = 0;
        $target = 1800;
        $seen = [];

        foreach ($compRades as $cr) {
            if ($count >= $target) { break; }
            $perRade = random_int(5, 20);
            for ($s = 0; $s < $perRade && $count < $target; $s++) {
                $rider = $riders[array_rand($riders)];
                $riderId = (int) $rider['id'];
                if (empty($horsesByRider[$riderId])) { continue; }
                $horseId = $horsesByRider[$riderId][array_rand($horsesByRider[$riderId])];
                $dedupe = (int) $cr['id'] . ':' . $riderId . ':' . $horseId;
                if (isset($seen[$dedupe])) { continue; }
                $seen[$dedupe] = true;
                $amount = $prices[(int) $cr['payment_id']] ?? 0;
                $isPast = strtotime((string) $cr['start_at']) < time();
                $status = $isPast ? 'confirmed' : (random_int(0, 5) === 0 ? 'pending_payment' : 'confirmed');
                $signupId = $this->db->insert('signups', [
                    'uuid' => uuid4(), 'competition_id' => (int) $cr['competition_id'],
                    'competition_rade_id' => (int) $cr['id'], 'rade_id' => (int) $cr['rade_id'],
                    'rider_user_id' => $riderId, 'horse_id' => $horseId,
                    'affiliation_club_id' => $clubs[array_rand($clubs)],
                    'status' => $status, 'is_confirmed' => $status === 'confirmed' ? 1 : 0,
                    'confirmed_by' => null, 'confirmed_at' => $status === 'confirmed' ? $cr['start_at'] : null,
                    'position' => null, 'is_winner' => 0, 'result_notes' => null,
                    'payment_id_snapshot' => (int) $cr['payment_id'], 'payment_name_snapshot' => 'قالب پرداخت',
                    'payment_amount_irt_snapshot' => $amount, 'order_uuid' => null,
                    'is_demo' => 1, 'created_at' => $cr['start_at'], 'updated_at' => $now,
                ]);
                // Payment order.
                $orderStatus = match (true) {
                    $status === 'pending_payment' => 'pending',
                    random_int(0, 40) === 0 => 'refunded',
                    default => 'paid',
                };
                $orderUuid = uuid4();
                $this->db->insert('payment_orders', [
                    'uuid' => $orderUuid, 'signup_id' => $signupId,
                    'competition_id' => (int) $cr['competition_id'], 'rider_user_id' => $riderId,
                    'amount_irt' => $amount, 'status' => $orderStatus,
                    'authority' => 'A' . random_digits(20), 'ref_id' => $orderStatus === 'paid' || $orderStatus === 'refunded' ? (string) random_int(100000000, 999999999) : null,
                    'card_pan' => '6037****' . random_digits(4), 'description' => 'شرکت در مسابقه',
                    'gateway' => 'zarinpal', 'verified_at' => $orderStatus !== 'pending' ? $cr['start_at'] : null,
                    'refunded_at' => $orderStatus === 'refunded' ? $now : null,
                    'is_demo' => 1, 'created_at' => $cr['start_at'], 'updated_at' => $now,
                ]);
                $this->db->update('signups', ['order_uuid' => $orderUuid], 'id = :id', ['id' => $signupId]);
                $count++;
            }
        }
        return $count;
    }

    /** @return int Competitions with results. */
    private function seedResults(): int
    {
        $competitions = $this->db->select("SELECT id FROM competitions WHERE is_demo = 1 AND results_status != 'draft'");
        $count = 0;
        foreach ($competitions as $comp) {
            $compRades = $this->db->select('SELECT id FROM competition_rades WHERE competition_id = :c', ['c' => (int) $comp['id']]);
            foreach ($compRades as $cr) {
                $signups = $this->db->select("SELECT id FROM signups WHERE competition_rade_id = :cr AND status = 'confirmed' ORDER BY id ASC", ['cr' => (int) $cr['id']]);
                $position = 1;
                foreach ($signups as $s) {
                    $this->db->update('signups', [
                        'position' => $position,
                        'is_winner' => $position === 1 ? 1 : 0,
                        'result_notes' => $position === 1 ? 'مقام اول' : null,
                        'updated_at' => now_utc(),
                    ], 'id = :id', ['id' => (int) $s['id']]);
                    $position++;
                }
            }
            $count++;
        }
        return $count;
    }

    /** @return int Transfers seeded. */
    private function seedTransfers(): int
    {
        $horses = $this->db->select("SELECT id, owner_user_id FROM horses WHERE is_demo = 1 LIMIT 12");
        $riders = array_column($this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1"), 'id');
        $now = now_utc();
        $count = 0;
        foreach ($horses as $h) {
            $toUser = $riders[array_rand($riders)];
            if ((int) $toUser === (int) $h['owner_user_id']) { continue; }
            $this->db->insert('horse_transfers', [
                'horse_id' => (int) $h['id'], 'from_user_id' => (int) $h['owner_user_id'],
                'to_user_id' => (int) $toUser, 'transfer_code' => random_alnum(8),
                'status' => 'accepted', 'accepted_at' => utc_iso(strtotime('-30 days')), 'expires_at' => utc_iso(strtotime('+7 days')),
                'is_demo' => 1, 'created_at' => utc_iso(strtotime('-40 days')), 'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return int Shares seeded. */
    private function seedShares(): int
    {
        $horses = $this->db->select("SELECT id, owner_user_id FROM horses WHERE is_demo = 1 LIMIT 20");
        $riders = array_column($this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1"), 'id');
        $now = now_utc();
        $count = 0;
        foreach ($horses as $h) {
            $toUser = $riders[array_rand($riders)];
            if ((int) $toUser === (int) $h['owner_user_id']) { continue; }
            $this->db->insert('horse_shares', [
                'horse_id' => (int) $h['id'], 'owner_user_id' => (int) $h['owner_user_id'],
                'recipient_user_id' => (int) $toUser, 'status' => random_int(0, 1) ? 'accepted' : 'pending',
                'is_demo' => 1, 'created_at' => utc_iso(strtotime('-20 days')), 'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return int Bans seeded. */
    private function seedBans(): int
    {
        $clubs = array_column($this->db->select("SELECT id FROM clubs WHERE is_demo = 1"), 'id');
        $riders = array_column($this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1"), 'id');
        $horses = array_column($this->db->select("SELECT id FROM horses WHERE is_demo = 1"), 'id');
        $now = now_utc();
        $count = 0;
        for ($i = 0; $i < 3; $i++) {
            $this->db->insert('club_bans', [
                'club_id' => $clubs[array_rand($clubs)], 'target_type' => $i % 2 === 0 ? 'rider' : 'horse',
                'target_id' => $i % 2 === 0 ? $riders[array_rand($riders)] : $horses[array_rand($horses)],
                'reason' => 'دلیل نمونه', 'banned_by' => $riders[0] ?? 1, 'is_active' => 1, 'is_demo' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $count++;
        }
        for ($i = 0; $i < 5; $i++) {
            $this->db->insert('rider_bans', [
                'target_type' => 'rider', 'target_id' => $riders[array_rand($riders)],
                'scope' => 'global', 'reason' => 'تحریم نمونه', 'banned_by' => $riders[0] ?? 1,
                'is_active' => 1, 'is_demo' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return int Messages seeded. */
    private function seedMessages(): int
    {
        $admin = $this->db->selectOne("SELECT id FROM users WHERE role IN ('admin','manager') ORDER BY id ASC LIMIT 1");
        if ($admin === null) { return 0; }
        $now = now_utc();
        $count = 0;
        for ($i = 0; $i < 6; $i++) {
            $this->db->insert('messages', [
                'uuid' => uuid4(), 'sender_id' => (int) $admin['id'],
                'subject' => 'اطلاعیه شماره ' . ($i + 1), 'body' => 'این یک پیام نمونه از مدیریت فدراسیون است.',
                'scope' => 'global', 'via_sms' => 0, 'is_demo' => 1, 'created_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Insert a demo user.
     *
     * @param string $role     Role.
     * @param string $name     Display name.
     * @param string $city     City.
     * @param string $username Username.
     * @param string $now      UTC timestamp.
     * @param string $verification Verification status.
     * @return int User id (0 when it already exists).
     */
    private function insertUser(string $role, string $name, string $city, string $username, string $now, string $verification = 'verified'): int
    {
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $username]) > 0) {
            return 0;
        }
        return $this->db->insert('users', [
            'uuid' => uuid4(), 'role' => $role, 'username' => $username,
            'phone' => $this->phone(), 'email' => null,
            'password_hash' => password_hash('demo12345', PASSWORD_DEFAULT),
            'first_name' => $name, 'last_name' => self::LAST_NAMES[random_int(0, count(self::LAST_NAMES) - 1)],
            'national_id' => $this->nationalId(), 'avatar_media_id' => null,
            'disable_state' => 'none', 'verification_status' => $verification, 'auto_verify_at' => null,
            'is_demo' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Generate a valid Iranian phone number.
     *
     * @return string E.164 phone.
     */
    private function phone(): string
    {
        $prefixes = ['0911', '0912', '0917', '0918', '0919', '0993', '0901'];
        return $prefixes[random_int(0, count($prefixes) - 1)] . random_digits(7);
    }

    /**
     * Generate a valid Iranian national ID (check digit correct).
     *
     * @return string
     */
    private function nationalId(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $digits = (string) random_int(100000000, 999999999);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) { $sum += ((int) $digits[$i]) * (10 - $i); }
            $rem = $sum % 11;
            $check = $rem < 2 ? $rem : 11 - $rem;
            $candidate = $digits . $check;
            if (PhoneValidator::isValidNationalId($candidate)) { return $candidate; }
        }
        return '0000000000';
    }

    /**
     * Generate a unique 6-digit rider share code.
     *
     * @return string
     */
    private function uniqueShareCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = random_digits(6);
            if ((int) $this->db->scalar('SELECT COUNT(*) FROM rider_profiles WHERE my_share_code = :c', ['c' => $code]) === 0) {
                return $code;
            }
        }
        return random_digits(8);
    }
}
