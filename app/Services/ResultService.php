<?php
declare(strict_types=1);

/**
 * File: app/Services/ResultService.php
 *
 * Purpose:
 *   Results entry workflow for a competition: draft → confirmed → published,
 *   with barrage flags per competition-rade and rider notifications on publish.
 *   Only Admin can reopen after publish (Blueprint §8.2.4, Technical §17.9).
 *
 * Dependencies: Database, SettingService, LogService, NotificationService, SmsService.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;

/**
 * Class: ResultService
 *
 * Purpose: Manage the results workflow and entry grid.
 */
final class ResultService
{
    private Database $db;
    private SettingService $settings;
    private LogService $log;
    private ?NotificationService $notifications;
    private ?SmsService $sms;

    /**
     * @param Database                 $db            Main DB.
     * @param SettingService           $settings      Settings.
     * @param LogService               $log           Logs.
     * @param NotificationService|null $notifications Notifications.
     * @param SmsService|null          $sms           SMS.
     */
    public function __construct(Database $db, SettingService $settings, LogService $log, ?NotificationService $notifications = null, ?SmsService $sms = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->log = $log;
        $this->notifications = $notifications;
        $this->sms = $sms;
    }

    /**
     * Build the results grid: per-rade sections with their signups.
     *
     * @param int   $competitionId Competition id.
     * @param array $actor         Actor.
     * @return array{competition:array,rades:array<int,array>}
     * @throws NotFoundException COMPETITION_NOT_FOUND.
     * @throws ForbiddenException
     */
    public function grid(int $competitionId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $competition = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $competitionId]);
        if ($competition === null) {
            throw new NotFoundException('Competition not found', 'COMPETITION_NOT_FOUND');
        }
        $compRades = $this->db->select(
            'SELECT cr.id, cr.had_barrage, cr.barrage_notes, cr.auto_confirm, r.name AS rade_name, p.name AS payment_name, p.amount_irt
             FROM competition_rades cr JOIN rades r ON r.id = cr.rade_id JOIN payments p ON p.id = cr.payment_id
             WHERE cr.competition_id = :c ORDER BY cr.sort_order ASC, cr.id ASC',
            ['c' => $competitionId]
        );
        $rades = [];
        foreach ($compRades as $cr) {
            $signups = $this->db->select(
                "SELECT s.id, s.position, s.is_winner, s.result_notes, s.status,
                        h.name AS horse_name, cl.name AS club_name,
                        (u.first_name || ' ' || u.last_name) AS rider_name
                 FROM signups s JOIN horses h ON h.id = s.horse_id JOIN users u ON u.id = s.rider_user_id
                 LEFT JOIN clubs cl ON cl.id = s.affiliation_club_id
                 WHERE s.competition_rade_id = :cr AND s.status = 'confirmed'
                 ORDER BY s.id ASC",
                ['cr' => (int) $cr['id']]
            );
            $cr['signups'] = $signups;
            $rades[] = $cr;
        }
        return ['competition' => $competition, 'rades' => $rades];
    }

    /**
     * Save draft results (positions, winners, notes, barrage).
     *
     * @param int   $competitionId Competition id.
     * @param array $payload {rades:[{comp_rade_id,had_barrage,barrage_notes,signups:[{id,position,is_winner,result_notes}]}]}
     * @param array $actor Actor.
     * @return array{saved:int,status:string}
     * @throws NotFoundException|ForbiddenException
     *
     * Side effects: updates signups + competition_rades; sets results_status=draft; audit.
     * Transaction: yes.
     */
    public function saveDraft(int $competitionId, array $payload, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $competition = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $competitionId]);
        if ($competition === null) {
            throw new NotFoundException('Competition not found', 'COMPETITION_NOT_FOUND');
        }
        if ($competition['results_status'] === 'published' && $actor['role'] !== 'admin') {
            throw new ForbiddenException('Only admins may edit published results', 'FORBIDDEN');
        }
        $now = now_utc();
        $saved = 0;
        $this->db->transaction(function () use ($competitionId, $payload, $now, &$saved): void {
            foreach (($payload['rades'] ?? []) as $rade) {
                $compRadeId = (int) ($rade['comp_rade_id'] ?? 0);
                if ($compRadeId <= 0) {
                    continue;
                }
                $this->db->update('competition_rades', [
                    'had_barrage' => !empty($rade['had_barrage']) ? 1 : 0,
                    'barrage_notes' => $rade['barrage_notes'] ?? null,
                    'updated_at' => $now,
                ], 'id = :id AND competition_id = :c', ['id' => $compRadeId, 'c' => $competitionId]);
                foreach (($rade['signups'] ?? []) as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $position = isset($row['position']) && $row['position'] !== '' && $row['position'] !== null ? (int) $row['position'] : null;
                    $this->db->update('signups', [
                        'position' => $position,
                        'is_winner' => !empty($row['is_winner']) ? 1 : 0,
                        'result_notes' => $row['result_notes'] ?? null,
                        'updated_at' => $now,
                    ], 'id = :id AND competition_rade_id = :cr', ['id' => $id, 'cr' => $compRadeId]);
                    $saved++;
                }
            }
            $this->db->update('competitions', ['results_status' => 'draft', 'updated_at' => $now], 'id = :id', ['id' => $competitionId]);
            $this->record($actor, 'results.save_draft', $competitionId, ['saved' => $saved]);
        });
        return ['saved' => $saved, 'status' => 'draft'];
    }

    /**
     * Confirm results (draft → confirmed).
     *
     * @param int   $competitionId Competition id.
     * @param array $actor Actor.
     * @return array{status:string}
     * @throws NotFoundException|ForbiddenException
     */
    public function confirm(int $competitionId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $this->db->update('competitions', ['results_status' => 'confirmed', 'updated_at' => now_utc()], 'id = :id', ['id' => $competitionId]);
        $this->record($actor, 'results.confirm', $competitionId);
        return ['status' => 'confirmed'];
    }

    /**
     * Publish results (confirmed → published) and notify all confirmed riders.
     *
     * @param int   $competitionId Competition id.
     * @param array $actor Actor.
     * @return array{status:string,notified:int}
     * @throws NotFoundException|ForbiddenException
     *
     * Side effects: updates competitions; creates notifications; SMS; audit + changelog. Transaction: yes.
     */
    public function publish(int $competitionId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
        $competition = $this->db->selectOne('SELECT * FROM competitions WHERE id = :id', ['id' => $competitionId]);
        if ($competition === null) {
            throw new NotFoundException('Competition not found', 'COMPETITION_NOT_FOUND');
        }
        if (!in_array((string) $competition['results_status'], ['confirmed', 'published'], true)) {
            throw new DomainException('RESULTS_INVALID_STATE', 'Results must be confirmed before publishing', 422);
        }
        if ($competition['results_status'] === 'published') {
            return ['status' => 'published', 'notified' => 0];
        }
        $riders = $this->db->select("SELECT DISTINCT rider_user_id AS id FROM signups WHERE competition_id = :c AND status = 'confirmed'", ['c' => $competitionId]);
        $now = now_utc();
        return $this->db->transaction(function () use ($competitionId, $riders, $now, $actor, $competition): array {
            $this->db->update('competitions', ['results_status' => 'published', 'results_published_at' => $now, 'updated_at' => $now], 'id = :id', ['id' => $competitionId]);
            foreach ($riders as $r) {
                $this->notifications?->create((int) $r['id'], 'results.published', 'انتشار نتایج', 'نتایج مسابقه "' . $competition['title'] . '" منتشر شد.', '/panel/rider/signups', 'competition', $competitionId);
                if ($this->sms !== null) {
                    $u = $this->db->selectOne('SELECT phone FROM users WHERE id = :id', ['id' => (int) $r['id']]);
                    if ($u !== null && !empty($u['phone'])) {
                        $this->sms->notify((string) $u['phone'], 'sms.notify_on_results', 'نتایج مسابقه منتشر شد.');
                    }
                }
            }
            $this->record($actor, 'results.publish', $competitionId, ['notified' => count($riders)]);
            $this->log->changelog([
                'actor_id' => $actor['id'],
                'actor_role' => $actor['role'],
                'action' => 'results.publish',
                'target_type' => 'competition',
                'target_id' => $competitionId,
                'summary' => 'انتشار نتایج مسابقه: ' . $competition['title'],
                'link' => '/panel/competitions/' . $competitionId . '/results',
            ]);
            return ['status' => 'published', 'notified' => count($riders)];
        });
    }

    /**
     * Reopen published results (Admin only) → confirmed.
     *
     * @param int   $competitionId Competition id.
     * @param array $actor Actor.
     * @return array{status:string}
     * @throws ForbiddenException
     */
    public function reopen(int $competitionId, array $actor): array
    {
        if ($actor['role'] !== 'admin') {
            throw new ForbiddenException('Only admins may reopen results', 'FORBIDDEN');
        }
        $this->db->update('competitions', ['results_status' => 'confirmed', 'updated_at' => now_utc()], 'id = :id', ['id' => $competitionId]);
        $this->record($actor, 'results.reopen', $competitionId);
        return ['status' => 'confirmed'];
    }

    /**
     * Standings for a rider, horse, or rider-horse pair.
     *
     * @param array $scope {rider_user_id?,horse_id?}
     * @return array<int,array>
     */
    public function standings(array $scope): array
    {
        $where = ["s.status = 'confirmed'"];
        $params = [];
        if (!empty($scope['rider_user_id'])) {
            $where[] = 's.rider_user_id = :r';
            $params['r'] = (int) $scope['rider_user_id'];
        }
        if (!empty($scope['horse_id'])) {
            $where[] = 's.horse_id = :h';
            $params['h'] = (int) $scope['horse_id'];
        }
        return $this->db->select(
            'SELECT s.id, s.position, s.is_winner, c.title AS competition_title, c.start_at, r.name AS rade_name,
                    h.name AS horse_name, (u.first_name || \' \' || u.last_name) AS rider_name
             FROM signups s JOIN competitions c ON c.id = s.competition_id JOIN rades r ON r.id = s.rade_id
             JOIN horses h ON h.id = s.horse_id JOIN users u ON u.id = s.rider_user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY c.start_at DESC',
            $params
        );
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
            'actor_id' => $actor['id'] ?? null,
            'actor_role' => $actor['role'] ?? null,
            'action' => $action,
            'target_type' => 'competition',
            'target_id' => $id,
            'diff' => $diff,
        ]);
    }
}
