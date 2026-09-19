<?php
declare(strict_types=1);

/**
 * File: app/Services/UserService.php
 *
 * Purpose:
 *   User lifecycle: unified CRUD for admins/managers/riders/clubs, verification
 *   (verify/reject/auto-verify), disable states, impersonation tagging, password
 *   reset by managers, and scoped listings (Blueprint §4, §8.2.7; Technical §11).
 *
 * Dependencies:
 *   Database, SettingService, LogService, MediaService, NotificationService.
 *
 * Conventions:
 *   - All timestamps UTC ISO-8601.
 *   - Riders get a rider_profiles row; clubs get a clubs row.
 *   - Hard delete cascades (P02).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Support\Vendor\PhoneValidator;

/**
 * Class: UserService
 *
 * Purpose: Manage user accounts, verification, disable state, and scoping.
 */
final class UserService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private MediaService $media;
    private ?NotificationService $notifications;

    /**
     * @param Database                  $db            Main database.
     * @param SettingService            $settings      Settings.
     * @param LogService                $log           Log service.
     * @param MediaService              $media         Media service.
     * @param NotificationService|null  $notifications Notification service.
     */
    public function __construct(Database $db, SettingService $settings, LogService $log, MediaService $media, ?NotificationService $notifications = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
        $this->media = $media;
        $this->notifications = $notifications;
    }

    /**
     * List users with filters, scoped by actor role.
     *
     * @param array $filters {role?,status?,search?,scope_user_id?}
     * @param array $actor   {id,role}
     * @param int   $page    Page (1-based).
     * @param int   $perPage Page size.
     * @return array{rows:array<int,array>,total:int,page:int,per_page:int}
     */
    public function list(array $filters, array $actor, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        $role = (string) ($filters['role'] ?? '');
        if ($role !== '' && $role !== 'all') {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        } elseif ($actor['role'] === 'manager') {
            $where[] = "u.role IN ('rider','club')";
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && $status !== 'all') {
            if ($status === 'disabled-limited') { $where[] = "u.disable_state = 'limited'"; }
            elseif ($status === 'disabled-full') { $where[] = "u.disable_state = 'full'"; }
            else { $where[] = 'u.verification_status = :vstatus'; $params['vstatus'] = $status; }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.username LIKE :q OR u.phone LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q OR u.national_id LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM users u WHERE ' . $whereSql, $params);
        $page = max(1, $page);
        $perPage = max(1, min(250, $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            'SELECT u.id, u.uuid, u.role, u.username, u.phone, u.email, u.first_name, u.last_name, u.national_id,
                    u.disable_state, u.verification_status, u.auto_verify_at, u.last_login_at, u.created_at
             FROM users u WHERE ' . $whereSql . ' ORDER BY u.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch a user with joined profile bits.
     *
     * @param int $id User id.
     * @return array
     * @throws NotFoundException USER_NOT_FOUND.
     */
    public function get(int $id): array
    {
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user === null) {
            throw new NotFoundException('User not found', 'USER_NOT_FOUND');
        }
        unset($user['password_hash']);
        if ($user['role'] === 'rider') {
            $user['rider_profile'] = $this->db->selectOne('SELECT * FROM rider_profiles WHERE user_id = :u', ['u' => $id]);
        }
        if ($user['role'] === 'club') {
            $user['club'] = $this->db->selectOne('SELECT * FROM clubs WHERE user_id = :u', ['u' => $id]);
        }
        return $user;
    }

    /**
     * Create a user (admin/manager/rider/club).
     *
     * @param array $input {role,username,phone,email,password,first_name,last_name,national_id,disable_state?}
     * @param array $actor {id,role}
     * @return array{id:int,uuid:string}
     * @throws ValidationException|DomainException
     *
     * Side effects: inserts users (+ rider_profiles / clubs), changelog + audit.
     * Transaction: yes.
     */
    public function create(array $input, array $actor): array
    {
        $role = (string) ($input['role'] ?? 'rider');
        if (!in_array($role, ['admin', 'manager', 'rider', 'club'], true)) {
            throw new ValidationException('Invalid role', 'role', 'VALIDATION_FAILED');
        }
        if ($actor['role'] === 'manager' && !in_array($role, ['rider', 'club'], true)) {
            throw new ForbiddenException('Managers may only create riders and clubs', 'FORBIDDEN');
        }
        $errors = new ValidationException('Validation failed');
        $username = trim((string) ($input['username'] ?? ''));
        if ($username === '') {
            if ($role === 'rider') { $username = (string) random_int(100000, 99999999); }
            else { $errors->add('username', 'نام کاربری الزامی است', 'VALIDATION_FAILED'); }
        }
        if ($username !== '' && !preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $username)) {
            $errors->add('username', 'نام کاربری نامعتبر است', 'VALIDATION_FAILED');
        }
        $phone = isset($input['phone']) && $input['phone'] !== '' ? PhoneValidator::toE164((string) $input['phone']) : null;
        $nationalId = isset($input['national_id']) && $input['national_id'] !== '' ? normalize_digits((string) $input['national_id']) : null;
        $password = (string) ($input['password'] ?? '');
        if ($nationalId !== null && !PhoneValidator::isValidNationalId($nationalId)) {
            $errors->add('national_id', 'کد ملی نامعتبر است', 'USER_NATIONAL_ID_INVALID');
        }
        if ($errors->errors() !== []) { throw $errors; }

        if ($this->db->scalar('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $username]) > 0) {
            throw new DomainException('USER_USERNAME_TAKEN', 'Username already registered', 409, 'username');
        }
        if ($phone !== null && $this->db->scalar('SELECT COUNT(*) FROM users WHERE phone = :p', ['p' => $phone]) > 0) {
            throw new DomainException('USER_PHONE_TAKEN', 'Phone already registered', 409, 'phone');
        }
        if ($password === '') {
            $password = random_token(8);
        }
        $now = now_utc();

        return $this->db->transaction(function () use ($role, $username, $phone, $nationalId, $password, $input, $now, $actor): array {
            $uuid = uuid4();
            $userId = $this->db->insert('users', [
                'uuid' => $uuid,
                'role' => $role,
                'username' => $username,
                'phone' => $phone,
                'email' => $input['email'] ?? null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'national_id' => $nationalId,
                'avatar_media_id' => null,
                'disable_state' => $input['disable_state'] ?? 'none',
                'disable_reason' => null,
                'verification_status' => $role === 'rider' ? 'verified' : 'verified',
                'auto_verify_at' => null,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($role === 'rider') {
                $this->db->insert('rider_profiles', [
                    'user_id' => $userId,
                    'my_share_code' => random_digits((int) $this->settings->get('horses.share_code_length', 6)),
                    'experience_level' => 'active',
                    'is_demo' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($role === 'club') {
                $this->db->insert('clubs', [
                    'uuid' => uuid4(),
                    'user_id' => $userId,
                    'name' => ($input['first_name'] ?? 'باشگاه') . ' ' . ($input['last_name'] ?? ''),
                    'slug' => slugify((string) ($input['first_name'] ?? 'club')) . '-' . random_digits(4),
                    'is_active' => 1,
                    'is_demo' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->record($actor, 'user.create', 'user', $userId, ['role' => $role, 'username' => $username]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'user.create',
                'target_type' => 'user', 'target_id' => $userId,
                'summary' => 'ایجاد کاربر: ' . $username, 'link' => '/panel/users/' . $userId,
            ]);
            return ['id' => $userId, 'uuid' => $uuid];
        });
    }

    /**
     * Update a user's editable fields.
     *
     * @param int   $id    User id.
     * @param array $input Fields to update.
     * @param array $actor {id,role}
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|ValidationException
     *
     * Side effects: updates users (+ rider_profiles), audit + changelog.
     */
    public function update(int $id, array $input, array $actor): array
    {
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user === null) { throw new NotFoundException('User not found', 'USER_NOT_FOUND'); }
        if ($actor['role'] === 'manager' && !in_array($user['role'], ['rider', 'club'], true)) {
            throw new ForbiddenException('Managers may only edit riders and clubs', 'FORBIDDEN');
        }
        if ($actor['role'] === 'rider' && (int) $user['id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Riders may only edit their own profile', 'FORBIDDEN');
        }
        if ($actor['role'] === 'club' && (int) $user['id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Clubs may only edit their own profile', 'FORBIDDEN');
        }

        $data = [];
        foreach (['first_name', 'last_name', 'email'] as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field]; }
        }
        if (array_key_exists('phone', $input) && $input['phone'] !== '' && $input['phone'] !== null) {
            $phone = PhoneValidator::toE164((string) $input['phone']);
            if ($phone === null) { throw new ValidationException('Invalid phone', 'phone', 'USER_PHONE_INVALID'); }
            if ($this->db->scalar('SELECT COUNT(*) FROM users WHERE phone = :p AND id != :id', ['p' => $phone, 'id' => $id]) > 0) {
                throw new DomainException('USER_PHONE_TAKEN', 'Phone already registered', 409, 'phone');
            }
            $data['phone'] = $phone;
        }
        if (array_key_exists('national_id', $input) && $input['national_id'] !== '' && $input['national_id'] !== null) {
            $nid = normalize_digits((string) $input['national_id']);
            if (!PhoneValidator::isValidNationalId($nid)) { throw new ValidationException('Invalid national ID', 'national_id', 'USER_NATIONAL_ID_INVALID'); }
            $data['national_id'] = $nid;
        }
        // Only admins/managers may change these privileged fields.
        if (in_array($actor['role'], ['admin', 'manager'], true)) {
            if (array_key_exists('disable_state', $input)) { $data['disable_state'] = $input['disable_state']; }
        }
        $data['updated_at'] = now_utc();

        $this->db->transaction(function () use ($id, $data, $input, $actor, $user): void {
            $this->db->update('users', $data, 'id = :id', ['id' => $id]);
            if ($user['role'] === 'rider' && isset($input['rider_profile']) && is_array($input['rider_profile'])) {
                $allowed = ['gender', 'birth_date', 'insurance_number', 'province', 'city', 'address', 'bio', 'emergency_name', 'emergency_phone'];
                $profile = array_intersect_key($input['rider_profile'], array_flip($allowed));
                if ($profile !== []) {
                    $profile['updated_at'] = now_utc();
                    $this->db->update('rider_profiles', $profile, 'user_id = :u', ['u' => $id]);
                }
            }
            $this->record($actor, 'user.update', 'user', $id, $data);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'user.update',
                'target_type' => 'user', 'target_id' => $id,
                'summary' => 'ویرایش کاربر #' . $id, 'link' => '/panel/users/' . $id,
            ]);
        });
        return ['id' => $id];
    }

    /**
     * Hard-delete a user and dependents (Admin only).
     *
     * @param int   $id    User id.
     * @param array $actor {id,role}
     * @return void
     * @throws ForbiddenException When actor is not admin.
     *
     * Side effects: deletes the user (cascades), audit + changelog.
     */
    public function delete(int $id, array $actor): void
    {
        if ($actor['role'] !== 'admin') {
            throw new ForbiddenException('Only admins may delete users', 'FORBIDDEN');
        }
        $user = $this->db->selectOne('SELECT id, role, username FROM users WHERE id = :id', ['id' => $id]);
        if ($user === null) { throw new NotFoundException('User not found', 'USER_NOT_FOUND'); }
        $this->db->transaction(function () use ($id, $actor, $user): void {
            $this->db->delete('users', 'id = :id', ['id' => $id]);
            $this->record($actor, 'user.delete', 'user', $id, ['username' => $user['username']]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'user.delete',
                'target_type' => 'user', 'target_id' => $id, 'summary' => 'حذف کاربر: ' . $user['username'],
            ]);
        });
    }

    /**
     * Verify a pending rider.
     *
     * @param int   $id    User id.
     * @param array $actor {id,role}
     * @return array{id:int,verified_at:string}
     * @throws NotFoundException|ForbiddenException
     *
     * Side effects: updates verification_status; notifies rider; SMS; audit.
     */
    public function verify(int $id, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user === null) { throw new NotFoundException('User not found', 'USER_NOT_FOUND'); }
        $now = now_utc();
        $this->db->update('users', ['verification_status' => 'verified', 'auto_verify_at' => null, 'updated_at' => $now], 'id = :id', ['id' => $id]);
        $this->notifications?->create($id, 'rider.verified', 'تایید حساب', 'حساب شما توسط مدیریت تایید شد.', '/panel/profile', 'user', $id);
        $this->record($actor, 'user.verify', 'user', $id);
        return ['id' => $id, 'verified_at' => $now];
    }

    /**
     * Reject a pending rider: hard-delete account + horses + uploads; anonymize
     * signups; keep payment orders (Technical §11.8).
     *
     * @param int   $id    User id.
     * @param array $actor {id,role}
     * @return void
     * @throws ForbiddenException
     *
     * Side effects: deletes user/horses/uploads; updates signups; notifies; audit.
     * Transaction: yes.
     */
    public function reject(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user === null) { throw new NotFoundException('User not found', 'USER_NOT_FOUND'); }
        $this->db->transaction(function () use ($id, $actor): void {
            // Anonymize signups but keep them for reports.
            $this->db->execute("UPDATE signups SET status = 'rejected', updated_at = :t WHERE rider_user_id = :u", ['t' => now_utc(), 'u' => $id]);
            // Remove rider horses and their media.
            $horses = $this->db->select('SELECT id FROM horses WHERE owner_user_id = :u', ['u' => $id]);
            foreach ($horses as $horse) {
                $images = $this->db->select('SELECT media_id FROM horse_images WHERE horse_id = :h', ['h' => (int) $horse['id']]);
                foreach ($images as $img) { $this->media->delete((int) $img['media_id']); }
                $this->db->delete('horses', 'id = :id', ['id' => (int) $horse['id']]);
            }
            $this->db->delete('users', 'id = :id', ['id' => $id]);
            $this->record($actor, 'user.reject', 'user', $id, ['username' => $user['username']]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'user.reject',
                'target_type' => 'user', 'target_id' => $id, 'summary' => 'رد حساب کاربر: ' . $user['username'],
            ]);
        });
    }

    /**
     * Set a user's disable state (none/limited/full).
     *
     * @param int    $id     User id.
     * @param string $state  none|limited|full.
     * @param string $reason Reason text.
     * @param array  $actor  {id,role}
     * @return void
     * @throws NotFoundException|ForbiddenException|ValidationException
     *
     * Side effects: updates disable_state; revokes sessions on full; notifies; audit.
     */
    public function setDisableState(int $id, string $state, string $reason, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        if (!in_array($state, ['none', 'limited', 'full'], true)) { throw new ValidationException('Invalid state', 'state', 'VALIDATION_FAILED'); }
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user === null) { throw new NotFoundException('User not found', 'USER_NOT_FOUND'); }
        $this->db->update('users', ['disable_state' => $state, 'disable_reason' => $reason, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        if ($state === 'full') {
            $this->db->delete('sessions', 'user_id = :u', ['u' => $id]);
        }
        if ($state !== 'none') {
            $this->notifications?->create($id, 'user.disabled', 'تغییر وضعیت حساب', 'وضعیت حساب شما تغییر کرد: ' . $state, '/panel/profile', 'user', $id);
        }
        $this->record($actor, 'user.disable', 'user', $id, ['state' => $state, 'reason' => $reason]);
    }

    /**
     * Reset a user's password (manual, by manager/admin).
     *
     * @param int    $id          User id.
     * @param string $newPassword New password.
     * @param array  $actor       {id,role}
     * @param string|null $exceptSessionId Session to keep.
     * @return void
     * @throws ForbiddenException|ValidationException
     */
    public function resetPassword(int $id, string $newPassword, array $actor, ?string $exceptSessionId = null): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $min = (int) $this->settings->get('auth.password_min_length', 8);
        if (mb_strlen($newPassword) < $min) {
            throw new ValidationException('Password too short', 'password', 'USER_PASSWORD_WEAK');
        }
        $this->db->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $id]);
        $this->db->delete('sessions', 'user_id = :u AND (:e IS NULL OR id != :e)', ['u' => $id, 'e' => $exceptSessionId]);
        $this->record($actor, 'user.reset_password', 'user', $id);
    }

    /**
     * Auto-verify pending riders whose 48h window has elapsed.
     *
     * @param int|null $userId Limit to one user, or null for all due.
     * @return int Number verified.
     */
    public function autoVerifyDue(?int $userId = null): int
    {
        $now = now_utc();
        $params = ['now' => $now];
        $sql = "SELECT id FROM users WHERE role = 'rider' AND verification_status = 'pending'
                AND auto_verify_at IS NOT NULL AND auto_verify_at <= :now";
        if ($userId !== null) {
            $sql .= ' AND id = :u';
            $params['u'] = $userId;
        }
        $rows = $this->db->select($sql, $params);
        foreach ($rows as $row) {
            $this->db->update('users', ['verification_status' => 'verified', 'auto_verify_at' => null, 'updated_at' => $now], 'id = :id', ['id' => (int) $row['id']]);
        }
        return count($rows);
    }

    /**
     * Whether an action is allowed for a user's disable/verification state.
     *
     * @param array  $user      User row.
     * @param string $operation create|modify|view|report.
     * @return void
     * @throws DomainException USER_DISABLED_LIMITED | USER_NOT_VERIFIED.
     */
    public function assertCanWrite(array $user, string $operation = 'modify'): void
    {
        if (($user['disable_state'] ?? 'none') === 'limited' && in_array($operation, ['create', 'modify'], true)) {
            throw new DomainException('USER_DISABLED_LIMITED', 'Account restricted', 403);
        }
        if (($user['role'] ?? '') === 'rider' && ($user['verification_status'] ?? '') === 'pending'
            && in_array($operation, ['create', 'modify'], true)) {
            throw new DomainException('USER_NOT_VERIFIED', 'Account not verified', 403);
        }
    }

    /**
     * Write an audit + return a small result array.
     *
     * @param array  $actor  Actor.
     * @param string $action Action name.
     * @param string $type   Target type.
     * @param int    $id     Target id.
     * @param array  $diff   Diff.
     * @return void
     */
    private function record(array $actor, string $action, string $type, int $id, array $diff = []): void
    {
        $this->log->audit([
            'actor_id' => $actor['id'] ?? null,
            'actor_role' => $actor['role'] ?? null,
            'impersonated_by' => $actor['impersonated_by'] ?? null,
            'action' => $action,
            'target_type' => $type,
            'target_id' => $id,
            'diff' => $diff,
            'ip' => $actor['ip'] ?? null,
            'ua_hash' => $actor['ua_hash'] ?? null,
            'result' => 'ok',
        ]);
    }
}
