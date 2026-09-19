<?php
declare(strict_types=1);

/**
 * File: app/Services/SignupService.php
 *
 * Purpose:
 *   Signup lifecycle: creation (with price snapshot), capacity enforcement,
 *   ban checks, confirmation (auto/manual), rejection, cancellation, withdrawal,
 *   and position entry. Coordinates with the payment order for ZarinPal
 *   (Blueprint §8.2.1, §17.1–17.4; Technical §17).
 *
 * Dependencies: Database, SettingService, LogService, PaymentService,
 *               BanService, ClubService, NotificationService, SmsService.
 *
 * Conventions:
 *   - All money in IRT integers.
 *   - Price is snapshotted into the signup at creation (P22).
 *   - Writes wrap in a transaction; capacity is checked inside it.
 *   - Capacity counts exclude cancelled, withdrawn, AND rejected signups
 *     (a rejected signup does not occupy a slot).
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
 * Class: SignupService
 *
 * Purpose: Manage the signup lifecycle.
 */
final class SignupService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private PaymentService $payments;
    private BanService $bans;
    private ClubService $clubs;
    private ?NotificationService $notifications;
    private ?SmsService $sms;

    /**
     * @param Database                 $db            Main DB.
     * @param SettingService           $settings      Settings.
     * @param LogService               $log           Logs.
     * @param PaymentService           $payments      Payment service.
     * @param BanService               $bans          Ban service.
     * @param ClubService              $clubs         Club service.
     * @param NotificationService|null $notifications Notifications.
     * @param SmsService|null          $sms           SMS.
     */
    public function __construct(
        Database $db,
        SettingService $settings,
        LogService $log,
        PaymentService $payments,
        BanService $bans,
        ClubService $clubs,
        ?NotificationService $notifications = null,
        ?SmsService $sms = null
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
        $this->payments = $payments;
        $this->bans = $bans;
        $this->clubs = $clubs;
        $this->notifications = $notifications;
        $this->sms = $sms;
    }

    /**
     * Create a signup (pending_payment) with price snapshot and a payment order.
     *
     * @param int   $competitionRadeId Competition-rade id.
     * @param int   $horseId           Horse id (owned or shared to the rider).
     * @param int   $affiliationClubId Affiliation club id.
     * @param array $actor             Actor (rider).
     * @return array{signup_id:int,uuid:string,amount_irt:int,order_id:int,requires_payment:bool}
     * @throws NotFoundException COMPETITION_RADE_NOT_FOUND | HORSE_NOT_FOUND.
     * @throws ForbiddenException HORSE_NOT_OWNED.
     * @throws DomainException COMPETITION_NOT_OPEN | COMPETITION_REGISTRATION_PAUSED |
     *                         COMPETITION_RADE_FULL | SIGNUP_DUPLICATE | SIGNUP_RIDER_BANNED |
     *                         SIGNUP_HORSE_BANNED | HORSE_NOT_ACTIVE | HORSE_TRANSFER_LOCKED | CLUB_BANNED.
     *
     * Side effects:
     *   - inserts signups (with snapshots) and payment_orders
     *   - creates notifications (rider + staff); SMS if enabled
     *   - audit + changelog
     * Transaction: yes.
     */
    public function create(int $competitionRadeId, int $horseId, int $affiliationClubId, array $actor): array
    {
        $cr = $this->db->selectOne(
            'SELECT cr.*, c.status AS comp_status, c.registration_paused, c.start_at, c.end_at,
                    c.start_registration_at, c.end_registration_at, c.id AS competition_id,
                    p.name AS payment_name, p.amount_irt, r.name AS rade_name
             FROM competition_rades cr
             JOIN competitions c ON c.id = cr.competition_id
             JOIN payments p ON p.id = cr.payment_id
             JOIN rades r ON r.id = cr.rade_id
             WHERE cr.id = :id',
            ['id' => $competitionRadeId]
        );
        if ($cr === null) {
            throw new NotFoundException('Competition-Rade not found', 'COMPETITION_RADE_NOT_FOUND');
        }

        // Registration window checks.
        if (!in_array($cr['comp_status'], ['open'], true)) {
            throw new DomainException('COMPETITION_NOT_OPEN', 'Registration closed', 422);
        }
        if ((int) $cr['registration_paused'] === 1) {
            throw new DomainException('COMPETITION_REGISTRATION_PAUSED', 'Registration paused', 422);
        }

        // Enforce the registration window in real time (covers cases where
        // competitions.auto_status_change is disabled and status is stale).
        $nowTs = time();
        $startReg = strtotime((string) $cr['start_registration_at']);
        $endReg = strtotime((string) $cr['end_registration_at']);
        if ($startReg !== false && $endReg !== false) {
            if ($nowTs < $startReg) {
                throw new DomainException('COMPETITION_NOT_OPEN', 'Registration has not started', 422);
            }
            if ($nowTs > $endReg && !(bool) $this->settings->get('competitions.allow_late_entries', true)) {
                throw new DomainException('COMPETITION_NOT_OPEN', 'Registration has closed', 422);
            }
        }

        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $horseId]);
        if ($horse === null) {
            throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND');
        }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            $shared = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM horse_shares WHERE horse_id = :h AND recipient_user_id = :u AND status IN ('pending','accepted')",
                ['h' => $horseId, 'u' => (int) $actor['id']]
            );
            if ($shared === 0) {
                throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
            }
        }
        if ($horse['status'] !== 'active') {
            throw new DomainException('HORSE_NOT_ACTIVE', 'Horse not active', 422);
        }
        if ((int) $horse['transfer_locked'] === 1) {
            throw new DomainException('HORSE_TRANSFER_LOCKED', 'Horse transfer in progress', 423);
        }

        // Ban checks (rider + horse) for this competition/rade.
        $banError = $this->bans->evaluate((int) $actor['id'], $horseId, (int) $cr['competition_id'], (int) $cr['rade_id']);
        if ($banError !== null) {
            throw new DomainException($banError, 'Banned', 403);
        }

        // Club ban (rider/horse banned by chosen affiliation club).
        if ($affiliationClubId > 0) {
            if ($this->clubs->isBanned($affiliationClubId, 'rider', (int) $actor['id'])) {
                throw new DomainException('CLUB_BANNED', 'Club has banned you', 403);
            }
            if ($this->clubs->isBanned($affiliationClubId, 'horse', $horseId)) {
                throw new DomainException('CLUB_BANNED', 'Club has banned this horse', 403);
            }
        }

        $amount = (int) $cr['amount_irt'];
        $now = now_utc();

        return $this->db->transaction(function () use ($cr, $horseId, $affiliationClubId, $amount, $now, $actor, $competitionRadeId): array {
            // Capacity check inside the transaction. Rejected signups do not
            // occupy a slot; only active states count toward capacity.
            $capacity = $cr['capacity'] !== null ? (int) $cr['capacity'] : null;
            if ($capacity !== null) {
                $count = (int) $this->db->scalar(
                    "SELECT COUNT(*) FROM signups WHERE competition_rade_id = :cr AND status NOT IN ('cancelled','withdrawn','rejected')",
                    ['cr' => $competitionRadeId]
                );
                if ($count >= $capacity) {
                    throw new DomainException('COMPETITION_RADE_FULL', 'Rade full', 422);
                }
            }
            // Duplicate guard: a rider+horse+rade is unique unless the prior
            // row is in a terminal negative state.
            $dupe = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM signups WHERE competition_id = :c AND competition_rade_id = :cr AND rider_user_id = :r AND horse_id = :h AND status NOT IN ('cancelled','withdrawn','rejected')",
                ['c' => (int) $cr['competition_id'], 'cr' => $competitionRadeId, 'r' => (int) $actor['id'], 'h' => $horseId]
            );
            if ($dupe > 0) {
                throw new DomainException('SIGNUP_DUPLICATE', 'Duplicate signup', 409);
            }

            $uuid = uuid4();
            $signupId = $this->db->insert('signups', [
                'uuid' => $uuid,
                'competition_id' => (int) $cr['competition_id'],
                'competition_rade_id' => $competitionRadeId,
                'rade_id' => (int) $cr['rade_id'],
                'rider_user_id' => (int) $actor['id'],
                'horse_id' => $horseId,
                'affiliation_club_id' => $affiliationClubId > 0 ? $affiliationClubId : null,
                'status' => $amount === 0 ? ($cr['auto_confirm'] ? 'confirmed' : 'paid') : 'pending_payment',
                'is_confirmed' => ($amount === 0 && $cr['auto_confirm']) ? 1 : 0,
                'confirmed_by' => null,
                'confirmed_at' => ($amount === 0 && $cr['auto_confirm']) ? $now : null,
                'position' => null,
                'is_winner' => 0,
                'result_notes' => null,
                'payment_id_snapshot' => (int) $cr['payment_id'],
                'payment_name_snapshot' => (string) $cr['payment_name'],
                'payment_amount_irt_snapshot' => $amount,
                'order_uuid' => null,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $orderId = 0;
            $requiresPayment = false;
            if ($amount > 0) {
                $order = $this->payments->createOrder($signupId, $amount, 'شرکت در ' . $cr['rade_name'] . ' - مسابقه #' . $cr['competition_id']);
                $orderId = $order['id'];
                $this->db->update('payment_orders', ['competition_id' => (int) $cr['competition_id'], 'rider_user_id' => (int) $actor['id']], 'id = :id', ['id' => $orderId]);
                $this->db->update('signups', ['order_uuid' => $order['uuid']], 'id = :id', ['id' => $signupId]);
                $requiresPayment = true;
            } else {
                // Free signup: notify immediately.
                $this->notifications?->create((int) $actor['id'], 'signup.created', 'ثبت‌نام انجام شد', 'ثبت‌نام شما در ' . $cr['rade_name'] . ' ثبت شد.', '/panel/rider/signups', 'signup', $signupId);
            }

            $this->notifications?->notifyStaff('signup.created', 'ثبت‌نام جدید', 'یک سوارکار ثبت‌نام جدید انجام داد.', '/panel/signups', 'signup', $signupId);
            if ($this->sms !== null) {
                $u = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => (int) $actor['id']]);
                if ($u !== null && !empty($u['phone'])) {
                    $this->sms->notify((string) $u['phone'], 'sms.notify_on_signup', 'ثبت‌نام شما با موفقیت آغاز شد.');
                }
            }
            $this->record($actor, 'signup.create', $signupId, ['comp_rade_id' => $competitionRadeId, 'amount_irt' => $amount]);
            return [
                'signup_id' => $signupId,
                'uuid' => $uuid,
                'amount_irt' => $amount,
                'order_id' => $orderId,
                'requires_payment' => $requiresPayment,
            ];
        });
    }

    /**
     * Mark a signup as paid after payment success, auto-confirming when configured.
     *
     * Idempotent: if the signup is already `paid` or `confirmed`, returns the
     * current state without regressing it.
     *
     * @param int $signupId Signup id.
     * @return array{signup_id:int,status:string,auto_confirmed:bool}
     * @throws NotFoundException SIGNUP_NOT_FOUND.
     *
     * Side effects: updates signups; notifies rider + staff; SMS. Transaction: yes.
     */
    public function markPaid(int $signupId): array
    {
        $signup = $this->db->selectOne(
            'SELECT s.*, cr.auto_confirm FROM signups s JOIN competition_rades cr ON cr.id = s.competition_rade_id WHERE s.id = :id',
            ['id' => $signupId]
        );
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }

        // Idempotency: never regress an already-paid/confirmed signup.
        $current = (string) $signup['status'];
        if ($current === 'confirmed') {
            return ['signup_id' => $signupId, 'status' => 'confirmed', 'auto_confirmed' => true];
        }
        if ($current === 'paid') {
            return ['signup_id' => $signupId, 'status' => 'paid', 'auto_confirmed' => false];
        }
        if (in_array($current, ['cancelled', 'withdrawn', 'rejected'], true)) {
            // Terminal negative state; do not overwrite with a late payment.
            return ['signup_id' => $signupId, 'status' => $current, 'auto_confirmed' => false];
        }

        $now = now_utc();
        $autoConfirm = (int) $signup['auto_confirm'] === 1;

        return $this->db->transaction(function () use ($signupId, $signup, $autoConfirm, $now): array {
            $status = $autoConfirm ? 'confirmed' : 'paid';
            $this->db->update('signups', [
                'status' => $status,
                'is_confirmed' => $autoConfirm ? 1 : 0,
                'confirmed_at' => $autoConfirm ? $now : null,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $signupId]);
            $this->notifications?->create((int) $signup['rider_user_id'], 'payment.received', 'پرداخت موفق', 'پرداخت شما با موفقیت انجام شد.', '/panel/rider/signups', 'signup', $signupId);
            $this->notifications?->notifyStaff('payment.received', 'پرداخت جدید', 'یک پرداخت جدید دریافت شد.', '/panel/payment-orders', 'signup', $signupId);
            if ($this->sms !== null) {
                $u = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => (int) $signup['rider_user_id']]);
                if ($u !== null && !empty($u['phone'])) {
                    $this->sms->notify((string) $u['phone'], 'sms.notify_on_payment', 'پرداخت شما با موفقیت انجام شد.');
                }
            }
            return ['signup_id' => $signupId, 'status' => $status, 'auto_confirmed' => $autoConfirm];
        });
    }

    /**
     * Manually confirm a paid signup.
     *
     * @param int   $signupId  Signup id.
     * @param array $actor     Actor (admin/manager).
     * @return array{signup_id:int,confirmed_at:string}
     * @throws NotFoundException|ForbiddenException|DomainException SIGNUP_INVALID_STATE.
     *
     * Side effects: updates signups; notifies rider; SMS; audit + changelog. Transaction: yes.
     */
    public function confirm(int $signupId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $signup = $this->db->selectOne('SELECT * FROM signups WHERE id = :id', ['id' => $signupId]);
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }
        if (!in_array($signup['status'], ['paid', 'confirmed'], true)) {
            throw new DomainException('SIGNUP_INVALID_STATE', 'Invalid signup state', 422);
        }
        if ($signup['status'] === 'confirmed') {
            return ['signup_id' => $signupId, 'confirmed_at' => (string) $signup['confirmed_at']];
        }
        $now = now_utc();
        return $this->db->transaction(function () use ($signupId, $signup, $now, $actor): array {
            $this->db->update('signups', [
                'status' => 'confirmed',
                'is_confirmed' => 1,
                'confirmed_by' => (int) $actor['id'],
                'confirmed_at' => $now,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $signupId]);
            $this->notifications?->create((int) $signup['rider_user_id'], 'signup.confirmed', 'ثبت‌نام تایید شد', 'ثبت‌نام شما تایید شد.', '/panel/rider/signups', 'signup', $signupId);
            if ($this->sms !== null) {
                $u = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => (int) $signup['rider_user_id']]);
                if ($u !== null && !empty($u['phone'])) {
                    $this->sms->notify((string) $u['phone'], 'sms.notify_on_confirmation', 'ثبت‌نام شما تایید شد.');
                }
            }
            $this->record($actor, 'signup.confirm', $signupId);
            $this->log->changelog([
                'actor_id' => $actor['id'],
                'actor_role' => $actor['role'],
                'action' => 'signup.confirm',
                'target_type' => 'signup',
                'target_id' => $signupId,
                'summary' => 'تایید ثبت‌نام #' . $signupId,
                'link' => '/panel/signups/' . $signupId,
            ]);
            return ['signup_id' => $signupId, 'confirmed_at' => $now];
        });
    }

    /**
     * Reject a signup (from paid or confirmed).
     *
     * @param int    $signupId Signup id.
     * @param string $reason   Reason.
     * @param array  $actor    Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException|DomainException SIGNUP_INVALID_STATE.
     */
    public function reject(int $signupId, string $reason, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $signup = $this->db->selectOne('SELECT * FROM signups WHERE id = :id', ['id' => $signupId]);
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }
        if (!in_array($signup['status'], ['paid', 'confirmed'], true)) {
            throw new DomainException('SIGNUP_INVALID_STATE', 'Invalid signup state', 422);
        }
        $now = now_utc();
        $this->db->update('signups', ['status' => 'rejected', 'is_confirmed' => 0, 'result_notes' => $reason, 'updated_at' => $now], 'id = :id', ['id' => $signupId]);
        $this->notifications?->create((int) $signup['rider_user_id'], 'signup.rejected', 'ثبت‌نام رد شد', 'ثبت‌نام شما رد شد.' . ($reason !== '' ? ' دلیل: ' . $reason : ''), '/panel/rider/signups', 'signup', $signupId);
        if ($this->sms !== null) {
            $u = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => (int) $signup['rider_user_id']]);
            if ($u !== null && !empty($u['phone'])) {
                $this->sms->notify((string) $u['phone'], 'sms.notify_on_confirmation', 'ثبت‌نام شما رد شد.');
            }
        }
        $this->record($actor, 'signup.reject', $signupId, ['reason' => $reason]);
    }

    /**
     * Cancel a signup (from pending_payment or paid, before confirmation).
     *
     * @param int   $signupId Signup id.
     * @param array $actor    Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException|DomainException SIGNUP_INVALID_STATE.
     */
    public function cancel(int $signupId, array $actor): void
    {
        $signup = $this->db->selectOne('SELECT * FROM signups WHERE id = :id', ['id' => $signupId]);
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }
        $isOwner = (int) $signup['rider_user_id'] === (int) $actor['id'];
        if (!$isOwner && !in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        if (!in_array($signup['status'], ['pending_payment', 'paid'], true)) {
            throw new DomainException('SIGNUP_INVALID_STATE', 'Invalid signup state', 422);
        }
        $this->db->update('signups', ['status' => 'cancelled', 'is_confirmed' => 0, 'updated_at' => now_utc()], 'id = :id', ['id' => $signupId]);
        $this->record($actor, 'signup.cancel', $signupId);
    }

    /**
     * Withdraw a confirmed signup (rider chooses not to race).
     *
     * @param int   $signupId Signup id.
     * @param array $actor    Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException|DomainException SIGNUP_INVALID_STATE.
     */
    public function withdraw(int $signupId, array $actor): void
    {
        $signup = $this->db->selectOne('SELECT * FROM signups WHERE id = :id', ['id' => $signupId]);
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }
        $isOwner = (int) $signup['rider_user_id'] === (int) $actor['id'];
        if (!$isOwner && !in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        if ($signup['status'] !== 'confirmed') {
            throw new DomainException('SIGNUP_INVALID_STATE', 'Only confirmed signups can be withdrawn', 422);
        }
        $this->db->update('signups', ['status' => 'withdrawn', 'is_confirmed' => 0, 'updated_at' => now_utc()], 'id = :id', ['id' => $signupId]);
        $this->record($actor, 'signup.withdraw', $signupId);
    }

    /**
     * List signups with filters, scoped by role.
     *
     * @param array $filters {competition_id?,rade_id?,rider_user_id?,horse_id?,club_id?,status?,from?,to?,search?}
     * @param array $actor   Actor.
     * @param int   $page    Page.
     * @param int   $perPage Page size.
     * @return array{rows:array,total:int,page:int,per_page:int}
     */
    public function list(array $filters, array $actor, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        if ($actor['role'] === 'rider') {
            $where[] = 's.rider_user_id = :me';
            $params['me'] = (int) $actor['id'];
        } elseif ($actor['role'] === 'club') {
            $club = $this->db->selectOne('SELECT id FROM clubs WHERE user_id = :u', ['u' => (int) $actor['id']]);
            if ($club !== null) {
                $where[] = '(s.affiliation_club_id = :club OR s.competition_id IN (SELECT id FROM competitions WHERE venue_club_id = :club2))';
                $params['club'] = (int) $club['id'];
                $params['club2'] = (int) $club['id'];
            }
        }
        foreach (['competition_id' => 's.competition_id', 'rade_id' => 's.rade_id', 'rider_user_id' => 's.rider_user_id', 'horse_id' => 's.horse_id', 'club_id' => 's.affiliation_club_id'] as $key => $col) {
            if (!empty($filters[$key])) {
                $where[] = $col . ' = :' . $key;
                $params[$key] = (int) $filters[$key];
            }
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = 's.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 's.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 's.created_at <= :to';
            $params['to'] = $filters['to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(h.name LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q)';
            $params['q'] = '%' . $filters['search'] . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM signups s JOIN horses h ON h.id = s.horse_id JOIN users u ON u.id = s.rider_user_id WHERE ' . $whereSql,
            $params
        );
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT s.*, r.name AS rade_name, c.title AS competition_title, h.name AS horse_name,
                    (u.first_name || ' ' || u.last_name) AS rider_name, cl.name AS club_name
             FROM signups s
             JOIN rades r ON r.id = s.rade_id
             JOIN competitions c ON c.id = s.competition_id
             JOIN horses h ON h.id = s.horse_id
             JOIN users u ON u.id = s.rider_user_id
             LEFT JOIN clubs cl ON cl.id = s.affiliation_club_id
             WHERE " . $whereSql . ' ORDER BY s.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch a signup with joined detail, scoped by role.
     *
     * @param int   $id    Signup id.
     * @param array $actor Actor.
     * @return array
     * @throws NotFoundException SIGNUP_NOT_FOUND.
     * @throws ForbiddenException
     */
    public function get(int $id, array $actor): array
    {
        $signup = $this->db->selectOne(
            "SELECT s.*, r.name AS rade_name, c.title AS competition_title, h.name AS horse_name,
                    (u.first_name || ' ' || u.last_name) AS rider_name, cl.name AS club_name
             FROM signups s
             JOIN rades r ON r.id = s.rade_id
             JOIN competitions c ON c.id = s.competition_id
             JOIN horses h ON h.id = s.horse_id
             JOIN users u ON u.id = s.rider_user_id
             LEFT JOIN clubs cl ON cl.id = s.affiliation_club_id
             WHERE s.id = :id",
            ['id' => $id]
        );
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }
        if ($actor['role'] === 'rider' && (int) $signup['rider_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        return $signup;
    }

    /**
     * Set a signup's result position and winner flag.
     *
     * @param int   $signupId Signup id.
     * @param int|null $position Position (>=1) or null.
     * @param bool  $isWinner Winner flag.
     * @param string $notes   Result notes.
     * @param array $actor    Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException|ValidationException
     */
    public function setPosition(int $signupId, ?int $position, bool $isWinner, string $notes, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $signup = $this->db->selectOne('SELECT * FROM signups WHERE id = :id', ['id' => $signupId]);
        if ($signup === null) {
            throw new NotFoundException('Signup not found', 'SIGNUP_NOT_FOUND');
        }
        if ($position !== null && $position < 1) {
            throw new ValidationException('Position must be >= 1', 'position', 'VALIDATION_FAILED');
        }
        $this->db->update('signups', [
            'position' => $position,
            'is_winner' => $isWinner ? 1 : 0,
            'result_notes' => $notes,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $signupId]);
        $this->record($actor, 'signup.position', $signupId, ['position' => $position, 'is_winner' => $isWinner]);
    }

    /**
     * Bulk confirm or reject signups.
     *
     * @param array  $ids    Signup ids.
     * @param string $action confirm|reject.
     * @param array  $actor  Actor.
     * @return int Number processed.
     */
    public function bulk(array $ids, string $action, array $actor): int
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $count = 0;
        foreach ($ids as $id) {
            try {
                if ($action === 'confirm') {
                    $this->confirm((int) $id, $actor);
                } elseif ($action === 'reject') {
                    $this->reject((int) $id, '', $actor);
                }
                $count++;
            } catch (\Throwable) {
                // Skip rows that cannot transition; report the successful count.
            }
        }
        return $count;
    }

    /**
     * Count signups matching a filter set.
     *
     * @param array $filters Filters.
     * @return int
     */
    public function count(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :s';
            $params['s'] = $filters['status'];
        }
        if (!empty($filters['competition_id'])) {
            $where[] = 'competition_id = :c';
            $params['c'] = (int) $filters['competition_id'];
        }
        if (!empty($filters['rider_user_id'])) {
            $where[] = 'rider_user_id = :r';
            $params['r'] = (int) $filters['rider_user_id'];
        }
        return (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE ' . implode(' AND ', $where), $params);
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Signup id.
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
            'target_type' => 'signup',
            'target_id' => $id,
            'diff' => $diff,
        ]);
    }
}