<?php
declare(strict_types=1);

/**
 * File: app/Services/HorseHealthService.php
 *
 * Purpose: Manages per-horse health records (vaccination, check-up, treatment,
 *          injury, fitness certificate) so a manager can prove a horse was fit
 *          before a competition. Records are scoped by the actor's role: staff
 *          see and edit every horse, riders only their own.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;

/**
 * Class: HorseHealthService
 * Purpose: CRUD + queries for horse health records.
 */
final class HorseHealthService
{
    /** @var Database */
    private $db;

    /**
     * @param Database $db Main connection.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Allowed record types with Persian labels.
     *
     * @return array<string,string>
     */
    public static function types(): array
    {
        return [
            'vaccination' => 'واکسیناسیون',
            'checkup' => 'معاینه',
            'treatment' => 'درمان',
            'injury' => 'آسیب‌دیدگی',
            'fitness' => 'گواهی آمادگی',
        ];
    }

    /**
     * List records for a horse, newest first.
     *
     * @param int   $horseId Horse id.
     * @param array $actor   Actor.
     * @return array<int,array>
     * @throws ForbiddenException When the actor may not read this horse.
     */
    public function listFor(int $horseId, array $actor): array
    {
        $this->assertReadable($horseId, $actor);
        return $this->db->select(
            'SELECT * FROM horse_health_records WHERE horse_id = :h ORDER BY COALESCE(performed_at, created_at) DESC, id DESC',
            ['h' => $horseId]
        );
    }

    /**
     * Records whose next due date falls inside the window.
     *
     * Used by the dashboard and the scheduler to warn about expiring
     * vaccinations before a horse is entered in a competition.
     *
     * @param int $days Look-ahead window in days.
     * @return array<int,array>
     */
    public function dueWithin(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->select(
            "SELECT hr.*, h.name AS horse_name, u.first_name || ' ' || u.last_name AS owner_name
             FROM horse_health_records hr
             JOIN horses h ON h.id = hr.horse_id
             LEFT JOIN users u ON u.id = h.owner_user_id
             WHERE hr.next_due_at IS NOT NULL AND hr.next_due_at <> ''
               AND date(hr.next_due_at) <= date(:limit)
               AND date(hr.next_due_at) >= date(:now)
             ORDER BY hr.next_due_at ASC",
            ['limit' => utc_iso(time() + ($days * 86400)), 'now' => now_utc()]
        );
    }

    /**
     * Create a record.
     *
     * @param int   $horseId Horse id.
     * @param array $input   { record_type, title, performed_at, next_due_at,
     *                        vet_name, notes, cost_irt }
     * @param array $actor   Actor (staff only).
     * @return array{id:int}
     * @throws ForbiddenException When the actor is not staff.
     */
    public function create(int $horseId, array $input, array $actor): array
    {
        $this->assertStaff($actor);
        $this->assertHorseExists($horseId);

        $type = (string) ($input['record_type'] ?? 'vaccination');
        if (!array_key_exists($type, self::types())) {
            throw new DomainException('HEALTH_TYPE_INVALID', 'نوع رکورد نامعتبر است', 422, 'record_type');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('VALIDATION', 'عنوان رکورد الزامی است', 422, 'title');
        }
        $cost = (int) ($input['cost_irt'] ?? 0);
        if ($cost < 0) {
            throw new DomainException('VALIDATION', 'مبلغ نمی‌تواند منفی باشد', 422, 'cost_irt');
        }
        $now = now_utc();
        $id = $this->db->insert('horse_health_records', [
            'horse_id' => $horseId,
            'record_type' => $type,
            'title' => $title,
            'performed_at' => $input['performed_at'] ?? null,
            'next_due_at' => $input['next_due_at'] ?? null,
            'vet_name' => $input['vet_name'] ?? null,
            'notes' => $input['notes'] ?? null,
            'cost_irt' => $cost,
            'performed_by' => (int) ($actor['id'] ?? 0) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['id' => (int) $id];
    }

    /**
     * Update a record.
     *
     * @param int   $id    Record id.
     * @param array $input Fields.
     * @param array $actor Actor (staff only).
     * @return array{id:int}
     * @throws ForbiddenException When the actor is not staff.
     * @throws NotFoundException  When the record does not exist.
     */
    public function update(int $id, array $input, array $actor): array
    {
        $this->assertStaff($actor);
        $current = $this->db->selectOne('SELECT * FROM horse_health_records WHERE id = :id', ['id' => $id]);
        if ($current === null) {
            throw new NotFoundException('Health record not found', 'HEALTH_RECORD_NOT_FOUND');
        }
        $data = [];
        if (isset($input['record_type'])) {
            if (!array_key_exists((string) $input['record_type'], self::types())) {
                throw new DomainException('HEALTH_TYPE_INVALID', 'نوع رکورد نامعتبر است', 422, 'record_type');
            }
            $data['record_type'] = (string) $input['record_type'];
        }
        foreach (['title', 'performed_at', 'next_due_at', 'vet_name', 'notes'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }
        if (isset($input['cost_irt'])) {
            $cost = (int) $input['cost_irt'];
            if ($cost < 0) {
                throw new DomainException('VALIDATION', 'مبلغ نمی‌تواند منفی باشد', 422, 'cost_irt');
            }
            $data['cost_irt'] = $cost;
        }
        if ($data === []) {
            return ['id' => $id];
        }
        $data['updated_at'] = now_utc();
        $this->db->update('horse_health_records', $data, 'id = :id', ['id' => $id]);
        return ['id' => $id];
    }

    /**
     * Delete a record.
     *
     * @param int   $id    Record id.
     * @param array $actor Actor (staff only).
     * @return void
     * @throws ForbiddenException When the actor is not staff.
     */
    public function delete(int $id, array $actor): void
    {
        $this->assertStaff($actor);
        $this->db->execute('DELETE FROM horse_health_records WHERE id = :id', ['id' => $id]);
    }

    /**
     * Reject non-staff actors.
     *
     * @param array $actor Actor.
     * @return void
     * @throws ForbiddenException
     */
    private function assertStaff(array $actor): void
    {
        if (!in_array(($actor['role'] ?? ''), ['admin', 'manager'], true)) {
            throw new ForbiddenException('Forbidden', 'FORBIDDEN');
        }
    }

    /**
     * Reject riders who do not own the horse.
     *
     * @param int   $horseId Horse id.
     * @param array $actor   Actor.
     * @return void
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function assertReadable(int $horseId, array $actor): void
    {
        $role = (string) ($actor['role'] ?? '');
        if (in_array($role, ['admin', 'manager'], true)) {
            return;
        }
        if ($role === 'rider') {
            $owner = (int) $this->db->scalar('SELECT owner_user_id FROM horses WHERE id = :h', ['h' => $horseId]);
            if ($owner !== (int) ($actor['id'] ?? 0)) {
                throw new ForbiddenException('Forbidden', 'FORBIDDEN');
            }
            return;
        }
        throw new ForbiddenException('Forbidden', 'FORBIDDEN');
    }

    /**
     * Ensure the horse exists before writing a child row.
     *
     * @param int $horseId Horse id.
     * @return void
     * @throws NotFoundException
     */
    private function assertHorseExists(int $horseId): void
    {
        $exists = (int) $this->db->scalar('SELECT COUNT(*) FROM horses WHERE id = :h', ['h' => $horseId]);
        if ($exists === 0) {
            throw new NotFoundException('Horse not found', 'HORSE_NOT_FOUND');
        }
    }
}