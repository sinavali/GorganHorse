<?php
declare(strict_types=1);

/**
 * File: app/Services/LogService.php
 *
 * Purpose:
 *   Centralised logging: audit trail, application logs (errors/warnings/slow
 *   queries), login attempts, SMS logs, OTP codes, and the changelog. Structured
 *   rows live in logs.sqlite (P13); raw JSON-lines are also appended under
 *   logs/app/YYYY-MM/YYYY-MM-DD.log and logs/audit/YYYY-MM/YYYY-MM-DD.log.
 *
 * Dependencies:
 *   - Database (logs)
 *   - SettingService (retention windows)
 *   - Container "db" binding (for rate_limits cleanup, which lives in the main DB)
 *
 * Conventions:
 *   - All timestamps UTC ISO-8601.
 *   - Audit action names use dot notation {entity}.{action}.
 *   - Opportunistic cleanup runs 1-in-N requests (P17).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;

/**
 * Class: LogService
 *
 * Purpose: Write structured and file-based logs, and clean up expired rows.
 *
 * Side effects:
 *   - Writes to logs.sqlite tables.
 *   - Writes to rate_limits in the main DB during opportunistic cleanup.
 *   - Appends JSON-line files under logs/.
 */
final class LogService
{
    private Database $db;
    private string $logsDir;
    private ?SettingService $settings;

    /**
     * @param Database            $db       Logs database.
     * @param string              $logsDir  Absolute logs directory.
     * @param SettingService|null $settings Settings for retention.
     */
    public function __construct(Database $db, string $logsDir, ?SettingService $settings = null)
    {
        $this->db = $db;
        $this->logsDir = rtrim($logsDir, '/');
        $this->settings = $settings;
    }

    /**
     * Write an audit entry for a write operation.
     *
     * @param array $entry {
     *   request_id?:string, actor_id?:int, actor_role?:string, impersonated_by?:int,
     *   action:string, target_type?:string, target_id?:int, diff?:array,
     *   ip?:string, ua_hash?:string, result?:string
     * }
     * @return void
     */
    public function audit(array $entry): void
    {
        $now = now_utc();
        $row = [
            'request_id' => $entry['request_id'] ?? null,
            'actor_id' => $entry['actor_id'] ?? null,
            'actor_role' => $entry['actor_role'] ?? null,
            'impersonated_by' => $entry['impersonated_by'] ?? null,
            'action' => (string) $entry['action'],
            'target_type' => $entry['target_type'] ?? null,
            'target_id' => $entry['target_id'] ?? null,
            'diff_json' => isset($entry['diff']) ? json_encode($entry['diff'], JSON_UNESCAPED_UNICODE) : null,
            'ip' => $entry['ip'] ?? null,
            'ua_hash' => $entry['ua_hash'] ?? null,
            'result' => $entry['result'] ?? 'ok',
            'created_at' => $now,
        ];
        $this->db->insert('audit_logs', $row);

        // Raw JSON-lines retention.
        $this->appendJsonLine('audit', [
            'ts' => $now,
            'rid' => $row['request_id'],
            'actor' => ['id' => $row['actor_id'], 'role' => $row['actor_role'], 'ip' => $row['ip'], 'ua_hash' => $row['ua_hash']],
            'action' => $row['action'],
            'target' => ['type' => $row['target_type'], 'id' => $row['target_id']],
            'diff' => $entry['diff'] ?? null,
            'result' => $row['result'],
        ]);
    }

    /**
     * Write an application log entry.
     *
     * @param string $level   debug|info|warning|error.
     * @param string $message Message.
     * @param array  $context Context array.
     * @param int    $durationMs Duration for slow-query logs.
     * @return void
     */
    public function app(string $level, string $message, array $context = [], int $durationMs = 0): void
    {
        $now = now_utc();
        $this->db->insert('app_logs', [
            'request_id' => $context['request_id'] ?? null,
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'duration_ms' => $durationMs ?: null,
            'created_at' => $now,
        ]);
        $this->appendJsonLine('app', [
            'ts' => $now, 'lvl' => $level, 'msg' => $message, 'ctx' => $context, 'ms' => $durationMs ?: null,
        ]);
    }

    /**
     * Record a login attempt (failed/blocked/success).
     *
     * @param array $entry {identifier?:string,user_id?:int,ip?:string,ua_hash?:string,result:string,reason?:string}
     * @return void
     */
    public function loginAttempt(array $entry): void
    {
        $this->db->insert('login_attempts', [
            'identifier' => $entry['identifier'] ?? null,
            'user_id' => $entry['user_id'] ?? null,
            'ip' => $entry['ip'] ?? null,
            'ua_hash' => $entry['ua_hash'] ?? null,
            'result' => (string) ($entry['result'] ?? 'failed'),
            'reason' => $entry['reason'] ?? null,
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Record an outbound SMS.
     *
     * @param array $entry {recipient:string,template?:string,body?:string,status:string,provider_id?:string,error?:string}
     * @return void
     */
    public function sms(array $entry): void
    {
        $this->db->insert('sms_logs', [
            'recipient' => (string) $entry['recipient'],
            'template' => $entry['template'] ?? null,
            'body' => $entry['body'] ?? null,
            'status' => (string) $entry['status'],
            'provider_id' => $entry['provider_id'] ?? null,
            'error' => $entry['error'] ?? null,
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Persist a hashed OTP code.
     *
     * @param string $phone     Destination phone (E.164).
     * @param string $codeHash  Hash of the OTP.
     * @param int    $ttl       Time-to-live seconds.
     * @param int    $maxAttempts Max verification attempts.
     * @return void
     */
    public function storeOtp(string $phone, string $codeHash, int $ttl, int $maxAttempts): void
    {
        $now = now_utc();
        $this->db->insert('otp_codes', [
            'phone' => $phone,
            'code_hash' => $codeHash,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'is_used' => 0,
            'expires_at' => utc_iso(time() + $ttl),
            'created_at' => $now,
        ]);
    }

    /**
     * Write a changelog summary (manager/admin actions for the Admin dashboard).
     *
     * @param array $entry {actor_id?:int,actor_role?:string,action:string,target_type?:string,target_id?:int,summary:string,link?:string}
     * @return void
     */
    public function changelog(array $entry): void
    {
        $this->db->insert('changelog', [
            'actor_id' => $entry['actor_id'] ?? null,
            'actor_role' => $entry['actor_role'] ?? null,
            'action' => (string) $entry['action'],
            'target_type' => $entry['target_type'] ?? null,
            'target_id' => $entry['target_id'] ?? null,
            'summary' => (string) $entry['summary'],
            'link' => $entry['link'] ?? null,
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Opportunistically purge expired log rows (1-in-N per request).
     *
     * Cleans the logs DB (app/audit/sms/login/otp) plus the main DB's
     * rate_limits table, which grows with every hit and has no natural TTL.
     *
     * @param int $probabilityPercent Percent chance to run (default from settings).
     * @return void
     */
    public function maybeCleanup(int $probabilityPercent = 1): void
    {
        if (random_int(1, 100) > $probabilityPercent) { return; }
        $appDays = (int) ($this->settings?->get('logs.app_retention_days', 30) ?? 30);
        $auditDays = (int) ($this->settings?->get('logs.audit_retention_days', 180) ?? 180);
        $smsDays = (int) ($this->settings?->get('logs.sms_retention_days', 90) ?? 90);
        $loginDays = (int) ($this->settings?->get('logs.login_retention_days', 30) ?? 30);
        try {
            $this->db->delete('app_logs', 'created_at < :t', ['t' => utc_iso(time() - $appDays * 86400)]);
            $this->db->delete('audit_logs', 'created_at < :t', ['t' => utc_iso(time() - $auditDays * 86400)]);
            $this->db->delete('sms_logs', 'created_at < :t', ['t' => utc_iso(time() - $smsDays * 86400)]);
            $this->db->delete('login_attempts', 'created_at < :t', ['t' => utc_iso(time() - $loginDays * 86400)]);
            $this->db->delete('otp_codes', 'expires_at < :t', ['t' => now_utc()]);
        } catch (\Throwable) {
            // Cleanup of the logs DB must never break a request.
        }
        // rate_limits lives in the main DB; resolve it lazily from the container
        // so LogService stays decoupled from the main connection at construct time.
        try {
            $main = \App\Support\container('db');
            if ($main instanceof Database) {
                $main->delete('rate_limits', 'window_start < :t', ['t' => utc_iso(time() - 3600)]);
            }
        } catch (\Throwable) {
            // Main DB unavailable during cleanup; harmless.
        }
    }

    /**
     * Append a JSON line to the daily raw log file.
     *
     * @param string $channel app|audit.
     * @param array  $payload Event payload.
     * @return void
     */
    private function appendJsonLine(string $channel, array $payload): void
    {
        $month = gmdate('Y-m');
        $day = gmdate('Y-m-d');
        $dir = $this->logsDir . '/' . $channel . '/' . $month;
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($dir . '/' . $day . '.log', $line, FILE_APPEND);
    }
}