<?php
declare(strict_types=1);

/**
 * File: app/Services/BanService.php
 *
 * Purpose:
 *   Admin/Manager bans on riders and horses (global, per-competition, per-rade).
 *   Priority: Rider > Competition > Rade (highest wins). Also exposes the ban
 *   check used by the signup flow (Blueprint §4.3, §8.2.5, §17.8).
 *
 * Dependencies: Database, SettingService, LogService, NotificationService, SmsService.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Class: BanService
 *
 * Purpose: Manage admin/manager bans and evaluate them for signups.
 */
final class BanService
{
    private Database $db;
    private LogService $log;
    private ?NotificationService $notifications;
    private ?SmsService $sms;

    /**
     * @param Database                 $db            Main DB.
     * @param LogService               $log           Logs.
     * @param NotificationService|null $notifications Notifications.
     * @param SmsService|null          $sms           SMS.
     */
    public function __construct(Database $db, LogService $log, ?NotificationService $notifications = null, ?SmsService $sms = null)
    {
        $this->db = $db;
        $this->log = $log;
        $this->notifications = $notifications;
        $this->sms = $sms;
    }

    /**
     * List active bans with optional filters.
     *
     * @param array $filters {target_type?,scope?,search?}
     * @return array<int,array>
     */
    public function list(array $filters = []): array
    {
        $where = ['b.is_active = 1'];
        $params = [];
        if (!empty($filters['target_type'])) { $where[] = 'b.target_type = :tt'; $params['tt'] = $filters['target_type']; }
        if (!empty($filters['scope'])) { $where[] = 'b.scope = :sc'; $params['sc'] = $filters['scope']; }
        return $this->db->select(
            "SELECT b.*, u.username AS banned_by_username,
                    CASE WHEN b.target_type = 'rider' THEN (SELECT first_name || ' ' || last_name FROM users WHERE id = b.target_id)
                         ELSE (SELECT name FROM horses WHERE id = b.target_id) END AS target_name
             FROM rider_bans b LEFT JOIN users u ON u.id = b.banned_by
             WHERE " . implode(' AND ', $where) . ' ORDER BY b.id DESC',
            $params
        );
    }

    /**
     * Create a ban.
     *
     * @param array $input {target_type:rider|horse,target_id:int,scope:global|competition|rade,competition_id?,rade_id?,reason?,expires_at?}
     * @param array $actor Actor.
     * @return array{id:int}
     * @throws ForbiddenException|ValidationException|NotFoundException
     *
     * Side effects: inserts rider_bans; notifies target; SMS; audit.
     */
    public function create(array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $targetType = (string) ($input['target_type'] ?? 'rider');
        if (!in_array($targetType, ['rider', 'horse'], true)) { throw new ValidationException('Invalid target type', 'target_type', 'VALIDATION_FAILED'); }
        $scope = (string) ($input['scope'] ?? 'global');
        if (!in_array($scope, ['global', 'competition', 'rade'], true)) { throw new ValidationException('Invalid scope', 'scope', 'VALIDATION_FAILED'); }
        if ($scope === 'competition' && empty($input['competition_id'])) { throw new ValidationException('Competition required', 'competition_id', 'VALIDATION_FAILED'); }
        if ($scope === 'rade' && empty($input['rade_id'])) { throw new ValidationException('Rade required', 'rade_id', 'VALIDATION_FAILED'); }
        $targetId = (int) ($input['target_id'] ?? 0);
        if ($targetId <= 0) { throw new ValidationException('Target required', 'target_id', 'VALIDATION_FAILED'); }

        $now = now_utc();
        $id = $this->db->insert('rider_bans', [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'scope' => $scope,
            'competition_id' => $input['competition_id'] ?? null,
            'rade_id' => $input['rade_id'] ?? null,
            'reason' => $input['reason'] ?? null,
            'banned_by' => (int) $actor['id'],
            'expires_at' => $input['expires_at'] ?? null,
            'is_active' => 1,
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($targetType === 'rider') {
            $this->notifications?->create($targetId, 'ban.applied', 'تحریم', 'شما تحریم شده‌اید.', '/panel/profile', 'rider_ban', $id);
            if ($this->sms !== null) {
                $u = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => $targetId]);
                if ($u !== null && !empty($u['phone'])) {
                    $this->sms->notify((string) $u['phone'], 'sms.notify_on_ban', 'شما تحریم شده‌اید. برای اطلاعات با پشتیبانی تماس بگیرید.');
                }
            }
        }
        $this->record($actor, 'ban.create', $id, ['target_type' => $targetType, 'scope' => $scope]);
        return ['id' => $id];
    }

    /**
     * Deactivate a ban.
     *
     * @param int   $id    Ban id.
     * @param array $actor Actor.
     * @return void
     * @throws ForbiddenException
     */
    public function remove(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->db->update('rider_bans', ['is_active' => 0, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        $this->record($actor, 'ban.remove', $id);
    }

    /**
     * Evaluate whether a rider/horse is banned for a competition-rade.
     *
     * @param int $riderId       Rider id.
     * @param int $horseId       Horse id.
     * @param int $competitionId Competition id.
     * @param int $radeId        Rade id.
     * @return string|null Error code (SIGNUP_RIDER_BANNED | SIGNUP_HORSE_BANNED) or null.
     */
    public function evaluate(int $riderId, int $horseId, int $competitionId, int $radeId): ?string
    {
        $now = now_utc();
        $riders = $this->db->select(
            "SELECT scope, competition_id, rade_id FROM rider_bans
             WHERE target_type = 'rider' AND target_id = :id AND is_active = 1 AND (expires_at IS NULL OR expires_at > :now)",
            ['id' => $riderId, 'now' => $now]
        );
        if ($this->matches($riders, $competitionId, $radeId)) { return 'SIGNUP_RIDER_BANNED'; }
        $horses = $this->db->select(
            "SELECT scope, competition_id, rade_id FROM rider_bans
             WHERE target_type = 'horse' AND target_id = :id AND is_active = 1 AND (expires_at IS NULL OR expires_at > :now)",
            ['id' => $horseId, 'now' => $now]
        );
        if ($this->matches($horses, $competitionId, $radeId)) { return 'SIGNUP_HORSE_BANNED'; }
        return null;
    }

    /**
     * Determine whether any ban row applies to the given competition/rade.
     *
     * @param array $bans          Ban rows.
     * @param int   $competitionId Competition id.
     * @param int   $radeId        Rade id.
     * @return bool
     */
    private function matches(array $bans, int $competitionId, int $radeId): bool
    {
        foreach ($bans as $ban) {
            if ($ban['scope'] === 'global') { return true; }
            if ($ban['scope'] === 'competition' && (int) $ban['competition_id'] === $competitionId) { return true; }
            if ($ban['scope'] === 'rade' && (int) $ban['rade_id'] === $radeId) { return true; }
        }
        return false;
    }

    /**
     * Count active bans.
     *
     * @return int
     */
    public function activeCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM rider_bans WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > :now)', ['now' => now_utc()]);
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Ban id.
     * @param array  $diff   Diff.
     * @return void
     */
    private function record(array $actor, string $action, int $id, array $diff = []): void
    {
        $this->log->audit([
            'actor_id' => $actor['id'] ?? null, 'actor_role' => $actor['role'] ?? null,
            'action' => $action, 'target_type' => 'rider_ban', 'target_id' => $id, 'diff' => $diff,
        ]);
    }
}
