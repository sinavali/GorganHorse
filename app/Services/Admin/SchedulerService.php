<?php
declare(strict_types=1);

/**
 * File: app/Services/Admin/SchedulerService.php
 *
 * Purpose: Runs the panel's recurring background work on demand. The panel has
 *          no daemon, so `php cron.php` (or an external scheduler calling
 *          POST /panel/cron) invokes these jobs instead:
 *
 *            1. Deadline alerts — notify staff about competitions whose
 *               registration window closes soon or that are about to start.
 *            2. Auto rider verification — approve riders that match the
 *               federation's rules so staff do not approve them one by one.
 *            3. Automatic backups — nightly zip when enabled in settings.
 *            4. Stale session cleanup — removes expired session rows.
 *
 *          Every job is idempotent: a per-day marker in the `settings` table
 *          prevents the same alert firing twice in one day, so it is safe to
 *          run the cron every five minutes.
 *
 * @package App\Services\Admin
 */

namespace App\Services\Admin;

use App\Bootstrap\Database;
use App\Services\LogService;
use App\Services\NotificationService;
use App\Services\SettingService;

/**
 * Class: SchedulerService
 * Purpose: Periodic maintenance and alert jobs.
 */
final class SchedulerService
{
    /** @var Database */
    private $db;

    /** @var SettingService */
    private $settings;

    /** @var NotificationService */
    private $notifications;

    /** @var LogService */
    private $log;

    /**
     * @param Database            $db            Main connection.
     * @param SettingService      $settings      Settings store.
     * @param NotificationService $notifications Notification fan-out.
     * @param LogService          $log           Logger.
     */
    public function __construct(
        Database $db,
        SettingService $settings,
        NotificationService $notifications,
        LogService $log
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->notifications = $notifications;
        $this->log = $log;
    }

    /**
     * Run every job and report what each one did.
     *
     * @return array<string,array{ran:bool,count:int,note?:string}>
     */
    public function runAll(): array
    {
        $jobs = [
            'deadline_alerts' => fn (): array => $this->deadlineAlerts(),
            'auto_verification' => fn (): array => $this->autoVerifyRiders(),
            'auto_backup' => fn (): array => $this->autoBackup(),
            'session_cleanup' => fn (): array => $this->cleanSessions(),
        ];
        $out = [];
        foreach ($jobs as $key => $job) {
            try {
                $out[$key] = $job();
            } catch (\Throwable $e) {
                /* One failing job must never abort the rest: a broken backup
                   used to stop session cleanup from ever running. */
                $this->log->app('error', 'scheduler: job failed', ['job' => $key, 'error' => $e->getMessage()]);
                $out[$key] = ['ran' => false, 'count' => 0, 'note' => 'failed'];
            }
        }
        return $out;
    }

    /**
     * Warn staff about competitions closing registration or starting soon.
     *
     * @return array{ran:bool,count:int}
     */
    public function deadlineAlerts(): array
    {
        $days = (int) $this->settings->get('scheduler.deadline_alert_days', 2);
        if ($days <= 0) { return ['ran' => false, 'count' => 0, 'note' => 'disabled']; }

        $today = gmdate('Y-m-d');
        $marker = 'scheduler.deadline_alerts.' . $today;
        if ((string) $this->settings->get($marker, '') !== '') {
            return ['ran' => false, 'count' => 0, 'note' => 'already_sent_today'];
        }

        $soon = utc_iso(time() + ($days * 86400));
        $now = now_utc();

        $closing = $this->db->select(
            "SELECT c.id, c.title, c.slug, c.end_registration_at
             FROM competitions c
             WHERE c.status = 'open' AND c.registration_paused = 0
               AND c.end_registration_at BETWEEN :n AND :s
             ORDER BY c.end_registration_at ASC",
            ['n' => $now, 's' => $soon]
        );
        $closing = is_array($closing) ? $closing : [];

        $starting = $this->db->select(
            "SELECT c.id, c.title, c.slug, c.start_at
             FROM competitions c
             WHERE c.status IN ('closed','running')
               AND c.start_at BETWEEN :n AND :s
             ORDER BY c.start_at ASC",
            ['n' => $now, 's' => $soon]
        );
        $starting = is_array($starting) ? $starting : [];

        $count = 0;
        foreach ($closing as $c) {
            $this->notifications->notifyStaff(
                'competition.registration_closing',
                'پایان ثبت‌نام «' . $c['title'] . '»',
                'مهلت ثبت‌نام این مسابقه تا ' . $c['end_registration_at'] . ' است.',
                '/competitions/' . (int) $c['id']
            );
            $count++;
        }
        foreach ($starting as $c) {
            $this->notifications->notifyStaff(
                'competition.starting',
                'به‌زودی: «' . $c['title'] . '»',
                'این مسابقه در تاریخ ' . $c['start_at'] . ' برگزار می‌شود.',
                '/competitions/' . (int) $c['id']
            );
            $count++;
        }

        $this->settings->set($marker, (string) time());
        $this->log->app('info', 'scheduler: deadline alerts', ['sent' => $count]);
        return ['ran' => true, 'count' => $count];
    }

    /**
     * Auto-approve riders that satisfy the federation's verification rules.
     *
     * A rider qualifies when they supplied a valid national id and phone and
     * their account is older than `scheduler.auto_verify_min_age_hours`.
     *
     * @return array{ran:bool,count:int}
     */
    public function autoVerifyRiders(): array
    {
        if ((string) $this->settings->get('scheduler.auto_verify', '0') !== '1') {
            return ['ran' => false, 'count' => 0, 'note' => 'disabled'];
        }
        $minAge = max(0, (int) $this->settings->get('scheduler.auto_verify_min_age_hours', 24));
        $since = utc_iso(time() - ($minAge * 3600));

        $rows = $this->db->select(
            "SELECT id FROM users
             WHERE role = 'rider'
               AND verification_status = 'pending'
               AND national_id IS NOT NULL AND national_id <> ''
               AND phone IS NOT NULL AND phone <> ''
               AND created_at <= :since
             LIMIT 100",
            ['since' => $since]
        );
        $rows = is_array($rows) ? $rows : [];

        $users = container('users');
        $count = 0;
        foreach ($rows as $r) {
            try {
                $users->verify((int) $r['id'], ['id' => 0, 'role' => 'system']);
                $count++;
            } catch (\Throwable $e) {
                $this->log->app('error', 'scheduler: auto verification failed', ['error' => $e->getMessage()]);
            }
        }
        if ($count > 0) {
            $this->log->app('info', 'scheduler: riders auto-verified', ['count' => $count]);
        }
        return ['ran' => true, 'count' => $count];
    }

    /**
     * Take a nightly backup when the setting is on and today's is missing.
     *
     * @return array{ran:bool,count:int,note?:string}
     */
    public function autoBackup(): array
    {
        if ((string) $this->settings->get('scheduler.auto_backup', '0') !== '1') {
            return ['ran' => false, 'count' => 0, 'note' => 'disabled'];
        }
        $today = gmdate('Y-m-d');
        $marker = 'scheduler.auto_backup.' . $today;
        if ((string) $this->settings->get($marker, '') !== '') {
            return ['ran' => false, 'count' => 0, 'note' => 'already_done_today'];
        }
        $backup = container('backup');
        try {
            $backup->create('auto-' . $today, null);
            $this->settings->set($marker, (string) time());
            $this->log->app('info', 'scheduler: automatic backup created', ['day' => $today]);
            return ['ran' => true, 'count' => 1];
        } catch (\Throwable $e) {
            $this->log->app('error', 'scheduler: automatic backup failed', ['error' => $e->getMessage()]);
            return ['ran' => false, 'count' => 0, 'note' => 'failed'];
        }
    }

    /**
     * Delete expired sessions and stale rate-limit buckets.
     *
     * @return array{ran:bool,count:int}
     */
    public function cleanSessions(): array
    {
        $now = now_utc();
        // execute() returns the affected row count. scalar() on a DELETE always
        // fetched false, so this job used to report "0 deleted" even though it
        // was deleting rows.
        $sessions = $this->db->execute('DELETE FROM sessions WHERE expires_at < :n', ['n' => $now]);
        $tokens = $this->db->execute('DELETE FROM api_tokens WHERE expires_at < :n', ['n' => $now]);
        $temporary = $this->sweepTemporaryFiles();
        // Refresh planner statistics after the day's data churn.
        try {
            $this->db->exec('PRAGMA optimize');
        } catch (\Throwable) {
            // Statistics refresh is best-effort.
        }
        return ['ran' => true, 'count' => $sessions + $tokens + $temporary];
    }

    /**
     * Delete generated CSV/report files older than an hour.
     *
     * Downloads now delete their file when the response finishes, and this
     * sweep catches anything left behind by an interrupted download, an
     * expired report token or a crash, so cache/ does not grow forever.
     *
     * @return int Number of files removed.
     */
    private function sweepTemporaryFiles(): int
    {
        $removed = 0;
        $cutoff = time() - 3600;
        $patterns = [
            BASE_PATH . '/cache/exports/*.csv',
            BASE_PATH . '/cache/exports/*.meta',
            BASE_PATH . '/cache/audit-log-*.csv',
            BASE_PATH . '/cache/sms-delivery-*.csv',
            BASE_PATH . '/cache/rider-ranking-*.csv',
            BASE_PATH . '/cache/horses-*.csv',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && (int) @filemtime($file) < $cutoff) {
                    @unlink($file);
                    $removed++;
                }
            }
        }
        return $removed;
    }
}