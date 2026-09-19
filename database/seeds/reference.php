<?php
declare(strict_types=1);

/**
 * File: database/seeds/reference.php
 *
 * Purpose:
 *   Idempotent reference-data seeder. Seeds the controlled vocabularies
 *   (horse genders, races, colors), the culture list, the settings registry,
 *   and the six base rade definitions (Blueprint §16, §22).
 *
 * Usage:
 *   Included by the installer and the DemoSeeder. Requires the container to be
 *   available via container() and the following services bound: db, settings,
 *   culture, log.
 *
 * Side effects:
 *   Inserts reference rows (idempotent). Never duplicates on re-run.
 *
 * @package Database\Seeds
 */

/**
 * Seed all reference data.
 *
 * @return array<string,int> Counts of inserted rows per category.
 */
function seed_reference(): array
{
    /** @var \App\Bootstrap\Database $db */
    $db = container('db');
    /** @var \App\Services\SettingService $settings */
    $settings = container('settings');
    /** @var \App\Services\CultureService $culture */
    $culture = container('culture');
    $now = now_utc();
    $counts = ['genders' => 0, 'races' => 0, 'colors' => 0, 'rades' => 0, 'payments' => 0];

    // Cultures.
    $culture->seedCultures();

    // Settings registry.
    $settings->seedDefaults();

    // Horse genders (controlled vocabulary).
    $genders = ['مادیان', 'نریان', 'اخته'];
    foreach ($genders as $i => $name) {
        if ((int) $db->scalar('SELECT COUNT(*) FROM horse_genders WHERE name = :n', ['n' => $name]) === 0) {
            $db->insert('horse_genders', ['name' => $name, 'sort_order' => $i, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $counts['genders']++;
        }
    }

    // Horse races (controlled vocabulary).
    $races = ['تروبرد', 'دوبرد', 'عرب', 'کرد', 'ترکمن', 'آمیخته'];
    foreach ($races as $i => $name) {
        if ((int) $db->scalar('SELECT COUNT(*) FROM horse_races WHERE name = :n', ['n' => $name]) === 0) {
            $db->insert('horse_races', ['name' => $name, 'sort_order' => $i, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $counts['races']++;
        }
    }

    // Horse colors (controlled vocabulary).
    $colors = ['قهوه‌ای', 'سیاه', 'سفید', 'خاکستری', 'کرنگ', 'بلوطی'];
    foreach ($colors as $i => $name) {
        if ((int) $db->scalar('SELECT COUNT(*) FROM horse_colors WHERE name = :n', ['n' => $name]) === 0) {
            $db->insert('horse_colors', ['name' => $name, 'sort_order' => $i, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $counts['colors']++;
        }
    }

    // Rade definitions (six base rades).
    $rades = [
        ['آزاد', 'آزاد', null, null, 0, 1],
        ['رده E', 'rade-e', null, null, 2, 2],
        ['رده D', 'rade-d', null, null, 3, 3],
        ['رده D1', 'rade-d1', null, null, 4, 4],
        ['رده تمرینی', 'rade-training', null, null, 5, 5],
        ['رده مبتدی', 'rade-beginner', null, null, 6, 6],
    ];
    foreach ($rades as $r) {
        if ((int) $db->scalar('SELECT COUNT(*) FROM rades WHERE slug = :s', ['s' => $r[1]]) === 0) {
            $db->insert('rades', [
                'uuid' => uuid4(), 'name' => $r[0], 'slug' => $r[1], 'description' => null,
                'age_min' => $r[2], 'age_max' => $r[3], 'age_enforced' => $r[4], 'sort_order' => $r[5],
                'is_active' => 1, 'is_demo' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $counts['rades']++;
        }
    }

    // Payment templates (five).
    $payments = [
        ['ثبت‌نام آزاد', 'pay-open', 450000],
        ['ثبت‌نام رده E', 'pay-e', 380000],
        ['ثبت‌نام رده D', 'pay-d', 420000],
        ['ثبت‌نام رده D1', 'pay-d1', 500000],
        ['ثبت‌نام رده مبتدی', 'pay-beginner', 300000],
    ];
    foreach ($payments as $p) {
        if ((int) $db->scalar('SELECT COUNT(*) FROM payments WHERE slug = :s', ['s' => $p[1]]) === 0) {
            $db->insert('payments', [
                'uuid' => uuid4(), 'name' => $p[0], 'slug' => $p[1], 'description' => null,
                'amount_irt' => $p[2], 'is_active' => 1, 'is_demo' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $counts['payments']++;
        }
    }

    return $counts;
}
