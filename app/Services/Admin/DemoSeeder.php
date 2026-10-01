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

    /** Target rider count for a full demo dataset. */
    private const TARGET_RIDERS = 240;
    /** Target horse count for a full demo dataset. */
    private const TARGET_HORSES = 520;
    /** Target signup/order count for a full demo dataset. */
    private const TARGET_SIGNUPS = 6000;

    /** @var string[] Iranian first names. */
    private const FIRST_NAMES = ['سینا', 'امیر', 'محمد', 'علی', 'رضا', 'حسین', 'مهدی', 'سارا', 'نگار', 'الهام', 'فاطمه', 'زهرا', 'یاسر', 'بهنام', 'کامران', 'شهرام', 'پریسا', 'لیلا', 'آرش', 'فرهاد', 'وحید', 'بهرام', 'کیوان', 'سیاوش', 'نیما', 'رامین', 'شیما', 'مریم', 'حامد', 'پویا', 'ایمان', 'سمیرا', 'نیما', 'آرمان', 'بهاره', 'فرزاد', 'شادی', 'کاوه', 'مژگان', 'یاسین', 'رویا'];
    /** @var string[] Iranian last names. */
    private const LAST_NAMES = ['محمدی', 'حسینی', 'رضایی', 'کریمی', 'موسوی', 'جعفری', 'احمدی', 'صادقی', 'نوری', 'قاسمی', 'شریفی', 'عبدی', 'زمانی', 'رستمی', 'کاظمی', 'طاهری', 'یزدانی', 'بهرامی', 'اکبری', 'سلطانی', 'دانش', 'فرهمند', 'خسروی', 'امینی'];
    /** @var string[] Iranian cities — weighted toward Golestan province. */
    private const CITIES = ['گرگان', 'گرگان', 'گرگان', 'بندر ترکمن', 'آق‌قلا', 'مینودشت', 'علی‌آباد کتول', 'کردکوی', 'گمیشان', 'قشقار', 'ساری', 'بابل', 'نکا', 'بهشهر', 'قائم‌شهر', 'چالوس', 'تهران', 'مشهد', 'اصفهان', 'شیراز', 'بندرعباس', 'قزوین'];
    /** @var string[] Horse names. */
    private const HORSE_NAMES = ['برق', 'طوفان', 'آتش', 'ستاره', 'رعد', 'سهراب', 'تندر', 'پگاه', 'دریا', 'کوه', 'باد', 'شاهین', 'تیزپا', 'گل', 'ناز', 'سیمرغ', 'آریا', 'کوروش', 'زال', 'رستم', 'آذر', 'بهرام', 'پارس', 'مانی', 'نیلوفر', 'سروش', 'هما', 'کوهسار', 'ارغوان', 'آفتاب', 'مهتاب', 'پرنیان', 'زرین', 'سپیدار', 'کیوان', 'خورشید'];
    /** @var string[] Club names — one per Golestan district/venue. */
    private const CLUB_NAMES = [
        'باشگاه هیرکان گرگان', 'باشگاه شکوه طبیعت گرگان', 'باشگاه سزار گرگان', 'باشگاه گلستان گرگان',
        'باشگاه موج بندر ترکمن', 'باشگاه آق‌قلا', 'باشگاه مینودشت', 'باشگاه علی‌آباد کتول',
        'باشگاه کردکوی', 'باشگاه گمیشان', 'باشگاه قشقار', 'باشگاه شرق گرگان',
        'باشگاه مرکزی گرگان', 'باشگاه البرز گرگان', 'باشگاه ساحل گرگان', 'باشگاه نگین گرگان',
        'باشگاه پارس گرگان', 'باشگاه خزر گرگان', 'باشگاه سپهر گرگان', 'باشگاه یاس گرگان',
    ];
    /** @var string[] Competition title stems. */
    private const COMP_TITLES = [
        'هفته‌ای پرش بلند', 'جام هیرکان', 'قهرمانی استان گلستان', 'دوره‌ای اسب‌دوانی',
        'مسابقات پرش با موانع', 'جام سزار', 'دوره‌ای توان', 'آزمون عملکرد اسب‌ها',
        'جام شکوه طبیعت', 'دوره‌ای استقامت', 'مسابقات کورس', 'جام پاییزه گرگان',
        'دوره‌ای سرعت', 'جام بهاری طبیعت', 'آزمون مهارت‌های پایه',
    ];
    /** @var string[] Competition descriptions. */
    private const COMP_DESCRIPTIONS = [
        'این مسابقه با هدف سنجش آمادگی اسب و مهارت سوار در پرش بلند برگزار می‌شود و شامل سه رده مجزا است.',
        'دوره‌ای سه‌روزه از تمرینات کورس و پرش، با حضور داوران رسمی استان.',
        'رقابتی در رده‌های پایه و پیشرفته با جایزه برای سه نفر اول هر رده.',
        'مسابقه تخصصی اسب‌های بوم استان با تمرکز بر استقامت و پیروزی در مسیرهای طبیعی.',
        'آزمون عملکرد شامل سه مرحله: کنترل، سرعت و پرش. نتیجه به تفکیک رده اعلام می‌شود.',
        'رویداد ویژه پایان فصل با حضور اسب‌های برتر استان و مدارس سوارکاری.',
    ];
    /** @var string[] Competition rule sets. */
    private const COMP_RULES = [
        'حضور با کارت عضویت معتبر و ریزتراشه ثبت‌شده الزامی است. کلاه ایمنی برای همه رده‌ها اجباری است.',
        'هر سوارکار در هر رده حداکثر یک اسب می‌تواند ثبت‌نام کند. لغو ثبت‌نام تا ۴۸ ساعت قبل امکان‌پذیر است.',
        'تجهیزات ایمنی و کفش سوارکاری پیش از ورود به میدان کنترل می‌شود. عدم رعایت موجب حذف است.',
        'مسابقه در سه مرحله برگزار می‌شود. لغو به دلیل شرایط جوی با اطلاع یک‌روزه انجام می‌گیرد.',
        'در صورت تساوی، مقام اول بر اساس زمان مرحله سوم و سپس نظرات داوران تعیین می‌شود.',
    ];
    /** @var string[] Mandatory announcements. */
    private const ANNOUNCEMENTS = [
        'همه سوارکاران باید کارت عضویت معتبر و بیمه اسب همراه داشته باشند؛ در غیر این صورت امکان ورود به میدان وجود ندارد.',
        'این دوره صرفاً برای سوارکاران دارای حداقل یک شرکت تأییدشده در سال جاری است.',
        'ورود به میدان از ساعت ۷ صبح و خروج تا ساعت ۱۸ الزامی است. تأخیر موجب حذف از رتبه‌بندی می‌شود.',
    ];

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
        if ($existing >= self::TARGET_RIDERS) {
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
        $summary['health'] = $this->seedHorseHealth();
        $summary['competitions'] = $this->seedCompetitions();
        $summary['signups'] = $this->seedSignupsAndOrders();
        $summary['results'] = $this->seedResults();
        $summary['transfers'] = $this->seedTransfers();
        $summary['shares'] = $this->seedShares();
        $summary['bans'] = $this->seedBans();
        $summary['messages'] = $this->seedMessages();
        $summary['notifications'] = $this->seedNotifications();

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
            'horse_transfers', 'horse_health_records', 'horses', 'club_bans', 'rider_bans', 'notifications',
            'message_recipients', 'messages', 'report_shares', 'media', 'clubs', 'rider_profiles', 'users', 'sessions'];
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
        $now = time();
        $count = 0;
        for ($i = 0; $i < self::TARGET_RIDERS; $i++) {
            $first = self::FIRST_NAMES[$i % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[($i * 7 + intdiv($i, count(self::FIRST_NAMES))) % count(self::LAST_NAMES)];
            /* Spread join dates over the last two years so the federation looks
               established rather than freshly created. */
            $joinedAt = $now - random_int(5, 730) * 86400;

            /* Verification mix: mostly verified, with a realistic tail of
               pending and rejected accounts plus two disabled states. */
            $roll = $i % 20;
            $verification = $roll === 3 ? 'pending' : ($roll === 17 ? 'rejected' : 'verified');
            $userId = $this->insertUser('rider', $first, self::CITIES[$i % count(self::CITIES)], 'rider' . ($i + 1), utc_iso($joinedAt), $verification);
            if ($userId === 0) { continue; }

            $level = match (true) {
                $i % 5 === 0 => 'inactive',
                $i % 3 === 0 => 'occasional',
                default => 'active',
            };
            $this->db->insert('rider_profiles', [
                'user_id' => $userId,
                'gender' => $i % 3 === 0 ? 'زن' : 'مرد',
                'birth_date' => utc_iso($now - random_int(16, 52) * 365 * 86400),
                'insurance_number' => 'INS' . random_digits(8),
                'province' => $i % 10 === 0 ? 'مازندران' : 'گلستان',
                'city' => self::CITIES[$i % count(self::CITIES)],
                'address' => 'خیابان ' . self::CITIES[$i % count(self::CITIES)] . '، کوچه ' . random_int(1, 40) . '، پلاک ' . random_int(1, 300),
                'bio' => 'سوارکار با سابقه در رده‌های ' . ($level === 'active' ? 'پیشرفته' : 'پایه') . '، عضو باشگاه ' . self::CLUB_NAMES[$i % count(self::CLUB_NAMES)],
                'experience_level' => $level,
                'my_share_code' => $this->uniqueShareCode(),
                'is_demo' => 1, 'created_at' => utc_iso($joinedAt), 'updated_at' => utc_iso($joinedAt),
            ]);

            /* A slice of accounts are limited or fully disabled so the
               admin screens show those states instead of an all-green list. */
            if ($i % 47 === 0) {
                $this->db->update('users', ['disable_state' => 'limited', 'disable_reason' => 'محدودیت موقت باشگاهی'], 'id = :id', ['id' => $userId]);
            } elseif ($i % 97 === 0) {
                $this->db->update('users', ['disable_state' => 'full', 'disable_reason' => 'تعلیق با حکم هیئت'], 'id = :id', ['id' => $userId]);
            }
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
            if ($count >= self::TARGET_HORSES) { break; }
            /* 1–6 horses per rider: a long tail of multi-horse riders. */
            $n = random_int(1, 6);
            for ($j = 0; $j < $n && $count < self::TARGET_HORSES; $j++) {
                do { $mc = (string) random_int(100000000000000, 999999999999999); } while (isset($microchips[$mc]));
                $microchips[$mc] = true;
                /* A few horses are missing their microchip or UELN so the
                   "incomplete data" states are represented in the grids. */
                $noMicrochip = ($count % 41 === 0);
                $age = random_int(2, 18);
                $this->db->insert('horses', [
                    'uuid' => uuid4(), 'owner_user_id' => (int) $rider['id'],
                    'name' => self::HORSE_NAMES[($count * 3 + $j) % count(self::HORSE_NAMES)] . '-' . random_int(1, 999),
                    'microchip_number' => $noMicrochip ? null : $mc,
                    'ueln' => ($count % 7 === 0) ? null : 'IR' . random_digits(12),
                    'gender' => $genders ? $genders[$count % count($genders)] : null,
                    'race' => $races ? $races[$count % count($races)] : null,
                    'color' => $colors ? $colors[$count % count($colors)] : null,
                    'birth_date' => utc_iso(time() - $age * 365 * 86400),
                    'ghamari_birthday' => utc_iso(time() - $age * 354 * 86400),
                    'sire_name' => self::HORSE_NAMES[random_int(0, count(self::HORSE_NAMES) - 1)],
                    'dam_name' => self::HORSE_NAMES[random_int(0, count(self::HORSE_NAMES) - 1)],
                    'breeder' => self::CLUB_NAMES[$count % count(self::CLUB_NAMES)] . ' (پرورش داخلی)',
                    'registration_no' => ($count % 11 === 0) ? null : 'REG-' . random_digits(6),
                    'notes' => $count % 6 === 0 ? 'اسب آرام و مناسب رده‌های پایه.' : null,
                    'status' => 'active', 'transfer_locked' => 0, 'share_code' => random_digits(6),
                    'is_demo' => 1,
                    'created_at' => utc_iso(time() - random_int(60, 900) * 86400),
                    'updated_at' => $now,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Veterinary / vaccination history so the health screens have content.
     *
     * @return int Records seeded.
     */
    private function seedHorseHealth(): int
    {
        try {
            $exists = (int) $this->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='horse_health_records'");
            if ($exists === 0) { return 0; }
        } catch (\Throwable) {
            return 0;
        }
        $horses = $this->db->select('SELECT id FROM horses WHERE is_demo = 1');
        if ($horses === []) { return 0; }
        $now = time();
        $types = ['vaccination', 'checkup', 'treatment', 'injury', 'fitness'];
        $titles = [
            'vaccination' => ['واکسیناسیون آنفولانزای اسب', 'واکسیناسیون تetanوس', ' boosters کرپ'],
            'checkup' => ['معاینه دوره‌ای', 'معاینه پیش از مسابقه'],
            'treatment' => ['درمان آسیب تاندون', 'مراقبت پس از جراحی'],
            'injury' => ['آسیب خفیف ساق', 'کبودی پشت'],
            'fitness' => ['گواهی آمادگی مسابقه', 'تأیید سلامت برای صدور مجوز'],
        ];
        $count = 0;
        foreach ($horses as $h) {
            /* Not every horse has records — roughly 70% do. */
            if (random_int(1, 10) > 7) { continue; }
            $n = random_int(1, 4);
            for ($i = 0; $i < $n; $i++) {
                $type = $types[random_int(0, count($types) - 1)];
                $performed = $now - random_int(10, 700) * 86400;
                /* Some records have a next-due date in the future (upcoming
                   vaccinations), some are overdue, some have none. */
                $nextDue = random_int(1, 3) === 1 ? null : $performed + random_int(60, 900) * 86400;
                $this->db->insert('horse_health_records', [
                    'horse_id' => (int) $h['id'],
                    'record_type' => $type,
                    'title' => $titles[$type][array_rand($titles[$type])],
                    'performed_at' => utc_iso($performed),
                    'next_due_at' => $nextDue !== null ? utc_iso($nextDue) : null,
                    'vet_name' => self::LAST_NAMES[array_rand(self::LAST_NAMES)] . ' — دامپزشکی مرکزی گرگان',
                    'notes' => $type === 'injury' ? 'استراحت ۲ هفته و کنترل هفتگی توصیه شد.' : null,
                    'cost_irt' => random_int(1, 40) * 500000,
                    'performed_by' => null,
                    'created_at' => utc_iso($performed),
                    'updated_at' => utc_iso($performed),
                ]);
                $count++;
            }
        }
        return $count;
    }

    /** @return int Competitions seeded. */
    private function seedCompetitions(): int
    {
        $clubs = $this->db->select("SELECT id, name, city FROM clubs WHERE is_demo = 1");
        $compRades = array_column($this->db->select('SELECT id FROM rades'), 'id');
        $payments = $this->db->select('SELECT id, amount_irt FROM payments');
        if ($clubs === [] || $compRades === [] || $payments === []) { return 0; }

        /* Two years of history plus eight weeks of upcoming events, so the
           calendar, dashboards and "attention" queue all have live data. */
        $weeksBack = 104;
        $weeksForward = 8;
        $count = 0;
        $now = time();

        for ($w = $weeksBack + $weeksForward; $w >= -$weeksForward; $w--) {
            $slot = ($weeksBack + $weeksForward) - $w;   /* 0 … weeksBack+forward */
            $club = $clubs[$slot % count($clubs)];
            /* Two competitions some weeks, one in most, none in a few. */
            $perWeek = random_int(1, 10) <= 2 ? 2 : 1;

            for ($c = 0; $c < $perWeek; $c++) {
                /* Spread each event to a plausible hour of the day. */
                $start = $now - $w * 7 * 86400 + $c * 36000 + random_int(0, 6) * 3600;
                $startReg = $start - random_int(10, 30) * 86400;
                $endReg = $start - random_int(1, 5) * 86400;
                $isPast = $start < $now;
                $isSoon = !$isPast && $start - $now < 14 * 86400;

                if ($isPast) {
                    $status = random_int(1, 10) <= 8 ? 'finished' : 'closed';
                    $results = random_int(1, 20) <= 18 ? 'published' : (random_int(1, 3) === 1 ? 'confirmed' : 'draft');
                } elseif ($isSoon) {
                    $status = random_int(1, 10) <= 8 ? 'open' : 'closed';
                    $results = 'draft';
                } else {
                    $status = random_int(1, 10) <= 6 ? 'open' : (random_int(1, 10) <= 8 ? 'closed' : 'draft');
                    $results = 'draft';
                }
                /* A small share is cancelled so the badge map is exercised. */
                if (!$isPast && random_int(1, 60) === 1) { $status = 'cancelled'; }

                $titleStem = self::COMP_TITLES[($slot + $c) % count(self::COMP_TITLES)];
                $announcement = random_int(1, 3) === 1
                    ? self::ANNOUNCEMENTS[array_rand(self::ANNOUNCEMENTS)]
                    : null;

                $compId = $this->db->insert('competitions', [
                    'uuid' => uuid4(),
                    'title' => $titleStem . ' ' . Jalali::format($start, 'YYYY/MM/DD'),
                    'slug' => 'comp-' . $count . '-' . random_digits(4),
                    'venue_club_id' => (int) $club['id'],
                    'city' => (string) ($club['city'] ?? 'گرگان'),
                    'description' => self::COMP_DESCRIPTIONS[($slot + $c) % count(self::COMP_DESCRIPTIONS)],
                    'rules' => self::COMP_RULES[($slot * 2 + $c) % count(self::COMP_RULES)],
                    'announcement' => $announcement,
                    'announcement_required' => $announcement !== null && random_int(1, 2) === 1 ? 1 : 0,
                    'start_registration_at' => utc_iso($startReg),
                    'end_registration_at' => utc_iso($endReg),
                    'start_at' => utc_iso($start),
                    'end_at' => utc_iso($start + random_int(1, 2) * 86400),
                    'registration_paused' => ($status === 'open' && random_int(1, 25) === 1) ? 1 : 0,
                    'status' => $status,
                    'results_status' => $results,
                    'results_published_at' => $results === 'published' ? utc_iso($start + 86400) : null,
                    'cancelled_at' => $status === 'cancelled' ? utc_iso($start - 3 * 86400) : null,
                    'is_demo' => 1, 'created_at' => utc_iso($startReg), 'updated_at' => utc_iso($start),
                ]);
                // Bind 3-8 rades.
                $nRades = random_int(3, 8);
                $shuffled = $compRades;
                shuffle($shuffled);
                for ($k = 0; $k < $nRades && $k < count($shuffled); $k++) {
                    $pay = $payments[array_rand($payments)];
                    $this->db->insert('competition_rades', [
                        'uuid' => uuid4(), 'competition_id' => $compId, 'rade_id' => (int) $shuffled[$k],
                        'payment_id' => (int) $pay['id'],
                        'capacity' => random_int(0, 10) === 0 ? null : random_int(10, 60),
                        'auto_confirm' => random_int(0, 1),
                        'had_barrage' => random_int(0, 5) === 0 ? 1 : 0,
                        'barrage_notes' => null,
                        'signup_mode' => random_int(1, 8) === 1 ? 'per_competition' : 'per_rade',
                        'sort_order' => $k,
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
        $target = self::TARGET_SIGNUPS;
        $seen = [];

        foreach ($compRades as $cr) {
            if ($count >= $target) { break; }
            /* Popular rades fill up, unpopular ones stay empty. */
            $perRade = random_int(0, 10) <= 1 ? random_int(0, 3) : random_int(8, 26);
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
                /* Past events are mostly confirmed; upcoming events mix
                   pending-payment, paid-awaiting-confirmation and rejections. */
                $status = $isPast
                    ? (random_int(1, 25) === 1 ? 'withdrawn' : 'confirmed')
                    : match (true) {
                        random_int(1, 4) === 1 => 'pending_payment',
                        random_int(1, 8) === 1 => 'paid',
                        random_int(1, 20) === 1 => 'rejected',
                        default => 'confirmed',
                    };
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
                    $status === 'rejected' => (random_int(1, 3) === 1 ? 'failed' : 'refunded'),
                    random_int(1, 30) === 1 => 'pending_refund',
                    random_int(1, 45) === 1 => 'refunded',
                    default => 'paid',
                };
                $orderUuid = uuid4();
                $this->db->insert('payment_orders', [
                    'uuid' => $orderUuid, 'signup_id' => $signupId,
                    'competition_id' => (int) $cr['competition_id'], 'rider_user_id' => $riderId,
                    'amount_irt' => $amount, 'status' => $orderStatus,
                    'authority' => 'A' . random_digits(20),
                    'ref_id' => $orderStatus === 'paid' || $orderStatus === 'refunded' || $orderStatus === 'pending_refund'
                        ? (string) random_int(100000000, 999999999) : null,
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
        $horses = $this->db->select("SELECT id, owner_user_id FROM horses WHERE is_demo = 1 LIMIT 40");
        $riders = array_column($this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1"), 'id');
        if ($horses === [] || $riders === []) { return 0; }
        $now = now_utc();
        $count = 0;
        foreach ($horses as $h) {
            $toUser = $riders[array_rand($riders)];
            if ((int) $toUser === (int) $h['owner_user_id']) { continue; }
            /* Mix of pending, accepted, rejected and expired transfers so the
               detail screen shows every branch of the transfer state machine. */
            $status = ['pending', 'accepted', 'accepted', 'rejected', 'expired'][array_rand(['pending', 'accepted', 'accepted', 'rejected', 'expired'])];
            $createdAt = time() - random_int(3, 120) * 86400;
            $this->db->insert('horse_transfers', [
                'horse_id' => (int) $h['id'], 'from_user_id' => (int) $h['owner_user_id'],
                'to_user_id' => (int) $toUser, 'transfer_code' => random_alnum(8),
                'status' => $status,
                'accepted_at' => $status === 'accepted' ? utc_iso($createdAt + 3 * 86400) : null,
                'expires_at' => utc_iso($createdAt + 7 * 86400),
                'is_demo' => 1, 'created_at' => utc_iso($createdAt), 'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return int Shares seeded. */
    private function seedShares(): int
    {
        $horses = $this->db->select("SELECT id, owner_user_id FROM horses WHERE is_demo = 1 LIMIT 70");
        $riders = array_column($this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1"), 'id');
        if ($horses === [] || $riders === []) { return 0; }
        $now = now_utc();
        $count = 0;
        foreach ($horses as $h) {
            $toUser = $riders[array_rand($riders)];
            if ((int) $toUser === (int) $h['owner_user_id']) { continue; }
            $status = ['pending', 'pending', 'accepted', 'rejected', 'revoked'][array_rand(['pending', 'pending', 'accepted', 'rejected', 'revoked'])];
            $this->db->insert('horse_shares', [
                'horse_id' => (int) $h['id'], 'owner_user_id' => (int) $h['owner_user_id'],
                'recipient_user_id' => (int) $toUser, 'status' => $status,
                'is_demo' => 1,
                'created_at' => utc_iso(time() - random_int(1, 90) * 86400),
                'updated_at' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * In-app notifications for staff and riders, so the bell and the
     * notifications page are not empty.
     *
     * @return int Notifications seeded.
     */
    private function seedNotifications(): int
    {
        $admins = array_column($this->db->select("SELECT id FROM users WHERE role IN ('admin','manager')"), 'id');
        $riders = array_column($this->db->select("SELECT id FROM users WHERE role = 'rider' AND is_demo = 1 LIMIT 40"), 'id');
        $comps = $this->db->select("SELECT id, title FROM competitions WHERE is_demo = 1 AND start_at >= :n ORDER BY start_at ASC LIMIT 8", ['n' => now_utc()]);
        if ($admins === []) { return 0; }

        $templates = [
            ['signup.received', 'ثبت‌نام جدید', 'یک سوارکار جدید برای مسابقه ثبت‌نام کرد.', '/panel/signups'],
            ['payment.paid', 'پرداخت موفق', 'پرداخت یک ثبت‌نام با موفقیت انجام شد.', '/panel/payment-orders'],
            ['signup.paid', 'در انتظار تأیید', 'ثبت‌نام پرداخت‌شده منتظر تأیید مدیر است.', '/panel/signups'],
            ['horse.shared', 'درخواست اشتراک اسب', 'سوارکاری اسب را با شما به اشتراک گذاشته است.', '/panel/horse-shares'],
            ['competition.open', 'ثبت‌نام مسابقه باز شد', 'ثبت‌نام مسابقه جدید آغاز شده است.', '/panel/competitions'],
            ['refund.requested', 'درخواست استرداد', 'یک سفارش وارد صف استرداد شده است.', '/panel/payment-orders'],
        ];
        $count = 0;
        $now = time();
        for ($i = 0; $i < 60; $i++) {
            $t = $templates[array_rand($templates)];
            $this->db->insert('notifications', [
                'user_id' => (int) $admins[array_rand($admins)],
                'type' => $t[0], 'title' => $t[1], 'body' => $t[2], 'link' => $t[3],
                'ref_type' => null, 'ref_id' => null,
                'is_read' => $i % 3 === 0 ? 1 : 0,
                'created_at' => utc_iso($now - random_int(1, 600) * 3600),
            ]);
            $count++;
        }
        foreach (array_slice($riders, 0, 25) as $riderId) {
            $comp = $comps[array_rand($comps)] ?? null;
            $this->db->insert('notifications', [
                'user_id' => (int) $riderId,
                'type' => 'signup.status', 'title' => 'وضعیت ثبت‌نام شما تغییر کرد',
                'body' => $comp !== null ? ('وضعیت ثبت‌نام شما در «' . $comp['title'] . '» به‌روزرسانی شد.') : 'وضعیت ثبت‌نام شما به‌روزرسانی شد.',
                'link' => $comp !== null ? ('/panel/rider/competitions/' . (int) $comp['id']) : '/panel/rider/signups',
                'ref_type' => null, 'ref_id' => null,
                'is_read' => random_int(0, 1),
                'created_at' => utc_iso($now - random_int(1, 400) * 3600),
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
        for ($i = 0; $i < 12; $i++) {
            $this->db->insert('club_bans', [
                'club_id' => $clubs[array_rand($clubs)], 'target_type' => $i % 2 === 0 ? 'rider' : 'horse',
                'target_id' => $i % 2 === 0 ? $riders[array_rand($riders)] : $horses[array_rand($horses)],
                'reason' => ['عدم رعایت آیین‌نامه باشگاه', 'آسیب‌دیدگی در جریان مسابقه', 'پذیرش‌نشدن در معاینه دامپزشکی', 'تأخیر در پرداخت هزینه'][array_rand([0, 1, 2, 3])],
                'banned_by' => $riders[0] ?? 1, 'is_active' => $i % 3 === 0 ? 0 : 1, 'is_demo' => 1,
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
