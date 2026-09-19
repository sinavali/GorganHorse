<?php
declare(strict_types=1);

/**
 * File: app/Services/CompetitionService.php
 *
 * Purpose:
 *   Competition lifecycle: CRUD, venue assignment, competition-rades binding,
 *   pause/resume, cancellation (with refund marking), clone, and automatic
 *   status transitions (Blueprint §8.2.1, §8.2.8, Technical §17).
 *
 * Dependencies: Database, SettingService, LogService, NotificationService.
 *
 * Conventions:
 *   - All timestamps UTC ISO-8601.
 *   - Results status flows draft → confirmed → published.
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
 * Class: CompetitionService
 *
 * Purpose: Manage competitions and their competition-rades.
 */
final class CompetitionService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private ?NotificationService $notifications;

    /**
     * @param Database                 $db            Main DB.
     * @param SettingService           $settings      Settings.
     * @param LogService               $log           Logs.
     * @param NotificationService|null $notifications Notifications.
     */
    public function __construct(Database $db, SettingService $settings, LogService $log, ?NotificationService $notifications = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
        $this->notifications = $notifications;
    }

    /**
     * List competitions with filters and role scoping.
     *
     * @param array $filters {status?,venue_club_id?,from?,to?,search?,open_only?}
     * @param array $actor   Actor.
     * @param int   $page    Page.
     * @param int   $perPage Page size.
     * @return array{rows:array,total:int,page:int,per_page:int}
     */
    public function list(array $filters, array $actor, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        if ($actor['role'] === 'club') {
            $club = $this->db->selectOne('SELECT id FROM clubs WHERE user_id = :u', ['u' => (int) $actor['id']]);
            if ($club !== null) { $where[] = 'c.venue_club_id = :club'; $params['club'] = (int) $club['id']; }
        } elseif (!empty($filters['venue_club_id'])) {
            $where[] = 'c.venue_club_id = :club';
            $params['club'] = (int) $filters['venue_club_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') { $where[] = 'c.status = :status'; $params['status'] = $filters['status']; }
        if (!empty($filters['from'])) { $where[] = 'c.start_at >= :from'; $params['from'] = $filters['from']; }
        if (!empty($filters['to'])) { $where[] = 'c.start_at <= :to'; $params['to'] = $filters['to']; }
        if (!empty($filters['search'])) { $where[] = 'c.title LIKE :q'; $params['q'] = '%' . $filters['search'] . '%'; }
        if (!empty($filters['open_only'])) { $where[] = "c.status = 'open' AND c.registration_paused = 0"; }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM competitions c WHERE ' . $whereSql, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT c.*, cl.name AS venue_name,
                    (SELECT COUNT(*) FROM competition_rades cr WHERE cr.competition_id = c.id) AS rade_count,
                    (SELECT COUNT(*) FROM signups s WHERE s.competition_id = c.id AND s.status IN ('paid','confirmed')) AS signup_count
             FROM competitions c LEFT JOIN clubs cl ON cl.id = c.venue_club_id
             WHERE " . $whereSql . ' ORDER BY c.start_at DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch a competition with its rade assignments.
     *
     * @param int   $id    Competition id.
     * @param array $actor Actor.
     * @return array
     * @throws NotFoundException COMPETITION_NOT_FOUND.
     * @throws ForbiddenException For club scoping.
     */
    public function get(int $id, array $actor): array
    {
        $competition = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $id]);
        if ($competition === null) { throw new NotFoundException('Competition not found', 'COMPETITION_NOT_FOUND'); }
        if ($actor['role'] === 'club') {
            $club = $this->db->selectOne('SELECT id FROM clubs WHERE user_id = :u', ['u' => (int) $actor['id']]);
            if ($club !== null && (int) $competition['venue_club_id'] !== (int) $club['id']) {
                throw new ForbiddenException('Forbidden', 'FORBIDDEN');
            }
        }
        $competition['rades'] = $this->rades($id);
        return $competition;
    }

    /**
     * List competition-rades with joined rade/payment/counts.
     *
     * @param int $competitionId Competition id.
     * @return array<int,array>
     */
    public function rades(int $competitionId): array
    {
        return $this->db->select(
            'SELECT cr.*, r.name AS rade_name, r.age_min, r.age_max, p.name AS payment_name, p.amount_irt,
                    (SELECT COUNT(*) FROM signups s WHERE s.competition_rade_id = cr.id AND s.status IN (\'paid\',\'confirmed\')) AS signup_count
             FROM competition_rades cr
             JOIN rades r ON r.id = cr.rade_id
             JOIN payments p ON p.id = cr.payment_id
             WHERE cr.competition_id = :c ORDER BY cr.sort_order ASC, cr.id ASC',
            ['c' => $competitionId]
        );
    }

    /**
     * Create a competition.
     *
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int,uuid:string}
     * @throws ForbiddenException|ValidationException|DomainException
     *
     * Side effects: inserts competitions; notifies venue club; audit. Transaction: yes.
     */
    public function create(array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') { throw new ValidationException('Title is required', 'title', 'VALIDATION_FAILED'); }
        $startReg = (string) ($input['start_registration_at'] ?? '');
        $endReg = (string) ($input['end_registration_at'] ?? '');
        $startAt = (string) ($input['start_at'] ?? '');
        if ($startReg === '' || $endReg === '' || $startAt === '') {
            throw new ValidationException('Registration window and start date are required', 'start_at', 'VALIDATION_FAILED');
        }
        if (!($startReg < $endReg && $endReg <= $startAt)) {
            throw new ValidationException('Invalid registration window ordering', 'end_registration_at', 'VALIDATION_FAILED');
        }
        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'open', 'closed', 'running', 'finished', 'cancelled'], true)) {
            throw new ValidationException('Invalid status', 'status', 'VALIDATION_FAILED');
        }
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') { $slug = slugify($title) . '-' . random_digits(4); }
        if ($this->db->scalar('SELECT COUNT(*) FROM competitions WHERE slug = :s', ['s' => $slug]) > 0) {
            throw new DomainException('COMPETITION_SLUG_TAKEN', 'Slug already taken', 409, 'slug');
        }
        $now = now_utc();
        return $this->db->transaction(function () use ($input, $title, $slug, $status, $startReg, $endReg, $startAt, $now, $actor): array {
            $uuid = uuid4();
            $id = $this->db->insert('competitions', [
                'uuid' => $uuid,
                'title' => $title,
                'slug' => $slug,
                'venue_club_id' => !empty($input['venue_club_id']) ? (int) $input['venue_club_id'] : null,
                'city' => $input['city'] ?? null,
                'description' => $input['description'] ?? null,
                'rules' => $input['rules'] ?? null,
                'start_registration_at' => $startReg,
                'end_registration_at' => $endReg,
                'start_at' => $startAt,
                'end_at' => $input['end_at'] ?? null,
                'registration_paused' => 0,
                'status' => $status,
                'results_status' => 'draft',
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!empty($input['venue_club_id'])) {
                $clubUser = $this->db->selectOne('SELECT user_id FROM clubs WHERE id = :id', ['id' => (int) $input['venue_club_id']]);
                if ($clubUser !== null) {
                    $this->notifications?->create((int) $clubUser['user_id'], 'competition.assigned', 'مسابقه در محل باشگاه', 'مسابقه "' . $title . '" در محل باشگاه شما برگزار می‌شود.', '/panel/club/competitions', 'competition', $id);
                }
            }
            $this->record($actor, 'competition.create', $id, ['title' => $title]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'competition.create',
                'target_type' => 'competition', 'target_id' => $id, 'summary' => 'ایجاد مسابقه: ' . $title,
                'link' => '/panel/competitions/' . $id,
            ]);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /**
     * Update a competition.
     *
     * @param int   $id    Competition id.
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|DomainException
     */
    public function update(int $id, array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->get($id, $actor);
        $data = [];
        foreach (['title', 'description', 'rules', 'city', 'end_at'] as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field]; }
        }
        foreach (['start_registration_at', 'end_registration_at', 'start_at'] as $field) {
            if (!empty($input[$field])) { $data[$field] = $input[$field]; }
        }
        if (array_key_exists('venue_club_id', $input)) { $data['venue_club_id'] = $input['venue_club_id'] !== '' && $input['venue_club_id'] !== null ? (int) $input['venue_club_id'] : null; }
        if (array_key_exists('status', $input) && in_array($input['status'], ['draft', 'open', 'closed', 'running', 'finished', 'cancelled'], true)) {
            $data['status'] = $input['status'];
        }
        if (array_key_exists('slug', $input) && $input['slug'] !== '') {
            $slug = (string) $input['slug'];
            if ($this->db->scalar('SELECT COUNT(*) FROM competitions WHERE slug = :s AND id != :id', ['s' => $slug, 'id' => $id]) > 0) {
                throw new DomainException('COMPETITION_SLUG_TAKEN', 'Slug already taken', 409, 'slug');
            }
            $data['slug'] = $slug;
        }
        // Re-validate ordering on the merged record.
        $current = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $id]);
        $sr = $data['start_registration_at'] ?? $current['start_registration_at'];
        $er = $data['end_registration_at'] ?? $current['end_registration_at'];
        $sa = $data['start_at'] ?? $current['start_at'];
        if (!($sr < $er && $er <= $sa)) {
            throw new ValidationException('Invalid registration window ordering', 'end_registration_at', 'VALIDATION_FAILED');
        }
        $data['updated_at'] = now_utc();
        $this->db->update('competitions', $data, 'id = :id', ['id' => $id]);
        $this->record($actor, 'competition.update', $id, $data);
        return ['id' => $id];
    }

    /**
     * Delete a competition (blocked when it has signups).
     *
     * @param int   $id    Competition id.
     * @param array $actor Actor.
     * @return void
     * @throws ForbiddenException|DomainException
     */
    public function delete(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->get($id, $actor);
        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE competition_id = :c', ['c' => $id]);
        if ($count > 0) {
            throw new DomainException('FORBIDDEN', 'Competition has signups and cannot be deleted', 409);
        }
        $this->db->transaction(function () use ($id, $actor): void {
            $this->db->delete('competition_rades', 'competition_id = :c', ['c' => $id]);
            $this->db->delete('competitions', 'id = :id', ['id' => $id]);
            $this->record($actor, 'competition.delete', $id);
        });
    }

    /**
     * Bind a rade to a competition.
     *
     * @param int   $competitionId Competition id.
     * @param array $input {rade_id,payment_id,capacity?,auto_confirm?,signup_mode?,had_barrage?,sort_order?}
     * @param array $actor Actor.
     * @return array{id:int,uuid:string}
     * @throws ForbiddenException|ValidationException|DomainException COMPETITION_RADE_FULL.
     *
     * Side effects: inserts competition_rades; audit.
     */
    public function addRade(int $competitionId, array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->get($competitionId, $actor);
        $radeId = (int) ($input['rade_id'] ?? 0);
        $paymentId = (int) ($input['payment_id'] ?? 0);
        if ($radeId <= 0 || $paymentId <= 0) { throw new ValidationException('Rade and payment are required', 'rade_id', 'VALIDATION_FAILED'); }
        $dup = (int) $this->db->scalar('SELECT COUNT(*) FROM competition_rades WHERE competition_id = :c AND rade_id = :r', ['c' => $competitionId, 'r' => $radeId]);
        if ($dup > 0) { throw new DomainException('SIGNUP_DUPLICATE', 'Rade already added to this competition', 409); }
        $capacity = isset($input['capacity']) && $input['capacity'] !== '' && (int) $input['capacity'] > 0 ? (int) $input['capacity'] : null;
        $signupMode = (string) ($input['signup_mode'] ?? 'per_rade');
        if (!in_array($signupMode, ['per_competition', 'per_rade'], true)) { throw new ValidationException('Invalid signup mode', 'signup_mode', 'VALIDATION_FAILED'); }
        $now = now_utc();
        $uuid = uuid4();
        $id = $this->db->insert('competition_rades', [
            'uuid' => $uuid,
            'competition_id' => $competitionId,
            'rade_id' => $radeId,
            'payment_id' => $paymentId,
            'capacity' => $capacity,
            'auto_confirm' => !empty($input['auto_confirm']) ? 1 : 0,
            'had_barrage' => !empty($input['had_barrage']) ? 1 : 0,
            'barrage_notes' => $input['barrage_notes'] ?? null,
            'signup_mode' => $signupMode,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->record($actor, 'competition.rade.add', $competitionId, ['comp_rade_id' => $id, 'rade_id' => $radeId]);
        return ['id' => $id, 'uuid' => $uuid];
    }

    /**
     * Update a competition-rade.
     *
     * @param int   $competitionId Competition id.
     * @param int   $compRadeId    Competition-rade id.
     * @param array $input         Fields.
     * @param array $actor         Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|DomainException COMPETITION_RADE_CAPACITY_BELOW_COUNT.
     */
    public function updateRade(int $competitionId, int $compRadeId, array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $cr = $this->db->selectOne('SELECT * FROM competition_rades WHERE id = :id AND competition_id = :c', ['id' => $compRadeId, 'c' => $competitionId]);
        if ($cr === null) { throw new NotFoundException('Competition-Rade not found', 'COMPETITION_RADE_NOT_FOUND'); }
        $data = [];
        if (array_key_exists('capacity', $input)) {
            $capacity = $input['capacity'] === '' || $input['capacity'] === null ? null : (int) $input['capacity'];
            if ($capacity !== null) {
                $count = (int) $this->db->scalar("SELECT COUNT(*) FROM signups WHERE competition_rade_id = :cr AND status NOT IN ('cancelled','withdrawn')", ['cr' => $compRadeId]);
                if ($capacity < $count) { throw new DomainException('COMPETITION_RADE_CAPACITY_BELOW_COUNT', 'Capacity below signup count', 422, 'capacity'); }
            }
            $data['capacity'] = $capacity;
        }
        if (array_key_exists('payment_id', $input) && (int) $input['payment_id'] > 0) { $data['payment_id'] = (int) $input['payment_id']; }
        if (array_key_exists('auto_confirm', $input)) { $data['auto_confirm'] = $input['auto_confirm'] ? 1 : 0; }
        if (array_key_exists('had_barrage', $input)) { $data['had_barrage'] = $input['had_barrage'] ? 1 : 0; }
        if (array_key_exists('barrage_notes', $input)) { $data['barrage_notes'] = $input['barrage_notes']; }
        if (array_key_exists('signup_mode', $input) && in_array($input['signup_mode'], ['per_competition', 'per_rade'], true)) { $data['signup_mode'] = $input['signup_mode']; }
        if (array_key_exists('sort_order', $input)) { $data['sort_order'] = (int) $input['sort_order']; }
        $data['updated_at'] = now_utc();
        $this->db->update('competition_rades', $data, 'id = :id', ['id' => $compRadeId]);
        $this->record($actor, 'competition.rade.update', $competitionId, ['comp_rade_id' => $compRadeId] + $data);
        return ['id' => $compRadeId];
    }

    /**
     * Remove a competition-rade (blocked when it has signups).
     *
     * @param int   $competitionId Competition id.
     * @param int   $compRadeId    Competition-rade id.
     * @param array $actor         Actor.
     * @return void
     * @throws ForbiddenException|DomainException
     */
    public function removeRade(int $competitionId, int $compRadeId, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE competition_rade_id = :cr', ['cr' => $compRadeId]);
        if ($count > 0) { throw new DomainException('FORBIDDEN', 'Competition-Rade has signups and cannot be removed', 409); }
        $this->db->delete('competition_rades', 'id = :id AND competition_id = :c', ['id' => $compRadeId, 'c' => $competitionId]);
        $this->record($actor, 'competition.rade.remove', $competitionId, ['comp_rade_id' => $compRadeId]);
    }

    /**
     * Toggle the barrage flag/notes for a competition-rade.
     *
     * @param int   $competitionId Competition id.
     * @param int   $compRadeId    Competition-rade id.
     * @param bool  $hadBarrage    Barrage flag.
     * @param string $notes        Barrage notes.
     * @param array $actor         Actor.
     * @return void
     */
    public function setBarrage(int $competitionId, int $compRadeId, bool $hadBarrage, string $notes, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->db->update('competition_rades', ['had_barrage' => $hadBarrage ? 1 : 0, 'barrage_notes' => $notes, 'updated_at' => now_utc()], 'id = :id AND competition_id = :c', ['id' => $compRadeId, 'c' => $competitionId]);
        $this->record($actor, 'competition.rade.barrage', $competitionId, ['comp_rade_id' => $compRadeId, 'had_barrage' => $hadBarrage]);
    }

    /**
     * Pause registration.
     *
     * @param int   $id    Competition id.
     * @param array $actor Actor.
     * @return void
     */
    public function pause(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->db->update('competitions', ['registration_paused' => 1, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        $this->record($actor, 'competition.pause', $id);
    }

    /**
     * Resume registration.
     *
     * @param int   $id    Competition id.
     * @param array $actor Actor.
     * @return void
     */
    public function resume(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->db->update('competitions', ['registration_paused' => 0, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        $this->record($actor, 'competition.resume', $id);
    }

    /**
     * Cancel a competition: mark it cancelled and move its orders to pending_refund.
     *
     * @param int   $id    Competition id.
     * @param array $actor Actor.
     * @return array{competition_id:int,pending_refund_orders:int}
     * @throws NotFoundException|ForbiddenException
     *
     * Side effects: updates competitions + payment_orders; audit. Transaction: yes.
     */
    public function cancel(int $id, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $competition = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $id]);
        if ($competition === null) { throw new NotFoundException('Competition not found', 'COMPETITION_NOT_FOUND'); }
        $now = now_utc();
        return $this->db->transaction(function () use ($id, $now, $actor): array {
            $this->db->update('competitions', ['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now], 'id = :id', ['id' => $id]);
            $affected = $this->db->execute(
                "UPDATE payment_orders SET status = 'pending_refund', updated_at = :t
                 WHERE status = 'paid' AND signup_id IN (SELECT id FROM signups WHERE competition_id = :c)",
                ['t' => $now, 'c' => $id]
            );
            $this->record($actor, 'competition.cancel', $id, ['pending_refund_orders' => $affected]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'competition.cancel',
                'target_type' => 'competition', 'target_id' => $id, 'summary' => 'لغو مسابقه #' . $id,
            ]);
            return ['competition_id' => $id, 'pending_refund_orders' => $affected];
        });
    }

    /**
     * Clone a competition (rades + payments, no signups).
     *
     * @param int   $id    Source competition id.
     * @param array $actor Actor.
     * @return array{id:int,uuid:string}
     * @throws NotFoundException|ForbiddenException
     *
     * Side effects: inserts competitions + competition_rades; audit. Transaction: yes.
     */
    public function clone(int $id, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $source = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $id]);
        if ($source === null) { throw new NotFoundException('Competition not found', 'COMPETITION_NOT_FOUND'); }
        $rades = $this->rades($id);
        $now = now_utc();
        return $this->db->transaction(function () use ($source, $rades, $now, $actor): array {
            $uuid = uuid4();
            $title = $source['title'] . ' (کپی)';
            $slug = slugify($title) . '-' . random_digits(4);
            $newId = $this->db->insert('competitions', [
                'uuid' => $uuid,
                'title' => $title,
                'slug' => $slug,
                'venue_club_id' => $source['venue_club_id'],
                'city' => $source['city'],
                'description' => $source['description'],
                'rules' => $source['rules'],
                'start_registration_at' => $source['start_registration_at'],
                'end_registration_at' => $source['end_registration_at'],
                'start_at' => $source['start_at'],
                'end_at' => $source['end_at'],
                'registration_paused' => 0,
                'status' => 'draft',
                'results_status' => 'draft',
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($rades as $cr) {
                $this->db->insert('competition_rades', [
                    'uuid' => uuid4(),
                    'competition_id' => $newId,
                    'rade_id' => (int) $cr['rade_id'],
                    'payment_id' => (int) $cr['payment_id'],
                    'capacity' => $cr['capacity'],
                    'auto_confirm' => (int) $cr['auto_confirm'],
                    'had_barrage' => 0,
                    'barrage_notes' => null,
                    'signup_mode' => $cr['signup_mode'],
                    'sort_order' => (int) $cr['sort_order'],
                    'is_demo' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->record($actor, 'competition.clone', $newId, ['source_id' => $id]);
            return ['id' => $newId, 'uuid' => $uuid];
        });
    }

    /**
     * Apply automatic status transitions based on the current time.
     *
     * @param int $id Competition id.
     * @return void
     */
    public function applyAutoStatus(int $id): void
    {
        if (!(bool) $this->settings->get('competitions.auto_status_change', true)) { return; }
        $c = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $id]);
        if ($c === null) { return; }
        if (in_array($c['status'], ['cancelled', 'finished'], true)) { return; }
        $now = time();
        $status = $c['status'];
        if (strtotime((string) $c['start_registration_at']) <= $now && $now < strtotime((string) $c['end_registration_at'])) {
            $status = 'open';
        } elseif (strtotime((string) $c['end_registration_at']) <= $now && $now < strtotime((string) $c['start_at'])) {
            $status = 'closed';
        } elseif (strtotime((string) $c['start_at']) <= $now) {
            $status = 'running';
        }
        if ($status !== $c['status']) {
            $this->db->update('competitions', ['status' => $status, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        }
    }

    /**
     * Count competitions with a given status.
     *
     * @param string|null $status Status filter.
     * @return int
     */
    public function count(?string $status = null): int
    {
        if ($status === null) { return (int) $this->db->scalar('SELECT COUNT(*) FROM competitions'); }
        return (int) $this->db->scalar('SELECT COUNT(*) FROM competitions WHERE status = :s', ['s' => $status]);
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Competition id.
     * @param array  $diff   Diff.
     * @return void
     */
    private function record(array $actor, string $action, int $id, array $diff = []): void
    {
        $this->log->audit([
            'actor_id' => $actor['id'] ?? null, 'actor_role' => $actor['role'] ?? null,
            'action' => $action, 'target_type' => 'competition', 'target_id' => $id, 'diff' => $diff,
        ]);
    }
}
