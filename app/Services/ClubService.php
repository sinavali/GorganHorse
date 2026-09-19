<?php
declare(strict_types=1);

/**
 * File: app/Services/ClubService.php
 *
 * Purpose:
 *   Club lifecycle: CRUD, linked club user account, profile media, forward-looking
 *   bans on riders/horses, and club-scoped reads (affiliated riders, venue
 *   competitions, revenue) (Blueprint §4.3, §8.2.5; Technical §12.3).
 *
 * Dependencies: Database, SettingService, LogService, MediaService, NotificationService.
 *
 * Conventions:
 *   - Club bans are forward-looking only.
 *   - Club scoping resolves via clubs.user_id.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Class: ClubService
 *
 * Purpose: Manage clubs, their bans, and club-scoped reads.
 */
final class ClubService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private MediaService $media;
    private ?NotificationService $notifications;

    /**
     * @param Database                 $db            Main DB.
     * @param SettingService           $settings      Settings.
     * @param LogService               $log           Logs.
     * @param MediaService             $media         Media.
     * @param NotificationService|null $notifications Notifications.
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
     * List clubs with filters.
     *
     * @param array $filters {status?,city?,search?}
     * @param array $actor   {id,role} (riders see the list as affiliation options)
     * @param int   $page    Page.
     * @param int   $perPage Page size.
     * @return array{rows:array,total:int,page:int,per_page:int}
     */
    public function list(array $filters, array $actor, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'active') { $where[] = 'c.is_active = 1'; }
        elseif ($status === 'inactive') { $where[] = 'c.is_active = 0'; }
        if (!empty($filters['city'])) { $where[] = 'c.city = :city'; $params['city'] = $filters['city']; }
        if (!empty($filters['search'])) { $where[] = '(c.name LIKE :q OR c.contact_person LIKE :q OR c.phone LIKE :q)'; $params['q'] = '%' . $filters['search'] . '%'; }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM clubs c WHERE ' . $whereSql, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            'SELECT c.*, (SELECT COUNT(DISTINCT s.rider_user_id) FROM signups s WHERE s.affiliation_club_id = c.id) AS affiliated_count
             FROM clubs c WHERE ' . $whereSql . ' ORDER BY c.name ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch a club by id (with optional scope enforcement).
     *
     * @param int   $id    Club id.
     * @param array $actor Actor.
     * @return array
     * @throws NotFoundException CLUB_NOT_FOUND.
     */
    public function get(int $id, array $actor): array
    {
        $club = $this->db->selectOne('SELECT * FROM clubs WHERE id = :id', ['id' => $id]);
        if ($club === null) { throw new NotFoundException('Club not found', 'CLUB_NOT_FOUND'); }
        if ($actor['role'] === 'club' && (int) $club['user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Clubs may only view their own club', 'FORBIDDEN');
        }
        if ($actor['role'] === 'rider' && !in_array('list', ['list'], true)) {
            // Riders may see basic club info for affiliation selection only.
            return array_intersect_key($club, array_flip(['id', 'uuid', 'name', 'city', 'province']));
        }
        return $club;
    }

    /**
     * Resolve a club row from a club-user id.
     *
     * @param int $userId Club user id.
     * @return array|null
     */
    public function byUserId(int $userId): ?array
    {
        return $this->db->selectOne('SELECT * FROM clubs WHERE user_id = :u', ['u' => $userId]);
    }

    /**
     * Create a club profile (linked to a club user).
     *
     * @param array $input {user_id,name,slug?,city?,province?,address?,contact_person?,phone?,email?,description?}
     * @param array $actor Actor {id,role}.
     * @return array{id:int,uuid:string}
     * @throws ForbiddenException|ValidationException|DomainException
     *
     * Side effects: inserts clubs; audit + changelog. Transaction: yes.
     */
    public function create(array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') { throw new ValidationException('Club name is required', 'name', 'VALIDATION_FAILED'); }
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) { throw new ValidationException('Club user is required', 'user_id', 'VALIDATION_FAILED'); }
        if ($this->db->scalar('SELECT COUNT(*) FROM clubs WHERE user_id = :u', ['u' => $userId]) > 0) {
            throw new DomainException('CLUB_USER_TAKEN', 'Club user already linked', 409, 'user_id');
        }
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') { $slug = slugify($name) . '-' . random_digits(4); }
        if ($this->db->scalar('SELECT COUNT(*) FROM clubs WHERE slug = :s', ['s' => $slug]) > 0) {
            $slug .= '-' . random_digits(4);
        }
        $now = now_utc();
        return $this->db->transaction(function () use ($input, $name, $slug, $userId, $now, $actor): array {
            $uuid = uuid4();
            $id = $this->db->insert('clubs', [
                'uuid' => $uuid,
                'user_id' => $userId,
                'name' => $name,
                'slug' => $slug,
                'city' => $input['city'] ?? null,
                'province' => $input['province'] ?? null,
                'address' => $input['address'] ?? null,
                'contact_person' => $input['contact_person'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'description' => $input['description'] ?? null,
                'is_active' => 1,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->record($actor, 'club.create', $id, ['name' => $name]);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /**
     * Update a club profile.
     *
     * @param int   $id    Club id.
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|DomainException
     *
     * Side effects: updates clubs (optionally club user); audit.
     */
    public function update(int $id, array $input, array $actor): array
    {
        $club = $this->db->selectOne('SELECT * FROM clubs WHERE id = :id', ['id' => $id]);
        if ($club === null) { throw new NotFoundException('Club not found', 'CLUB_NOT_FOUND'); }
        if ($actor['role'] === 'club' && (int) $club['user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        if (!in_array($actor['role'], ['admin', 'manager', 'club'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }

        $data = [];
        foreach (['name', 'city', 'province', 'address', 'contact_person', 'phone', 'email', 'description'] as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field]; }
        }
        if (in_array($actor['role'], ['admin', 'manager'], true) && array_key_exists('is_active', $input)) {
            $data['is_active'] = $input['is_active'] ? 1 : 0;
        }
        if (array_key_exists('slug', $input) && $input['slug'] !== '') {
            $slug = (string) $input['slug'];
            if ($this->db->scalar('SELECT COUNT(*) FROM clubs WHERE slug = :s AND id != :id', ['s' => $slug, 'id' => $id]) > 0) {
                throw new DomainException('FORBIDDEN', 'Slug already taken', 409, 'slug');
            }
            $data['slug'] = $slug;
        }
        $data['updated_at'] = now_utc();

        $this->db->transaction(function () use ($id, $data, $input, $actor, $club): void {
            if ($data !== ['updated_at' => $data['updated_at']]) {
                $this->db->update('clubs', $data, 'id = :id', ['id' => $id]);
            }
            // Club profile mirrors onto the linked user (name/contact).
            if (isset($data['name'])) {
                $this->db->update('users', ['first_name' => $data['name'], 'phone' => $data['phone'] ?? $club['phone'], 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $club['user_id']]);
            }
            $this->record($actor, 'club.update', $id, $data);
        });
        return ['id' => $id];
    }

    /**
     * Hard-delete a club (Admin only).
     *
     * @param int   $id    Club id.
     * @param array $actor Actor.
     * @return void
     * @throws ForbiddenException|NotFoundException
     */
    public function delete(int $id, array $actor): void
    {
        if ($actor['role'] !== 'admin') { throw new ForbiddenException('Only admins may delete clubs', 'FORBIDDEN'); }
        $club = $this->db->selectOne('SELECT * FROM clubs WHERE id = :id', ['id' => $id]);
        if ($club === null) { throw new NotFoundException('Club not found', 'CLUB_NOT_FOUND'); }
        $this->db->transaction(function () use ($id, $actor, $club): void {
            $this->db->delete('clubs', 'id = :id', ['id' => $id]);
            $this->db->delete('users', 'id = :id', ['id' => (int) $club['user_id']]);
            $this->record($actor, 'club.delete', $id, ['name' => $club['name']]);
        });
    }

    /**
     * Add a forward-looking club ban.
     *
     * @param int    $clubId     Club id.
     * @param string $targetType rider|horse.
     * @param int    $targetId   Target id.
     * @param string $reason     Reason.
     * @param array  $actor      Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|ValidationException
     *
     * Side effects: inserts club_bans; notifies target; audit.
     */
    public function addBan(int $clubId, string $targetType, int $targetId, string $reason, array $actor): array
    {
        if (!(bool) $this->settings->get('clubs.enable_bans', true)) {
            throw new ForbiddenException('Club bans are disabled', 'FORBIDDEN');
        }
        $club = $this->db->selectOne('SELECT * FROM clubs WHERE id = :id', ['id' => $clubId]);
        if ($club === null) { throw new NotFoundException('Club not found', 'CLUB_NOT_FOUND'); }
        if ($actor['role'] === 'club' && (int) $club['user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        if (!in_array($targetType, ['rider', 'horse'], true)) { throw new ValidationException('Invalid target type', 'target_type', 'VALIDATION_FAILED'); }
        $days = (int) $this->settings->get('clubs.ban_expiry_days', 365);
        $now = now_utc();
        $id = $this->db->insert('club_bans', [
            'club_id' => $clubId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'banned_by' => (int) $actor['id'],
            'expires_at' => $days > 0 ? utc_iso(time() + $days * 86400) : null,
            'is_active' => 1,
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($targetType === 'rider') {
            $this->notifications?->create($targetId, 'ban.applied', 'تحریم باشگاه', 'باشگاه ' . $club['name'] . ' شما را تحریم کرد.', '/panel/profile', 'club_ban', $id);
        }
        $this->record($actor, 'club.ban.add', $clubId, ['target_type' => $targetType, 'target_id' => $targetId]);
        return ['id' => $id];
    }

    /**
     * Remove (soft) a club ban.
     *
     * @param int   $clubId Club id.
     * @param int   $banId  Ban id.
     * @param array $actor  Actor.
     * @return void
     * @throws ForbiddenException
     */
    public function removeBan(int $clubId, int $banId, array $actor): void
    {
        $club = $this->db->selectOne('SELECT * FROM clubs WHERE id = :id', ['id' => $clubId]);
        if ($club === null) { throw new NotFoundException('Club not found', 'CLUB_NOT_FOUND'); }
        if ($actor['role'] === 'club' && (int) $club['user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $this->db->update('club_bans', ['is_active' => 0, 'updated_at' => now_utc()], 'id = :id AND club_id = :c', ['id' => $banId, 'c' => $clubId]);
        $this->record($actor, 'club.ban.remove', $clubId, ['ban_id' => $banId]);
    }

    /**
     * List active bans for a club.
     *
     * @param int   $clubId   Club id.
     * @param array $actor    Actor.
     * @param string|null $type Filter by target type.
     * @return array<int,array>
     */
    public function bans(int $clubId, array $actor, ?string $type = null): array
    {
        $params = ['c' => $clubId];
        $sql = 'SELECT b.*, '
            . "CASE WHEN b.target_type = 'rider' THEN (SELECT first_name || ' ' || last_name FROM users WHERE id = b.target_id) "
            . 'ELSE (SELECT name FROM horses WHERE id = b.target_id) END AS target_name '
            . 'FROM club_bans b WHERE b.club_id = :c AND b.is_active = 1';
        if ($type !== null) { $sql .= ' AND b.target_type = :t'; $params['t'] = $type; }
        $sql .= ' ORDER BY b.id DESC';
        return $this->db->select($sql, $params);
    }

    /**
     * Whether a club has banned a rider/horse.
     *
     * @param int    $clubId     Club id.
     * @param string $targetType rider|horse.
     * @param int    $targetId   Target.
     * @return bool
     */
    public function isBanned(int $clubId, string $targetType, int $targetId): bool
    {
        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM club_bans WHERE club_id = :c AND target_type = :t AND target_id = :i AND is_active = 1
             AND (expires_at IS NULL OR expires_at > :now)',
            ['c' => $clubId, 't' => $targetType, 'i' => $targetId, 'now' => now_utc()]
        );
        return $count > 0;
    }

    /**
     * List riders affiliated with a club (chosen as affiliation in any signup).
     *
     * @param int $clubId Club id.
     * @return array<int,array>
     */
    public function affiliatedRiders(int $clubId): array
    {
        return $this->db->select(
            "SELECT u.id, u.username, u.first_name, u.last_name, u.national_id, u.phone,
                    COUNT(DISTINCT s.competition_id) AS competitions_count,
                    MAX(s.created_at) AS last_signup_at
             FROM signups s JOIN users u ON u.id = s.rider_user_id
             WHERE s.affiliation_club_id = :c
             GROUP BY u.id ORDER BY last_signup_at DESC",
            ['c' => $clubId]
        );
    }

    /**
     * List competitions held at a club's venue.
     *
     * @param int $clubId Club id.
     * @return array<int,array>
     */
    public function venueCompetitions(int $clubId): array
    {
        return $this->db->select(
            'SELECT c.*, (SELECT COUNT(*) FROM signups s WHERE s.competition_id = c.id) AS signup_count
             FROM competitions c WHERE c.venue_club_id = :c ORDER BY c.start_at DESC',
            ['c' => $clubId]
        );
    }

    /**
     * Revenue generated at a club's venue in a UTC window.
     *
     * @param int         $clubId Club id.
     * @param string|null $from   UTC lower bound.
     * @param string|null $to     UTC upper bound.
     * @return int Total IRT.
     */
    public function venueRevenue(int $clubId, ?string $from = null, ?string $to = null): int
    {
        $sql = "SELECT COALESCE(SUM(po.amount_irt),0) FROM payment_orders po
                JOIN signups s ON s.id = po.signup_id
                JOIN competitions c ON c.id = s.competition_id
                WHERE c.venue_club_id = :c AND po.status = 'paid'";
        $params = ['c' => $clubId];
        if ($from !== null) { $sql .= ' AND po.verified_at >= :from'; $params['from'] = $from; }
        if ($to !== null) { $sql .= ' AND po.verified_at <= :to'; $params['to'] = $to; }
        return (int) $this->db->scalar($sql, $params);
    }

    /**
     * Count active bans for a club.
     *
     * @param int $clubId Club id.
     * @return int
     */
    public function activeBanCount(int $clubId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM club_bans WHERE club_id = :c AND is_active = 1 AND (expires_at IS NULL OR expires_at > :now)',
            ['c' => $clubId, 'now' => now_utc()]
        );
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Club id.
     * @param array  $diff   Diff.
     * @return void
     */
    private function record(array $actor, string $action, int $id, array $diff = []): void
    {
        $this->log->audit([
            'actor_id' => $actor['id'] ?? null,
            'actor_role' => $actor['role'] ?? null,
            'impersonated_by' => $actor['impersonated_by'] ?? null,
            'action' => $action,
            'target_type' => 'club',
            'target_id' => $id,
            'diff' => $diff,
            'ip' => $actor['ip'] ?? null,
            'result' => 'ok',
        ]);
    }
}
