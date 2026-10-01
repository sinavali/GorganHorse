<?php
declare(strict_types=1);

/**
 * File: app/Services/LookupService.php
 *
 * Purpose:
 *   Controlled vocabulary for horse attributes (breed/race, colour, gender).
 *   Reads feed the searchable, pre-filled selects on the horse create/edit form
 *   and the rider signup flow; writes let Admins and Managers maintain the
 *   lists (add, rename, reorder, deactivate, delete) instead of hard-coding them
 *   in the frontend (User Usage §7.6, Blueprint §6 glossary).
 *
 * Tables: horse_races, horse_colors, horse_genders (all share the same shape).
 *   id, name (unique), sort_order, is_active, created_at, updated_at.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Class: LookupService
 * Purpose: Manage the horse lookup lists.
 */
final class LookupService
{
    /** Allowed lookup types mapped to their table. */
    private const TABLES = [
        'genders' => 'horse_genders',
        'colors' => 'horse_colors',
        'races' => 'horse_races',
    ];

    /** @var Database */
    private $db;

    /** @var LogService */
    private $log;

    /**
     * @param Database   $db  Main database.
     * @param LogService $log Changelog/audit logger.
     */
    public function __construct(Database $db, LogService $log)
    {
        $this->db = $db;
        $this->log = $log;
    }

    /**
     * List entries of a lookup type, active first (Admin/Manager may ask for all).
     *
     * @param string $type        One of genders|colors|races.
     * @param bool   $includeAll  Include deactivated rows.
     * @return array<int,array<string,mixed>>
     * @throws DomainException On an unknown type.
     */
    public function list(string $type, bool $includeAll = false): array
    {
        $table = $this->table($type);
        $sql = 'SELECT id, name, sort_order, is_active FROM ' . $table;
        if (!$includeAll) { $sql .= ' WHERE is_active = 1'; }
        $sql .= ' ORDER BY sort_order ASC, name ASC';
        return $this->db->select($sql);
    }

    /**
     * Create an entry (idempotent on name: re-adding an existing name re-enables it).
     *
     * @param string $type  Lookup type.
     * @param string $name  Entry name (trimmed, 1..60 chars).
     * @param array  $actor {id,role} for audit.
     * @return int New (or re-enabled) entry id.
     * @throws ValidationException On an empty/too-long name.
     */
    public function create(string $type, string $name, array $actor): int
    {
        $table = $this->table($type);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new ValidationException('نام نامعتبر است', 'name', 'LOOKUP_NAME_INVALID');
        }
        $existing = $this->db->selectOne('SELECT id, is_active FROM ' . $table . ' WHERE name = :n', ['n' => $name]);
        if ($existing !== null) {
            if ((int) $existing['is_active'] === 0) {
                $this->db->update($table, ['is_active' => 1, 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $existing['id']]);
            }
            return (int) $existing['id'];
        }
        $maxSort = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM ' . $table);
        $id = $this->db->insert($table, [
            'name' => $name,
            'sort_order' => $maxSort + 1,
            'is_active' => 1,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $this->log->audit([
            'actor_id' => (int) $actor['id'], 'actor_role' => $actor['role'],
            'action' => 'lookup.create', 'target_type' => $type, 'target_id' => $id, 'diff' => ['name' => $name],
        ]);
        return $id;
    }

    /**
     * Update an entry.
     *
     * @param string $type  Lookup type.
     * @param int    $id    Entry id.
     * @param array  $input {name?, sort_order?, is_active?}
     * @param array  $actor {id,role} for audit.
     * @return void
     * @throws NotFoundException When the entry does not exist.
     * @throws DomainException   When the new name is already used in this list.
     */
    public function update(string $type, int $id, array $input, array $actor): void
    {
        $table = $this->table($type);
        $row = $this->db->selectOne('SELECT * FROM ' . $table . ' WHERE id = :id', ['id' => $id]);
        if ($row === null) { throw new NotFoundException('Lookup entry not found', 'LOOKUP_NOT_FOUND'); }
        $data = ['updated_at' => now_utc()];
        if (array_key_exists('name', $input) && trim((string) $input['name']) !== '') {
            $name = trim((string) $input['name']);
            if (mb_strlen($name) > 60) {
                throw new ValidationException('نام نامعتبر است', 'name', 'LOOKUP_NAME_INVALID');
            }
            $clash = $this->db->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE name = :n AND id != :id', ['n' => $name, 'id' => $id]);
            if ((int) $clash > 0) {
                throw new DomainException('LOOKUP_NAME_TAKEN', 'این مقدار قبلاً ثبت شده است', 409, 'name');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('sort_order', $input) && $input['sort_order'] !== '' && $input['sort_order'] !== null) {
            $data['sort_order'] = (int) $input['sort_order'];
        }
        if (array_key_exists('is_active', $input)) {
            $data['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $this->db->update($table, $data, 'id = :id', ['id' => $id]);
        $this->log->audit([
            'actor_id' => (int) $actor['id'], 'actor_role' => $actor['role'],
            'action' => 'lookup.update', 'target_type' => $type, 'target_id' => $id, 'diff' => $data,
        ]);
    }

    /**
     * Delete an entry. Entries still referenced by horses cannot be deleted;
     * deactivate them instead (is_active = 0) so historical horses keep a label.
     *
     * @param string $type  Lookup type.
     * @param int    $id    Entry id.
     * @param array  $actor {id,role} for audit.
     * @return void
     * @throws NotFoundException  When the entry does not exist.
     * @throws DomainException    When the value is still in use by a horse.
     */
    public function delete(string $type, int $id, array $actor): void
    {
        $table = $this->table($type);
        $row = $this->db->selectOne('SELECT * FROM ' . $table . ' WHERE id = :id', ['id' => $id]);
        if ($row === null) { throw new NotFoundException('Lookup entry not found', 'LOOKUP_NOT_FOUND'); }
        $column = ['genders' => 'gender', 'colors' => 'color', 'races' => 'race'][$type];
        $inUse = (int) $this->db->scalar('SELECT COUNT(*) FROM horses WHERE ' . $column . ' = :n', ['n' => (string) $row['name']]);
        if ($inUse > 0) {
            throw new DomainException('LOOKUP_IN_USE', 'این مقدار توسط ' . $inUse . ' اسب استفاده می‌شود؛ به‌جای حذف، آن را غیرفعال کنید.', 409, 'id');
        }
        $this->db->delete($table, 'id = :id', ['id' => $id]);
        $this->log->audit([
            'actor_id' => (int) $actor['id'], 'actor_role' => $actor['role'],
            'action' => 'lookup.delete', 'target_type' => $type, 'target_id' => $id, 'diff' => ['name' => $row['name']],
        ]);
    }

    /**
     * Map a lookup type to its table, rejecting unknown input.
     *
     * @param string $type Lookup type.
     * @return string
     * @throws DomainException On an unknown type.
     */
    private function table(string $type): string
    {
        if (!isset(self::TABLES[$type])) {
            throw new DomainException('LOOKUP_TYPE_INVALID', 'Lookup type must be one of: ' . implode(', ', array_keys(self::TABLES)), 400, 'type');
        }
        return self::TABLES[$type];
    }
}
