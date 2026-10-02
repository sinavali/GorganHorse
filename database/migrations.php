<?php
declare(strict_types=1);

/**
 * File: database/migrations.php
 *
 * Purpose: Applies additive schema changes to an existing installation.
 *          `schema.sql` only ever runs with CREATE TABLE IF NOT EXISTS, so new
 *          columns added to a shipped table would never reach an existing
 *          database. This file closes that gap: every migration is idempotent
 *          (it checks the current column set first), so it is safe to run on
 *          every deploy.
 *
 *          Run from the CLI:  php database/migrations.php
 *
 * @package Database
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "migrations.php may only be run from the command line.\n";
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Support/Helpers.php';
require BASE_PATH . '/app/Bootstrap/App.php';
require BASE_PATH . '/app/Bootstrap/Database.php';
require BASE_PATH . '/app/Bootstrap/Bootstrap.php';

use App\Bootstrap\Bootstrap;

/**
 * Statements applied to the main application database. Each entry is run only
 * when its guard reports the change is still missing.
 */
$mainMigrations = [
    'horse_health_records table' => static function ($db): void {
        $exists = (int) $db->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='horse_health_records'"
        );
        if ($exists > 0) {
            return;
        }
        $db->execute(
            'CREATE TABLE IF NOT EXISTS horse_health_records (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                horse_id     INTEGER NOT NULL,
                record_type  TEXT NOT NULL DEFAULT \'vaccination\',
                title        TEXT NOT NULL,
                performed_at TEXT,
                next_due_at  TEXT,
                vet_name     TEXT,
                notes        TEXT,
                cost_irt     INTEGER NOT NULL DEFAULT 0,
                performed_by INTEGER,
                created_at   TEXT NOT NULL,
                updated_at   TEXT NOT NULL,
                FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE CASCADE
            )'
        );
        $db->execute('CREATE INDEX IF NOT EXISTS idx_horse_health_horse ON horse_health_records(horse_id)');
        $db->execute('CREATE INDEX IF NOT EXISTS idx_horse_health_due ON horse_health_records(next_due_at)');
    },
    'competitions.banner_media_id' => static function ($db): void {
        $cols = array_column($db->select('PRAGMA table_info(competitions)'), 'name');
        if (!in_array('banner_media_id', $cols, true)) {
            $db->execute('ALTER TABLE competitions ADD COLUMN banner_media_id INTEGER');
            $db->execute('CREATE INDEX IF NOT EXISTS idx_competitions_banner ON competitions(banner_media_id)');
        }
    },
    'competitions.announcement' => static function ($db): void {
        $cols = array_column($db->select('PRAGMA table_info(competitions)'), 'name');
        if (!in_array('announcement', $cols, true)) {
            $db->execute("ALTER TABLE competitions ADD COLUMN announcement TEXT");
        }
        if (!in_array('announcement_required', $cols, true)) {
            $db->execute('ALTER TABLE competitions ADD COLUMN announcement_required INTEGER NOT NULL DEFAULT 0');
        }
    },
];

$container = Bootstrap::container();
$db = $container->get('db');

echo "Applying migrations to app.sqlite\n";
foreach ($mainMigrations as $label => $migration) {
    try {
        $migration($db);
        echo "  [ok] $label\n";
    } catch (\Throwable $e) {
        echo "  [!!] $label — " . $e->getMessage() . "\n";
        exit(1);
    }
}

// New settings rows introduced after the initial install.
try {
    $n = $container->get('settings')->seedDefaults();
    echo "  [ok] settings defaults (added $n)\n";
} catch (\Throwable $e) {
    echo "  [--] settings seed skipped: " . $e->getMessage() . "\n";
}

echo "Migrations complete.\n";