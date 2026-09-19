<?php
declare(strict_types=1);

/**
 * File: app/Services/AuthService.php
 *
 * Purpose:
 *   Authentication: password login, OTP login, signup, sessions, rate limiting,
 *   and captcha. Sessions are DB-backed with a fixed 90-day lifetime, UA-bound,
 *   and rotated on privilege change (P08, Technical §11).
 *
 * Dependencies:
 *   - Database (main + logs)
 *   - SettingService
 *   - LogService
 *   - SmsService
 *
 * Conventions:
 *   - Password hashing: password_hash(PASSWORD_DEFAULT).
 *   - Usernames for riders are unique 6-8 digit numbers.
 *   - No email sending anywhere.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\ValidationException;
use App\Support\Vendor\PhoneValidator;

/**
 * Class: AuthService
 *
 * Purpose: Own the authentication lifecycle and session store.
 */
final class AuthService
{
    private Database $db;
    private Database $logsDb;
    private SettingService $settings;
    private LogService $log;
    private SmsService $sms;

    /**
     * @param Database       $db       Main database.
     * @param Database       $logsDb   Logs database.
     * @param SettingService $settings Settings.
     * @param LogService     $log      Log service.
     * @param SmsService     $sms      SMS service.
     */
    public function __construct(Database $db, Database $logsDb, SettingService $settings, LogService $log, SmsService $sms)
    {
        $this->db = $db;
        $this->logsDb = $logsDb;
        $this->settings = $settings;
        $this->log = $log;
        $this->sms = $sms;
    }

    /**
     * Attempt a password login.
     *
     * @param string $identifier Username or phone.
     * @param string $password   Password.
     * @param string $ip         Client IP.
     * @param string $uaHash     UA hash.
     * @return array{user:array,session_id:string,csrf:string,pending_verification:bool,role:string}
     * @throws DomainException AUTH_INVALID | AUTH_RATE_LIMITED | USER_DISABLED_FULL.
     */
    public function login(string $identifier, string $password, string $ip, string $uaHash): array
    {
        $identifier = trim($identifier);
        $this->assertLoginRate($identifier, $ip);

        $phone = PhoneValidator::toE164($identifier);
        $user = null;
        if ($phone !== null) {
            $user = $this->db->selectOne('SELECT * FROM users WHERE phone = :p', ['p' => $phone]);
        }
        if ($user === null) {
            $user = $this->db->selectOne('SELECT * FROM users WHERE username = :u', ['u' => $identifier]);
        }
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            $this->recordLoginAttempt($identifier, $user['id'] ?? null, $ip, $uaHash, 'failed', 'AUTH_INVALID');
            throw new DomainException('AUTH_INVALID', 'Invalid credentials', 401);
        }

        if (($user['disable_state'] ?? 'none') === 'full') {
            $this->recordLoginAttempt($identifier, (int) $user['id'], $ip, $uaHash, 'blocked', 'USER_DISABLED_FULL');
            throw new DomainException('USER_DISABLED_FULL', 'Account banned', 403);
        }

        // Rehash when the algorithm cost changes.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => now_utc(),
            ], 'id = :id', ['id' => (int) $user['id']]);
        }

        $pending = ($user['role'] === 'rider' && ($user['verification_status'] ?? '') === 'pending');
        $session = $this->createSession((int) $user['id'], (string) $user['role'], $ip, $uaHash);

        $this->db->update('users', [
            'last_login_at' => now_utc(),
            'last_login_ip' => $ip,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => (int) $user['id']]);

        $this->recordLoginAttempt($identifier, (int) $user['id'], $ip, $uaHash, 'success', null);
        $this->log->audit([
            'actor_id' => (int) $user['id'], 'actor_role' => $user['role'],
            'action' => 'auth.login', 'target_type' => 'user', 'target_id' => (int) $user['id'],
            'ip' => $ip, 'ua_hash' => $uaHash, 'result' => 'ok',
        ]);

        return [
            'user' => $user,
            'session_id' => $session['id'],
            'csrf' => $session['csrf'],
            'pending_verification' => $pending,
            'role' => (string) $user['role'],
        ];
    }

    /**
     * Request an OTP for login (requires SMS enabled).
     *
     * Unknown phone numbers do **not** trigger an SMS (anti-enumeration), but
     * the response is intentionally identical to the known-user path.
     *
     * @param string $phone Phone (any format).
     * @param string $ip    Client IP.
     * @return string E.164 phone.
     * @throws DomainException SMS_DISABLED, USER_PHONE_INVALID, OTP_RATE_LIMITED, SMS_SEND_FAILED.
     */
    public function requestOtp(string $phone, string $ip): string
    {
        if (!$this->sms->enabled()) {
            throw new DomainException('SMS_DISABLED', 'SMS service is disabled', 403);
        }
        $e164 = PhoneValidator::toE164($phone);
        if ($e164 === null) {
            throw new DomainException('USER_PHONE_INVALID', 'Invalid phone', 422);
        }

        // Anti-enumeration: silently succeed for unknown phones, but never
        // actually send an SMS (so credits can't be burned by enumeration).
        $user = $this->db->selectOne('SELECT * FROM users WHERE phone = :p', ['p' => $e164]);
        if ($user === null) {
            return $e164;
        }
        if (($user['disable_state'] ?? 'none') === 'full') {
            throw new DomainException('USER_DISABLED_FULL', 'Account banned', 403);
        }
        $this->sms->requestOtp($e164, $ip);
        return $e164;
    }

    /**
     * Verify an OTP and issue a session.
     *
     * @param string $phone   Phone (any format).
     * @param string $code    OTP code.
     * @param string $ip      Client IP.
     * @param string $uaHash  UA hash.
     * @return array{user:array,session_id:string,csrf:string,pending_verification:bool,role:string}
     */
    public function verifyOtp(string $phone, string $code, string $ip, string $uaHash): array
    {
        if (!$this->sms->enabled()) {
            throw new DomainException('SMS_DISABLED', 'SMS service is disabled', 403);
        }
        $e164 = PhoneValidator::toE164($phone);
        if ($e164 === null) {
            throw new DomainException('USER_PHONE_INVALID', 'Invalid phone', 422);
        }
        $this->sms->verifyOtp($e164, $code);
        $user = $this->db->selectOne('SELECT * FROM users WHERE phone = :p', ['p' => $e164]);
        if ($user === null) {
            throw new DomainException('AUTH_INVALID', 'Invalid credentials', 401);
        }
        if (($user['disable_state'] ?? 'none') === 'full') {
            throw new DomainException('USER_DISABLED_FULL', 'Account banned', 403);
        }
        $pending = ($user['role'] === 'rider' && ($user['verification_status'] ?? '') === 'pending');
        $session = $this->createSession((int) $user['id'], (string) $user['role'], $ip, $uaHash);
        $this->db->update('users', ['last_login_at' => now_utc(), 'last_login_ip' => $ip], 'id = :id', ['id' => (int) $user['id']]);
        $this->log->audit([
            'actor_id' => (int) $user['id'], 'actor_role' => $user['role'],
            'action' => 'auth.login.otp', 'target_type' => 'user', 'target_id' => (int) $user['id'],
            'ip' => $ip, 'ua_hash' => $uaHash, 'result' => 'ok',
        ]);
        return [
            'user' => $user, 'session_id' => $session['id'], 'csrf' => $session['csrf'],
            'pending_verification' => $pending, 'role' => (string) $user['role'],
        ];
    }

    /**
     * Register a new rider account (verification_status = pending).
     *
     * @param array $input {first_name,last_name,phone,password,password_confirm,national_id}
     * @return array{user_id:int,username:string,auto_verify_at:string}
     */
    public function signup(array $input): array
    {
        if (!(bool) $this->settings->get('auth.allow_signup', true)) {
            throw new DomainException('FORBIDDEN', 'Signup is disabled', 403);
        }
        $errors = new ValidationException('Validation failed');

        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $phone = PhoneValidator::toE164((string) ($input['phone'] ?? ''));
        $nationalId = normalize_digits(trim((string) ($input['national_id'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');

        if ($firstName === '') { $errors->add('first_name', 'نام الزامی است', 'VALIDATION_FAILED'); }
        if ($lastName === '') { $errors->add('last_name', 'نام خانوادگی الزامی است', 'VALIDATION_FAILED'); }
        if ($phone === null) { $errors->add('phone', 'شماره موبایل نامعتبر است', 'USER_PHONE_INVALID'); }
        if (!PhoneValidator::isValidNationalId($nationalId)) { $errors->add('national_id', 'کد ملی نامعتبر است', 'USER_NATIONAL_ID_INVALID'); }
        $this->assertPassword($password, $errors);
        if ($password !== $confirm) { $errors->add('password_confirm', 'تکرار رمز عبور مطابقت ندارد', 'VALIDATION_FAILED'); }
        if ($errors->errors() !== []) { throw $errors; }

        if ($this->db->scalar('SELECT COUNT(*) FROM users WHERE phone = :p', ['p' => $phone]) > 0) {
            throw new DomainException('USER_PHONE_TAKEN', 'Phone already registered', 409, 'phone');
        }

        $autoHours = (int) $this->settings->get('auth.auto_verify_hours', 48);
        $now = now_utc();
        $autoVerifyAt = utc_iso(time() + $autoHours * 3600);

        return $this->db->transaction(function () use ($firstName, $lastName, $phone, $nationalId, $password, $now, $autoVerifyAt): array {
            $username = $this->generateRiderUsername();
            $userId = $this->db->insert('users', [
                'uuid' => uuid4(),
                'role' => 'rider',
                'username' => $username,
                'phone' => $phone,
                'email' => null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'national_id' => $nationalId,
                'avatar_media_id' => null,
                'disable_state' => 'none',
                'disable_reason' => null,
                'verification_status' => 'pending',
                'auto_verify_at' => $autoVerifyAt,
                'last_login_at' => null,
                'last_login_ip' => null,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->insert('rider_profiles', [
                'user_id' => $userId,
                'my_share_code' => random_digits((int) $this->settings->get('horses.share_code_length', 6)),
                'experience_level' => 'active',
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->log->changelog([
                'actor_id' => $userId, 'actor_role' => 'rider', 'action' => 'user.signup',
                'target_type' => 'user', 'target_id' => $userId,
                'summary' => 'ثبت‌نام سوارکار جدید: ' . $firstName . ' ' . $lastName,
                'link' => '/panel/users/' . $userId,
            ]);
            return ['user_id' => $userId, 'username' => $username, 'auto_verify_at' => $autoVerifyAt];
        });
    }

    /**
     * Create a new authenticated session row.
     *
     * @param int      $userId   User id.
     * @param string   $role     Role.
     * @param string   $ip       Client IP.
     * @param string   $uaHash   UA hash.
     * @param int|null $impersonatedBy Admin id when impersonating.
     * @return array{id:string,csrf:string,expires_at:string}
     */
    public function createSession(int $userId, string $role, string $ip, string $uaHash, ?int $impersonatedBy = null): array
    {
        $id = random_token(32);
        $csrf = random_token(32);
        $days = max(1, (int) $this->settings->get('auth.session_absolute_days', 90));
        $now = now_utc();
        $expires = utc_iso(time() + $days * 86400);
        $this->db->insert('sessions', [
            'id' => $id,
            'user_id' => $userId,
            'role' => $role,
            'impersonated_by' => $impersonatedBy,
            'ip' => $ip,
            'ua_hash' => $uaHash,
            'mismatch_count' => 0,
            'payload' => json_encode(['csrf_token' => $csrf], JSON_UNESCAPED_UNICODE),
            'last_activity_at' => $now,
            'created_at' => $now,
            'expires_at' => $expires,
            'is_demo' => 0,
        ]);
        return ['id' => $id, 'csrf' => $csrf, 'expires_at' => $expires];
    }

    /**
     * Load and validate a session by id (enforces UA binding + expiry).
     */
    public function resolveSession(string $sessionId, string $uaHash, string $ip): ?array
    {
        if ($sessionId === '') { return null; }
        $session = $this->db->selectOne('SELECT * FROM sessions WHERE id = :id', ['id' => $sessionId]);
        if ($session === null) { return null; }
        if (strtotime((string) $session['expires_at']) < time()) {
            $this->db->delete('sessions', 'id = :id', ['id' => $sessionId]);
            return null;
        }
        if (!hash_equals((string) $session['ua_hash'], $uaHash)) {
            $this->db->delete('sessions', 'id = :id', ['id' => $sessionId]);
            return null;
        }
        if (($session['ip'] ?? '') !== $ip && $session['ip'] !== null) {
            $count = (int) $session['mismatch_count'] + 1;
            if ($count >= 2) {
                $this->db->delete('sessions', 'id = :id', ['id' => $sessionId]);
                $this->log->app('warning', 'Session killed after repeated IP change', ['session_id' => $sessionId]);
                return null;
            }
            $this->db->update('sessions', ['mismatch_count' => $count, 'ip' => $ip], 'id = :id', ['id' => $sessionId]);
        }
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => (int) $session['user_id']]);
        if ($user === null) { return null; }

        $this->db->update('sessions', ['last_activity_at' => now_utc()], 'id = :id', ['id' => $sessionId]);
        $payload = json_decode((string) $session['payload'], true) ?: [];
        return [
            'session' => $session,
            'user' => $user,
            'csrf' => (string) ($payload['csrf_token'] ?? ''),
        ];
    }

    /** Rotate a session's id and CSRF token (login/privilege change). */
    public function rotateSession(string $sessionId): ?array
    {
        $session = $this->db->selectOne('SELECT * FROM sessions WHERE id = :id', ['id' => $sessionId]);
        if ($session === null) { return null; }
        $newId = random_token(32);
        $csrf = random_token(32);
        $this->db->update('sessions', [
            'id' => $newId,
            'payload' => json_encode(['csrf_token' => $csrf], JSON_UNESCAPED_UNICODE),
            'last_activity_at' => now_utc(),
        ], 'id = :id', ['id' => $sessionId]);
        return ['id' => $newId, 'csrf' => $csrf];
    }

    /** Destroy a session (logout). */
    public function logout(string $sessionId): void
    {
        $this->db->delete('sessions', 'id = :id', ['id' => $sessionId]);
    }

    /** Revoke all sessions for a user, optionally except one. */
    public function revokeAllSessions(int $userId, ?string $exceptId = null): int
    {
        if ($exceptId !== null) {
            return $this->db->execute('DELETE FROM sessions WHERE user_id = :u AND id != :e', ['u' => $userId, 'e' => $exceptId]);
        }
        return $this->db->execute('DELETE FROM sessions WHERE user_id = :u', ['u' => $userId]);
    }

    /** List active sessions for a user. */
    public function sessionsFor(int $userId): array
    {
        return $this->db->select('SELECT * FROM sessions WHERE user_id = :u ORDER BY last_activity_at DESC', ['u' => $userId]);
    }

    /** Revoke a single session by id, scoped to a user. */
    public function revokeSession(int $userId, string $sessionId): void
    {
        $this->db->delete('sessions', 'id = :id AND user_id = :u', ['id' => $sessionId, 'u' => $userId]);
    }

    /** Change a user's password (also rotates sessions). */
    public function setPassword(int $userId, string $newPassword, ?string $exceptSessionId = null): void
    {
        $errors = new ValidationException('Weak password', 'password', 'USER_PASSWORD_WEAK');
        $this->assertPassword($newPassword, $errors);
        if ($errors->errors() !== []) { throw $errors; }
        $this->db->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $userId]);
        $this->revokeAllSessions($userId, $exceptSessionId);
    }

    /** Validate a password against the configured policy. */
    private function assertPassword(string $password, ValidationException $errors): void
    {
        $min = (int) $this->settings->get('auth.password_min_length', 8);
        if (mb_strlen($password) < $min) {
            $errors->add('password', 'رمز عبور باید حداقل ' . $min . ' نویسه باشد', 'USER_PASSWORD_WEAK');
            return;
        }
        if ((bool) $this->settings->get('auth.password_require_upper', false) && preg_match('/[A-Z]/', $password) !== 1) {
            $errors->add('password', 'رمز عبور باید حرف بزرگ داشته باشد', 'USER_PASSWORD_WEAK');
        }
        if ((bool) $this->settings->get('auth.password_require_digit', false) && preg_match('/\d/', $password) !== 1) {
            $errors->add('password', 'رمز عبور باید رقم داشته باشد', 'USER_PASSWORD_WEAK');
        }
        if ((bool) $this->settings->get('auth.password_require_symbol', false) && preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            $errors->add('password', 'رمز عبور باید نماد داشته باشد', 'USER_PASSWORD_WEAK');
        }
    }

    /** Enforce login rate limiting. */
    private function assertLoginRate(string $identifier, string $ip): void
    {
        $window = (int) $this->settings->get('auth.rate_login_window_seconds', 300);
        $cap = (int) $this->settings->get('auth.rate_login_per_window', 5);
        $since = utc_iso(time() - $window);
        $failures = (int) $this->logsDb->scalar(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND identifier = :id AND result = 'failed' AND created_at > :since",
            ['ip' => $ip, 'id' => $identifier, 'since' => $since]
        );
        if ($failures >= $cap) {
            throw new DomainException('AUTH_RATE_LIMITED', 'Too many attempts', 429);
        }
        $ipCap = (int) $this->settings->get('auth.rate_ip_hourly_cap', 20);
        $ipFailures = (int) $this->logsDb->scalar(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND result != 'success' AND created_at > :since",
            ['ip' => $ip, 'since' => utc_iso(time() - 3600)]
        );
        if ($ipFailures >= $ipCap) {
            throw new DomainException('AUTH_RATE_LIMITED', 'Too many attempts', 429);
        }
    }

    /** Record a login attempt row. */
    private function recordLoginAttempt(string $identifier, ?int $userId, string $ip, string $uaHash, string $result, ?string $reason): void
    {
        $this->log->loginAttempt([
            'identifier' => $identifier,
            'user_id' => $userId,
            'ip' => $ip,
            'ua_hash' => $uaHash,
            'result' => $result,
            'reason' => $reason,
        ]);
    }

    /** Generate a unique 6-8 digit rider username. */
    private function generateRiderUsername(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $length = random_int(6, 8);
            $min = (int) str_pad('1', $length, '0');
            $max = (int) str_pad('9', $length, '9');
            $candidate = (string) random_int($min, $max);
            if ($this->db->scalar('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $candidate]) === 0) {
                return $candidate;
            }
        }
        return random_digits(8);
    }
}