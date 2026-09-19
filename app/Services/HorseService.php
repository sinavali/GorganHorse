<?php
declare(strict_types=1);

/**
 * File: app/Services/HorseService.php
 *
 * Purpose:
 *   Horse lifecycle: CRUD, ownership, gallery images, shares, transfers,
 *   soft-delete / sold-to-non-rider, and CSV import/export. Enforces ownership
 *   scoping for riders and microchip uniqueness (Blueprint §8.2.2–8.2.3,
 *   Technical §17.5).
 *
 *   Clubs are explicitly denied access to horse records (Blueprint §12
 *   authorization matrix).
 *
 * Dependencies: Database, SettingService, LogService, MediaService, NotificationService, SmsService.
 *
 * Conventions:
 *   - A horse belongs to exactly one rider (owner_user_id).
 *   - Shares deactivate rows (no hard delete).
 *   - Transfers lock the horse and reset the share code.
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
 * Class: HorseService
 *
 * Purpose: Manage horses, images, shares, and transfers.
 */
final class HorseService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private MediaService $media;
    private ?NotificationService $notifications;
    private ?SmsService $sms;

    /**
     * @param Database                 $db            Main DB.
     * @param SettingService           $settings      Settings.
     * @param LogService               $log           Logs.
     * @param MediaService             $media         Media.
     * @param NotificationService|null $notifications Notifications.
     * @param SmsService|null          $sms           SMS.
     */
    public function __construct(Database $db, SettingService $settings, LogService $log, MediaService $media, ?NotificationService $notifications = null, ?SmsService $sms = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
        $this->media = $media;
        $this->notifications = $notifications;
        $this->sms = $sms;
    }

    /**
     * List horses with role-based scoping.
     *
     * @param array $filters {status?,gender?,race?,color?,owner_user_id?,microchip?,search?}
     * @param array $actor   {id,role}
     * @param int   $page    Page.
     * @param int   $perPage Page size.
     * @return array{rows:array,total:int,page:int,per_page:int}
     * @throws ForbiddenException Clubs are not permitted to list horse records.
     */
    public function list(array $filters, array $actor, int $page = 1, int $perPage = 25): array
    {
        if (($actor['role'] ?? '') === 'club') {
            throw new ForbiddenException('Clubs may not access horse records', 'FORBIDDEN');
        }
        $where = ['1=1'];
        $params = [];
        if ($actor['role'] === 'rider') {
            $where[] = '(h.owner_user_id = :me OR h.id IN (SELECT horse_id FROM horse_shares WHERE recipient_user_id = :me2 AND status IN (\'pending\',\'accepted\')))';
            $params['me'] = (int) $actor['id'];
            $params['me2'] = (int) $actor['id'];
        } elseif (!empty($filters['owner_user_id'])) {
            $where[] = 'h.owner_user_id = :owner';
            $params['owner'] = (int) $filters['owner_user_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') { $where[] = 'h.status = :status'; $params['status'] = $filters['status']; }
        else { $where[] = "h.status != 'soft_deleted'"; }
        foreach (['gender', 'race', 'color'] as $field) {
            if (!empty($filters[$field])) { $where[] = "h.$field = :$field"; $params[$field] = $filters[$field]; }
        }
        if (!empty($filters['microchip'])) { $where[] = 'h.microchip_number LIKE :mc'; $params['mc'] = '%' . $filters['microchip'] . '%'; }
        if (!empty($filters['search'])) { $where[] = 'h.name LIKE :q'; $params['q'] = '%' . $filters['search'] . '%'; }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM horses h WHERE ' . $whereSql, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT h.*, (u.first_name || ' ' || u.last_name) AS owner_name, u.username AS owner_username
             FROM horses h JOIN users u ON u.id = h.owner_user_id
             WHERE " . $whereSql . ' ORDER BY h.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch a horse, enforcing rider ownership/share scoping.
     *
     * @param int   $id    Horse id.
     * @param array $actor Actor.
     * @return array
     * @throws NotFoundException HORSE_NOT_FOUND.
     * @throws ForbiddenException HORSE_NOT_OWNED | clubs are not permitted.
     */
    public function get(int $id, array $actor): array
    {
        if (($actor['role'] ?? '') === 'club') {
            throw new ForbiddenException('Clubs may not access horse records', 'FORBIDDEN');
        }
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider') {
            $owns = (int) $horse['owner_user_id'] === (int) $actor['id'];
            $shared = $this->hasShare($id, (int) $actor['id']);
            if (!$owns && !$shared) {
                throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
            }
        }
        return $horse;
    }

    /**
     * Create a horse (owner = actor for riders; explicit owner for staff).
     *
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int,uuid:string}
     * @throws ForbiddenException|ValidationException|DomainException
     *
     * Side effects: inserts horses; audit + changelog. Transaction: yes.
     */
    public function create(array $input, array $actor): array
    {
        $ownerId = (int) ($input['owner_user_id'] ?? ($actor['role'] === 'rider' ? $actor['id'] : 0));
        if ($actor['role'] === 'rider') {
            if (($actor['verification_status'] ?? '') === 'pending') {
                throw new DomainException('USER_NOT_VERIFIED', 'Account not verified', 403);
            }
            $ownerId = (int) $actor['id'];
        } elseif (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        if ($ownerId <= 0) { throw new ValidationException('Owner is required', 'owner_user_id', 'VALIDATION_FAILED'); }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') { throw new ValidationException('Horse name is required', 'name', 'VALIDATION_FAILED'); }

        $microchip = isset($input['microchip_number']) ? trim((string) $input['microchip_number']) : '';
        if ($microchip !== '') {
            if (preg_match('/^\d{15}$/', normalize_digits($microchip)) !== 1) {
                throw new ValidationException('Microchip must be 15 digits', 'microchip_number', 'VALIDATION_FAILED');
            }
            $microchip = normalize_digits($microchip);
            $dup = (int) $this->db->scalar("SELECT COUNT(*) FROM horses WHERE microchip_number = :m AND status != 'soft_deleted'", ['m' => $microchip]);
            if ($dup > 0) { throw new DomainException('HORSE_MICROCHIP_TAKEN', 'Microchip already registered', 409, 'microchip_number'); }
        }
        $now = now_utc();
        return $this->db->transaction(function () use ($input, $ownerId, $name, $microchip, $now, $actor): array {
            $uuid = uuid4();
            $id = $this->db->insert('horses', [
                'uuid' => $uuid,
                'owner_user_id' => $ownerId,
                'name' => $name,
                'name_en' => $input['name_en'] ?? null,
                'microchip_number' => $microchip !== '' ? $microchip : null,
                'ueln' => $input['ueln'] ?? null,
                'gender' => $input['gender'] ?? null,
                'race' => $input['race'] ?? null,
                'color' => $input['color'] ?? null,
                'birth_date' => $input['birth_date'] ?? null,
                'ghamari_birthday' => $input['ghamari_birthday'] ?? null,
                'sire_name' => $input['sire_name'] ?? null,
                'dam_name' => $input['dam_name'] ?? null,
                'breeder' => $input['breeder'] ?? null,
                'registration_no' => $input['registration_no'] ?? null,
                'status' => 'active',
                'transfer_locked' => 0,
                'share_code' => random_digits((int) $this->settings->get('horses.share_code_length', 6)),
                'notes' => $input['notes'] ?? null,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->record($actor, 'horse.create', $id, ['name' => $name, 'owner_user_id' => $ownerId]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'horse.create',
                'target_type' => 'horse', 'target_id' => $id, 'summary' => 'افزودن اسب: ' . $name,
                'link' => '/panel/horses/' . $id,
            ]);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /**
     * Update a horse (owner or staff).
     *
     * @param int   $id    Horse id.
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|DomainException
     */
    public function update(int $id, array $input, array $actor): array
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        if ($actor['role'] === 'club') { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }

        $data = [];
        $fields = ['name', 'name_en', 'ueln', 'gender', 'race', 'color', 'birth_date', 'ghamari_birthday', 'sire_name', 'dam_name', 'breeder', 'registration_no', 'notes'];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field]; }
        }
        if (array_key_exists('microchip_number', $input) && $input['microchip_number'] !== '' && $input['microchip_number'] !== null) {
            $microchip = normalize_digits((string) $input['microchip_number']);
            if (preg_match('/^\d{15}$/', $microchip) !== 1) { throw new ValidationException('Microchip must be 15 digits', 'microchip_number', 'VALIDATION_FAILED'); }
            $dup = (int) $this->db->scalar("SELECT COUNT(*) FROM horses WHERE microchip_number = :m AND id != :id AND status != 'soft_deleted'", ['m' => $microchip, 'id' => $id]);
            if ($dup > 0) { throw new DomainException('HORSE_MICROCHIP_TAKEN', 'Microchip already registered', 409, 'microchip_number'); }
            $data['microchip_number'] = $microchip;
        }
        $data['updated_at'] = now_utc();
        $this->db->update('horses', $data, 'id = :id', ['id' => $id]);
        $this->record($actor, 'horse.update', $id, $data);
        return ['id' => $id];
    }

    /**
     * Change a horse's status (soft delete / restore / sold-to-non-rider).
     *
     * @param int    $id     Horse id.
     * @param string $status active|sold_to_non_rider|soft_deleted.
     * @param array  $actor  Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException|ValidationException
     */
    public function setStatus(int $id, string $status, array $actor): void
    {
        if (!in_array($status, ['active', 'sold_to_non_rider', 'soft_deleted'], true)) {
            throw new ValidationException('Invalid status', 'status', 'VALIDATION_FAILED');
        }
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        if ($actor['role'] === 'club') { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->db->update('horses', ['status' => $status, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        $this->record($actor, 'horse.status', $id, ['status' => $status]);
    }

    /**
     * Add an image to a horse gallery (max from settings).
     *
     * @param int   $id    Horse id.
     * @param array $file  $_FILES entry.
     * @param array $actor Actor.
     * @return array{id:int,media_id:int,path:string}
     * @throws NotFoundException|ForbiddenException|ValidationException
     *
     * Side effects: writes a file, inserts media + horse_images rows; audit.
     */
    public function addImage(int $id, array $file, array $actor): array
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $max = (int) $this->settings->get('horses.max_images', 5);
        if ($this->media->horseImageCount($id) >= $max) {
            throw new ValidationException('Maximum images reached', 'file', 'VALIDATION_FAILED');
        }
        $stored = $this->media->store($file, 'horse', (int) $actor['id'], 'horses/' . $id);
        $sort = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0)+1 FROM horse_images WHERE horse_id = :h', ['h' => $id]);
        $linkId = $this->db->insert('horse_images', [
            'horse_id' => $id,
            'media_id' => $stored['id'],
            'sort_order' => $sort,
            'is_demo' => 0,
            'created_at' => now_utc(),
        ]);
        $this->record($actor, 'horse.image.add', $id, ['media_id' => $stored['id']]);
        return ['id' => $linkId, 'media_id' => $stored['id'], 'path' => $stored['path']];
    }

    /**
     * Remove a horse image.
     *
     * @param int   $id      Horse id.
     * @param int   $mediaId Media id.
     * @param array $actor   Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException
     */
    public function removeImage(int $id, int $mediaId, array $actor): void
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $this->db->delete('horse_images', 'horse_id = :h AND media_id = :m', ['h' => $id, 'm' => $mediaId]);
        $this->media->delete($mediaId);
        $this->record($actor, 'horse.image.remove', $id, ['media_id' => $mediaId]);
    }

    /**
     * Share a horse to another rider using the other rider's personal code.
     *
     * @param int    $id         Horse id.
     * @param string $shareCode  Recipient's 6-digit personal share code.
     * @param array  $actor      Actor.
     * @return array{id:int,recipient_user_id:int}
     * @throws NotFoundException|ForbiddenException|DomainException SHARE_CODE_INVALID.
     *
     * Side effects: inserts horse_shares; notifies recipient; audit.
     */
    public function share(int $id, string $shareCode, array $actor): array
    {
        if (!(bool) $this->settings->get('horses.allow_sharing', true)) {
            throw new ForbiddenException('Sharing is disabled', 'FORBIDDEN');
        }
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $code = normalize_digits(trim($shareCode));
        $recipient = $this->db->selectOne(
            "SELECT rp.user_id FROM rider_profiles rp JOIN users u ON u.id = rp.user_id
             WHERE rp.my_share_code = :c AND u.role = 'rider'",
            ['c' => $code]
        );
        if ($recipient === null) {
            throw new DomainException('SHARE_CODE_INVALID', 'Invalid share code', 422, 'share_code');
        }
        $recipientId = (int) $recipient['user_id'];
        if ($recipientId === (int) $horse['owner_user_id']) {
            throw new ValidationException('You cannot share a horse with yourself', 'share_code', 'VALIDATION_FAILED');
        }
        $existing = $this->db->selectOne(
            'SELECT id FROM horse_shares WHERE horse_id = :h AND recipient_user_id = :r AND status IN (\'pending\',\'accepted\')',
            ['h' => $id, 'r' => $recipientId]
        );
        if ($existing !== null) {
            throw new DomainException('SIGNUP_DUPLICATE', 'Horse already shared to this rider', 409, 'share_code');
        }
        $now = now_utc();
        $shareId = $this->db->insert('horse_shares', [
            'horse_id' => $id,
            'owner_user_id' => (int) $horse['owner_user_id'],
            'recipient_user_id' => $recipientId,
            'status' => 'pending',
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->notifications?->create($recipientId, 'horse.shared', 'اسب به شما اشتراک داده شد', 'مالک اسب ' . $horse['name'] . ' را با شما به اشتراک گذاشت.', '/panel/horse-shares', 'horse_share', $shareId);
        $this->record($actor, 'horse.share', $id, ['recipient_user_id' => $recipientId]);
        return ['id' => $shareId, 'recipient_user_id' => $recipientId];
    }

    /**
     * Revoke a share.
     *
     * @param int   $id      Horse id.
     * @param int   $shareId Share id.
     * @param array $actor   Actor.
     * @return void
     */
    public function revokeShare(int $id, int $shareId, array $actor): void
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] === 'rider' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $this->db->update('horse_shares', ['status' => 'revoked', 'updated_at' => now_utc()], 'id = :id AND horse_id = :h', ['id' => $shareId, 'h' => $id]);
        $this->record($actor, 'horse.share.revoke', $id, ['share_id' => $shareId]);
    }

    /**
     * Whether an active share exists to a rider.
     *
     * @param int $horseId Horse id.
     * @param int $userId  Recipient rider.
     * @return bool
     */
    public function hasShare(int $horseId, int $userId): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM horse_shares WHERE horse_id = :h AND recipient_user_id = :u AND status IN ('pending','accepted')",
            ['h' => $horseId, 'u' => $userId]
        ) > 0;
    }

    /**
     * List shares received by a rider (with owner info).
     *
     * @param int $userId Recipient rider.
     * @return array<int,array>
     */
    public function sharesForRecipient(int $userId): array
    {
        return $this->db->select(
            "SELECT hs.*, h.name AS horse_name, h.microchip_number, (u.first_name || ' ' || u.last_name) AS owner_name
             FROM horse_shares hs JOIN horses h ON h.id = hs.horse_id JOIN users u ON u.id = hs.owner_user_id
             WHERE hs.recipient_user_id = :u ORDER BY hs.id DESC",
            ['u' => $userId]
        );
    }

    /**
     * List shares owned by a horse.
     *
     * @param int $horseId Horse id.
     * @return array<int,array>
     */
    public function sharesForHorse(int $horseId): array
    {
        return $this->db->select(
            "SELECT hs.*, (u.first_name || ' ' || u.last_name) AS recipient_name
             FROM horse_shares hs JOIN users u ON u.id = hs.recipient_user_id
             WHERE hs.horse_id = :h ORDER BY hs.id DESC",
            ['h' => $horseId]
        );
    }

    /**
     * Accept or reject a received share.
     *
     * @param int    $shareId Share id.
     * @param string $action  accept|reject.
     * @param array  $actor   Actor (recipient).
     * @return void
     * @throws NotFoundException SHARE_NOT_FOUND.
     */
    public function respondToShare(int $shareId, string $action, array $actor): void
    {
        $share = $this->db->selectOne('SELECT * FROM horse_shares WHERE id = :id', ['id' => $shareId]);
        if ($share === null) { throw new NotFoundException('Share not found', 'SHARE_NOT_FOUND'); }
        if ((int) $share['recipient_user_id'] !== (int) $actor['id'] && $actor['role'] !== 'admin') {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $status = $action === 'accept' ? 'accepted' : 'rejected';
        $this->db->update('horse_shares', ['status' => $status, 'responded_at' => now_utc(), 'updated_at' => now_utc()], 'id = :id', ['id' => $shareId]);
        $this->record($actor, 'horse.share.' . $status, (int) $share['horse_id'], ['share_id' => $shareId]);
    }

    /**
     * Initiate a horse transfer: lock horse, reset share code, generate code.
     *
     * @param int   $id    Horse id.
     * @param array $actor Owner.
     * @return array{transfer_id:int,code:string,expires_at:string}
     * @throws NotFoundException|ForbiddenException|DomainException HORSE_TRANSFER_LOCKED.
     *
     * Side effects: updates horses; inserts horse_transfers; audit. Transaction: yes.
     */
    public function initiateTransfer(int $id, array $actor): array
    {
        if (!(bool) $this->settings->get('horses.allow_transfer', true)) {
            throw new ForbiddenException('Transfers are disabled', 'FORBIDDEN');
        }
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] !== 'admin' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        if ((int) $horse['transfer_locked'] === 1) {
            throw new DomainException('HORSE_TRANSFER_LOCKED', 'Horse transfer in progress', 423);
        }
        $length = (int) $this->settings->get('horses.transfer_code_length', 8);
        $days = (int) $this->settings->get('horses.transfer_expiry_days', 7);
        $code = random_alnum($length);
        $now = now_utc();
        $expires = utc_iso(time() + $days * 86400);
        return $this->db->transaction(function () use ($id, $horse, $code, $expires, $now, $actor): array {
            $this->db->update('horses', [
                'transfer_locked' => 1,
                'share_code' => random_digits((int) $this->settings->get('horses.share_code_length', 6)),
                'updated_at' => $now,
            ], 'id = :id', ['id' => $id]);
            $transferId = $this->db->insert('horse_transfers', [
                'horse_id' => $id,
                'from_user_id' => (int) $horse['owner_user_id'],
                'to_user_id' => null,
                'transfer_code' => $code,
                'status' => 'pending',
                'expires_at' => $expires,
                'is_demo' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->record($actor, 'horse.transfer.initiate', $id, ['transfer_id' => $transferId]);
            return ['transfer_id' => $transferId, 'code' => $code, 'expires_at' => $expires];
        });
    }

    /**
     * Submit a transfer code (buyer claims the transfer).
     *
     * @param int    $id      Horse id.
     * @param string $code    Transfer code.
     * @param array  $actor   Initiating rider.
     * @return array{transfer_id:int,owner_user_id:int}
     * @throws DomainException TRANSFER_CODE_INVALID | TRANSFER_EXPIRED.
     */
    public function claimTransfer(int $id, string $code, array $actor): array
    {
        $transfer = $this->db->selectOne(
            "SELECT * FROM horse_transfers WHERE horse_id = :h AND transfer_code = :c AND status = 'pending' ORDER BY id DESC LIMIT 1",
            ['h' => $id, 'c' => strtoupper(trim($code))]
        );
        if ($transfer === null) { throw new DomainException('TRANSFER_CODE_INVALID', 'Invalid transfer code', 422, 'code'); }
        if (strtotime((string) $transfer['expires_at']) < time()) {
            $this->db->update('horse_transfers', ['status' => 'expired', 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $transfer['id']]);
            throw new DomainException('TRANSFER_EXPIRED', 'Transfer code expired', 422, 'code');
        }
        $this->db->update('horse_transfers', ['to_user_id' => (int) $actor['id'], 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $transfer['id']]);
        $this->notifications?->create((int) $transfer['from_user_id'], 'transfer.received', 'درخواست انتقال اسب', 'یک سوارکار برای انتقال اسب شما درخواست داده است.', '/panel/horses/' . $id . '/transfer', 'horse_transfer', (int) $transfer['id']);
        $this->record($actor, 'horse.transfer.claim', $id, ['transfer_id' => (int) $transfer['id']]);
        return ['transfer_id' => (int) $transfer['id'], 'owner_user_id' => (int) $transfer['from_user_id']];
    }

    /**
     * Accept a transfer (owner) → change ownership, unlock horse.
     *
     * @param int   $id    Horse id.
     * @param array $actor Owner.
     * @return array{transfer_id:int,new_owner_user_id:int}
     * @throws NotFoundException|ForbiddenException
     *
     * Side effects: updates horses + horse_transfers; notifies initiator; audit. Transaction: yes.
     */
    public function acceptTransfer(int $id, array $actor): array
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] !== 'admin' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $transfer = $this->db->selectOne(
            "SELECT * FROM horse_transfers WHERE horse_id = :h AND status = 'pending' AND to_user_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            ['h' => $id]
        );
        if ($transfer === null) { throw new NotFoundException('Transfer not found', 'TRANSFER_NOT_FOUND'); }
        $now = now_utc();
        $newOwner = (int) $transfer['to_user_id'];
        return $this->db->transaction(function () use ($id, $transfer, $newOwner, $now, $actor): array {
            $this->db->update('horses', ['owner_user_id' => $newOwner, 'transfer_locked' => 0, 'updated_at' => $now], 'id = :id', ['id' => $id]);
            $this->db->update('horse_transfers', ['status' => 'accepted', 'accepted_at' => $now, 'updated_at' => $now], 'id = :id', ['id' => (int) $transfer['id']]);
            $this->notifications?->create($newOwner, 'transfer.accepted', 'انتقال اسب پذیرفته شد', 'انتقال اسب با موفقیت انجام شد.', '/panel/horses/' . $id, 'horse_transfer', (int) $transfer['id']);
            $this->record($actor, 'horse.transfer.accept', $id, ['transfer_id' => (int) $transfer['id'], 'new_owner' => $newOwner]);
            $this->log->changelog([
                'actor_id' => $actor['id'], 'actor_role' => $actor['role'], 'action' => 'horse.transfer.accept',
                'target_type' => 'horse', 'target_id' => $id, 'summary' => 'انتقال اسب #' . $id,
            ]);
            return ['transfer_id' => (int) $transfer['id'], 'new_owner_user_id' => $newOwner];
        });
    }

    /**
     * Reject a transfer (owner) → code stays valid, horse stays locked.
     *
     * @param int   $id    Horse id.
     * @param array $actor Owner.
     * @return void
     */
    public function rejectTransfer(int $id, array $actor): void
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] !== 'admin' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $transfer = $this->db->selectOne("SELECT * FROM horse_transfers WHERE horse_id = :h AND status = 'pending' ORDER BY id DESC LIMIT 1", ['h' => $id]);
        if ($transfer === null) { throw new NotFoundException('Transfer not found', 'TRANSFER_NOT_FOUND'); }
        $this->db->update('horse_transfers', ['to_user_id' => null, 'status' => 'rejected', 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $transfer['id']]);
        if (!empty($transfer['to_user_id'])) {
            $this->notifications?->create((int) $transfer['to_user_id'], 'transfer.rejected', 'انتقال اسب رد شد', 'درخواست انتقال شما رد شد.', '/panel/horses', 'horse_transfer', (int) $transfer['id']);
        }
        $this->record($actor, 'horse.transfer.reject', $id, ['transfer_id' => (int) $transfer['id']]);
    }

    /**
     * Cancel the active transfer and unlock the horse.
     *
     * @param int   $id    Horse id.
     * @param array $actor Owner.
     * @return void
     */
    public function cancelTransfer(int $id, array $actor): void
    {
        $horse = $this->db->selectOne('SELECT * FROM horses WHERE id = :id', ['id' => $id]);
        if ($horse === null) { throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND'); }
        if ($actor['role'] !== 'admin' && (int) $horse['owner_user_id'] !== (int) $actor['id']) {
            throw new ForbiddenException('Horse not owned', 'HORSE_NOT_OWNED');
        }
        $this->db->update('horse_transfers', ['status' => 'cancelled', 'updated_at' => now_utc()], "horse_id = :h AND status = 'pending'", ['h' => $id]);
        $this->db->update('horses', ['transfer_locked' => 0, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        $this->record($actor, 'horse.transfer.cancel', $id);
    }

    /**
     * List transfers involving a horse.
     *
     * @param int $horseId Horse id.
     * @return array<int,array>
     */
    public function transfersForHorse(int $horseId): array
    {
        return $this->db->select('SELECT * FROM horse_transfers WHERE horse_id = :h ORDER BY id DESC', ['h' => $horseId]);
    }

    /**
     * Horse performance history (signups + results).
     *
     * @param int $horseId Horse id.
     * @return array<int,array>
     */
    public function performanceHistory(int $horseId): array
    {
        return $this->db->select(
            'SELECT s.id, s.position, s.is_winner, s.created_at, c.title AS competition_title, r.name AS rade_name
             FROM signups s JOIN competitions c ON c.id = s.competition_id JOIN rades r ON r.id = s.rade_id
             WHERE s.horse_id = :h AND s.status IN (\'paid\',\'confirmed\')
             ORDER BY c.start_at DESC',
            ['h' => $horseId]
        );
    }

    /**
     * Export horses to CSV (whitelisted columns).
     *
     * @param array $filters Filters.
     * @param array $actor   Actor.
     * @return array{filename:string,content:string}
     */
    public function exportCsv(array $filters, array $actor): array
    {
        $rows = $this->list($filters, $actor, 1, 10000)['rows'];
        $header = ['id', 'name', 'microchip_number', 'gender', 'race', 'color', 'owner_name', 'status'];
        $out = "\xEF\xBB\xBF" . implode(',', $header) . "\n";
        foreach ($rows as $r) {
            $line = [];
            foreach ($header as $col) { $line[] = '"' . str_replace('"', '""', (string) ($r[$col] ?? '')) . '"'; }
            $out .= implode(',', $line) . "\n";
        }
        return ['filename' => 'horses-' . gmdate('Ymd-His') . '.csv', 'content' => $out];
    }

    /**
     * Export a CSV import template.
     *
     * @return array{filename:string,content:string}
     */
    public function exportTemplate(): array
    {
        $header = ['name', 'microchip_number', 'gender', 'race', 'color', 'birth_date', 'sire_name', 'dam_name', 'registration_no'];
        $sample = ['نام اسب', '123456789012345', 'نریان', 'تروبرد', 'قهوه‌ای', '1400/01/01', 'نام پدر', 'نام مادر', 'REG-001'];
        return [
            'filename' => 'horses-import-template.csv',
            'content' => "\xEF\xBB\xBF" . implode(',', $header) . "\n" . implode(',', $sample) . "\n",
        ];
    }

    /**
     * Import horses from a CSV file (header-row mapping).
     *
     * @param array $file  $_FILES entry for the CSV.
     * @param array $actor Actor.
     * @return array{imported:int,errors:array<int,string>}
     * @throws ValidationException IMPORT_FAILED.
     *
     * Side effects: inserts horses (valid rows); audit. Transaction: yes.
     */
    public function importCsv(array $file, array $actor): array
    {
        if (($file['error'] ?? 1) !== 0) { throw new ValidationException('Upload failed', 'file', 'IMPORT_FAILED'); }
        $content = (string) file_get_contents((string) $file['tmp_name']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($content)) ?: []));
        if (count($lines) < 2) { throw new ValidationException('CSV has no data rows', 'file', 'IMPORT_FAILED'); }
        $header = array_map(static fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $ownerId = $actor['role'] === 'rider' ? (int) $actor['id'] : (int) ($actor['owner_user_id'] ?? $actor['id']);
        $imported = 0;
        $errors = [];
        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line);
            $row = array_combine(array_slice($header, 0, count($cols)), $cols) ?: [];
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') { $errors[] = 'سطر ' . ($i + 2) . ': نام اسب خالی است'; continue; }
            try {
                $this->create([
                    'owner_user_id' => $ownerId,
                    'name' => $name,
                    'microchip_number' => $row['microchip_number'] ?? null,
                    'gender' => $row['gender'] ?? null,
                    'race' => $row['race'] ?? null,
                    'color' => $row['color'] ?? null,
                    'birth_date' => $row['birth_date'] ?? null,
                    'sire_name' => $row['sire_name'] ?? null,
                    'dam_name' => $row['dam_name'] ?? null,
                    'registration_no' => $row['registration_no'] ?? null,
                ], $actor);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = 'سطر ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }
        $this->record($actor, 'horse.import', 0, ['imported' => $imported, 'errors' => count($errors)]);
        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Count horses owned by a rider.
     *
     * @param int $userId Rider id.
     * @return int
     */
    public function countOwned(int $userId): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM horses WHERE owner_user_id = :u AND status = 'active'", ['u' => $userId]);
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Horse id.
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
            'target_type' => 'horse',
            'target_id' => $id,
            'diff' => $diff,
            'ip' => $actor['ip'] ?? null,
            'result' => 'ok',
        ]);
    }
}