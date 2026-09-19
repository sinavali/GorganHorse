<?php
declare(strict_types=1);

/**
 * File: app/Services/SmsService.php
 *
 * Purpose:
 *   MelyPayamak SMS client and OTP lifecycle. SMS is disabled by default and
 *   enabled by an Admin via settings (Blueprint §14.2, Technical §20). Provides
 *   pattern-based OTP delivery and best-effort notification SMS that never block
 *   the calling action.
 *
 * Dependencies:
 *   - SettingService
 *   - LogService (sms_logs, otp_codes)
 *
 * Conventions:
 *   - OTP codes are stored hashed; verification uses hash_equals.
 *   - Failures are logged but never thrown into the caller's flow.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;

/**
 * Class: SmsService
 *
 * Purpose: Send SMS via MelyPayamak and manage OTP codes.
 */
final class SmsService
{
    private SettingService $settings;
    private LogService $log;
    private Database $appDb;
    /** @var callable|null HTTP POST callback(url, payload): array{status:int,body:string} */
    private $httpClient;

    /**
     * @param SettingService $settings   Settings.
     * @param LogService     $log        Log service.
     * @param Database       $appDb      Main database (rate limit checks).
     * @param callable|null  $httpClient Injectable HTTP client for testing.
     */
    public function __construct(SettingService $settings, LogService $log, Database $appDb, ?callable $httpClient = null)
    {
        $this->settings = $settings;
        $this->log = $log;
        $this->appDb = $appDb;
        $this->httpClient = $httpClient;
    }

    /** @return bool Whether SMS is enabled. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('sms.enabled', false);
    }

    /**
     * Request an OTP code for a phone number.
     *
     * @param string $phone Destination phone (E.164).
     * @param string $ip    Requester IP (for rate limiting).
     * @return void
     * @throws DomainException SMS_DISABLED, OTP_RATE_LIMITED, SMS_SEND_FAILED.
     */
    public function requestOtp(string $phone, string $ip): void
    {
        if (!$this->enabled()) {
            throw new DomainException('SMS_DISABLED', 'SMS service is disabled', 403);
        }
        $phoneCount = (int) $this->appDb->scalar(
            'SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND window_start > :w',
            ['b' => 'otp_phone:' . $phone, 'w' => utc_iso(time() - 3600)]
        );
        $ipCount = (int) $this->appDb->scalar(
            'SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND window_start > :w',
            ['b' => 'otp_ip:' . $ip, 'w' => utc_iso(time() - 3600)]
        );
        if ($phoneCount >= 5 || $ipCount >= 10) {
            throw new DomainException('OTP_RATE_LIMITED', 'OTP request rate limited', 429);
        }
        $this->hitRate('otp_phone:' . $phone);
        $this->hitRate('otp_ip:' . $ip);

        $length = max(4, (int) $this->settings->get('sms.otp_length', 5));
        $ttl = max(60, (int) $this->settings->get('sms.otp_ttl_seconds', 120));
        $maxAttempts = max(1, (int) $this->settings->get('sms.otp_max_attempts', 3));
        $code = random_digits($length);

        $this->log->storeOtp($phone, password_hash($code, PASSWORD_DEFAULT), $ttl, $maxAttempts);

        $sent = $this->sendViaPattern($phone, $code);
        if (!$sent) {
            throw new DomainException('SMS_SEND_FAILED', 'SMS send failed', 502);
        }
    }

    /**
     * Verify an OTP code for a phone number.
     *
     * @param string $phone Destination phone (E.164).
     * @param string $code  Submitted code (Latin/Persian digits accepted).
     * @return bool True on success.
     * @throws DomainException OTP_EXPIRED, OTP_MAX_ATTEMPTS, OTP_INVALID.
     *
     * Side effects: updates attempts/is_used in logs.sqlite.otp_codes.
     */
    public function verifyOtp(string $phone, string $code): bool
    {
        $code = normalize_digits(trim($code));
        $row = $this->logDatabase()->selectOne(
            'SELECT * FROM otp_codes WHERE phone = :p AND is_used = 0 ORDER BY id DESC LIMIT 1',
            ['p' => $phone]
        );
        if ($row === null) {
            throw new DomainException('OTP_INVALID', 'Invalid OTP', 422);
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            throw new DomainException('OTP_EXPIRED', 'OTP expired', 422);
        }
        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            throw new DomainException('OTP_MAX_ATTEMPTS', 'OTP attempts exceeded', 429);
        }
        if (!password_verify($code, (string) $row['code_hash'])) {
            $this->logDatabase()->update('otp_codes', ['attempts' => (int) $row['attempts'] + 1], 'id = :id', ['id' => (int) $row['id']]);
            throw new DomainException('OTP_INVALID', 'Invalid OTP', 422);
        }
        $this->logDatabase()->update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => (int) $row['id']]);
        return true;
    }

    /**
     * Send a notification SMS, honouring the notify_on_* gate.
     *
     * @param string $phone   Destination phone (E.164) or empty.
     * @param string $gateKey Setting gate, e.g. "sms.notify_on_signup".
     * @param string $message Message body.
     * @return bool True when sent, false when skipped/failed.
     */
    public function notify(string $phone, string $gateKey, string $message): bool
    {
        if ($phone === '' || !$this->enabled() || !(bool) $this->settings->get($gateKey, true)) {
            return false;
        }
        return $this->send($phone, $message);
    }

    /**
     * Send a plain SMS message. Never throws (logs failures).
     *
     * @param string $phone   Destination phone (E.164).
     * @param string $message Message body.
     * @return bool True on success.
     *
     * Side effects: records a row in logs.sqlite.sms_logs.
     */
    public function send(string $phone, string $message): bool
    {
        $username = (string) $this->settings->get('sms.username', '');
        $password = (string) $this->settings->get('sms.password', '');
        $sender = (string) $this->settings->get('sms.sender_number', '');
        if (!$this->enabled() || $username === '' || $password === '' || $sender === '') {
            $this->log->sms(['recipient' => $phone, 'body' => $message, 'status' => 'skipped', 'error' => 'SMS not configured']);
            return false;
        }
        $payload = [
            'username' => $username,
            'password' => $password,
            'from' => $sender,
            'to' => $this->localFormat($phone),
            'text' => $message,
            'isflash' => 'false',
        ];
        try {
            $res = $this->post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', $payload);
            $ok = $res['status'] >= 200 && $res['status'] < 300;
            $this->log->sms([
                'recipient' => $phone,
                'body' => $message,
                'status' => $ok ? 'sent' : 'failed',
                'provider_id' => $res['body'] ?? null,
                'error' => $ok ? null : ('HTTP ' . $res['status']),
            ]);
            return $ok;
        } catch (\Throwable $e) {
            $this->log->sms(['recipient' => $phone, 'body' => $message, 'status' => 'failed', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send an OTP using the configured pattern.
     *
     * @param string $phone Destination phone (E.164).
     * @param string $code  OTP code.
     * @return bool True on success.
     */
    private function sendViaPattern(string $phone, string $code): bool
    {
        $username = (string) $this->settings->get('sms.username', '');
        $password = (string) $this->settings->get('sms.password', '');
        $sender = (string) $this->settings->get('sms.sender_number', '');
        $pattern = (string) $this->settings->get('sms.otp_pattern', '');
        if ($username === '' || $password === '' || $sender === '' || $pattern === '') {
            $this->log->sms(['recipient' => $phone, 'template' => 'otp', 'body' => $code, 'status' => 'skipped', 'error' => 'SMS not configured']);
            return false;
        }
        $payload = [
            'username' => $username,
            'password' => $password,
            'from' => $sender,
            'to' => $this->localFormat($phone),
            'text' => $pattern,
            'bodyId' => $pattern,
        ];
        try {
            $res = $this->post('https://rest.payamak-panel.com/api/SendSMS/SendSMSWithPattern', $payload);
            $ok = $res['status'] >= 200 && $res['status'] < 300;
            $this->log->sms([
                'recipient' => $phone,
                'template' => 'otp',
                'body' => $code,
                'status' => $ok ? 'sent' : 'failed',
                'provider_id' => $res['body'] ?? null,
                'error' => $ok ? null : ('HTTP ' . $res['status']),
            ]);
            return $ok;
        } catch (\Throwable $e) {
            $this->log->sms(['recipient' => $phone, 'template' => 'otp', 'body' => $code, 'status' => 'failed', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Convert an E.164 phone to the local 09xxxxxxxxx form expected by MelyPayamak.
     *
     * @param string $phone E.164 phone.
     * @return string Local phone.
     */
    private function localFormat(string $phone): string
    {
        if (str_starts_with($phone, '+98')) {
            return '0' . substr($phone, 3);
        }
        return $phone;
    }

    /**
     * Perform a JSON POST request.
     *
     * @param string $url     Endpoint.
     * @param array  $payload Payload.
     * @return array{status:int,body:string}
     */
    private function post(string $url, array $payload): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($url, $payload);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('SMS HTTP error: ' . $err);
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Record a rate-limit hit for a bucket.
     *
     * @param string $bucket Bucket key.
     * @return void
     */
    private function hitRate(string $bucket): void
    {
        $this->appDb->insert('rate_limits', [
            'bucket' => $bucket,
            'hits' => 1,
            'window_start' => now_utc(),
            'updated_at' => now_utc(),
        ]);
    }

    /**
     * Access the logs database via the LogService's connection.
     *
     * Uses the service container's `logs_db` binding (set during bootstrap),
     * which is the same connection that LogService writes to.
     *
     * @return Database
     */
    private function logDatabase(): Database
    {
        $db = \App\Support\container('logs_db');
        if (!$db instanceof Database) {
            throw new \App\Exceptions\ServerErrorException('SERVICE_UNAVAILABLE', 'Logs database is unavailable');
        }
        return $db;
    }
}
