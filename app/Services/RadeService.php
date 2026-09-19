<?php
declare(strict_types=1);

/**
 * File: app/Services/RadeService.php
 *
 * Purpose:
 *   Rade (competition class) lifecycle: CRUD, activation, and slug uniqueness
 *   (Blueprint §7.1, §8.1).
 *
 * Dependencies: Database, LogService.
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
 * Class: RadeService
 *
 * Purpose: Manage rade definitions.
 */
final class RadeService
{
    private Database $db;
    private LogService $log;

    /**
     * @param Database   $db  Main DB.
     * @param LogService $log Logs.
     */
    public function __construct(Database $db, LogService $log)
    {
        $this->db = $db;
        $this->log = $log;
    }

    /**
     * List rade definitions.
     *
     * @param array $filters {active?,search?}
     * @return array<int,array>
     */
    public function list(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (($filters['active'] ?? '') !== '' && ($filters['active'] ?? 'all') !== 'all') {
            $where[] = 'is_active = :a';
            $params['a'] = (int) $filters['active'];
        }
        if (!empty($filters['search'])) { $where[] = 'name LIKE :q'; $params['q'] = '%' . $filters['search'] . '%'; }
        return $this->db->select('SELECT * FROM rades WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order ASC, id ASC', $params);
    }

    /**
     * Fetch a rade.
     *
     * @param int $id Rade id.
     * @return array
     * @throws NotFoundException RADE_NOT_FOUND.
     */
    public function get(int $id): array
    {
        $rade = $this->db->selectOne('SELECT * FROM rades WHERE id = :id', ['id' => $id]);
        if ($rade === null) { throw new NotFoundException('Rade not found', 'RADE_NOT_FOUND'); }
        return $rade;
    }

    /**
     * Create a rade.
     *
     * @param array $input {name,slug?,description?,age_min?,age_max?,age_enforced?,sort_order?,is_active?}
     * @param array $actor Actor.
     * @return array{id:int,uuid:string}
     * @throws ForbiddenException|ValidationException|DomainException
     *
     * Side effects: inserts rades; audit.
     */
    public function create(array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') { throw new ValidationException('Rade name is required', 'name', 'VALIDATION_FAILED'); }
        $ageMin = isset($input['age_min']) && $input['age_min'] !== '' ? (int) $input['age_min'] : null;
        $ageMax = isset($input['age_max']) && $input['age_max'] !== '' ? (int) $input['age_max'] : null;
        if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
            throw new ValidationException('age_min must be <= age_max', 'age_min', 'VALIDATION_FAILED');
        }
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') { $slug = slugify($name) . '-' . random_digits(4); }
        if ($this->db->scalar('SELECT COUNT(*) FROM rades WHERE slug = :s', ['s' => $slug]) > 0) {
            throw new DomainException('RADE_SLUG_TAKEN', 'Slug already taken', 409, 'slug');
        }
        $now = now_utc();
        $uuid = uuid4();
        $id = $this->db->insert('rades', [
            'uuid' => $uuid,
            'name' => $name,
            'slug' => $slug,
            'description' => $input['description'] ?? null,
            'age_min' => $ageMin,
            'age_max' => $ageMax,
            'age_enforced' => !empty($input['age_enforced']) ? 1 : 0,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'is_active' => !isset($input['is_active']) || $input['is_active'] ? 1 : 0,
            'is_demo' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->record($actor, 'rade.create', $id, ['name' => $name]);
        return ['id' => $id, 'uuid' => $uuid];
    }

    /**
     * Update a rade.
     *
     * @param int   $id    Rade id.
     * @param array $input Fields.
     * @param array $actor Actor.
     * @return array{id:int}
     * @throws NotFoundException|ForbiddenException|DomainException
     */
    public function update(int $id, array $input, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $this->get($id);
        $data = [];
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field]; }
        }
        foreach (['age_min', 'age_max', 'sort_order'] as $field) {
            if (array_key_exists($field, $input)) { $data[$field] = $input[$field] === '' || $input[$field] === null ? null : (int) $input[$field]; }
        }
        if (array_key_exists('age_enforced', $input)) { $data['age_enforced'] = $input['age_enforced'] ? 1 : 0; }
        if (array_key_exists('is_active', $input)) { $data['is_active'] = $input['is_active'] ? 1 : 0; }
        if (array_key_exists('slug', $input) && $input['slug'] !== '') {
            $slug = (string) $input['slug'];
            if ($this->db->scalar('SELECT COUNT(*) FROM rades WHERE slug = :s AND id != :id', ['s' => $slug, 'id' => $id]) > 0) {
                throw new DomainException('RADE_SLUG_TAKEN', 'Slug already taken', 409, 'slug');
            }
            $data['slug'] = $slug;
        }
        if (isset($data['age_min'], $data['age_max']) && $data['age_min'] !== null && $data['age_max'] !== null && $data['age_min'] > $data['age_max']) {
            throw new ValidationException('age_min must be <= age_max', 'age_min', 'VALIDATION_FAILED');
        }
        $data['updated_at'] = now_utc();
        $this->db->update('rades', $data, 'id = :id', ['id' => $id]);
        $this->record($actor, 'rade.update', $id, $data);
        return ['id' => $id];
    }

    /**
     * Delete a rade (blocked when referenced by competition-rades with signups).
     *
     * @param int   $id    Rade id.
     * @param array $actor Actor.
     * @return void
     * @throws NotFoundException|ForbiddenException|DomainException
     */
    public function delete(int $id, array $actor): void
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $rade = $this->get($id);
        $used = (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE rade_id = :r', ['r' => $id]);
        if ($used > 0) {
            throw new DomainException('FORBIDDEN', 'Rade has signups and cannot be deleted', 409);
        }
        $this->db->transaction(function () use ($id, $actor, $rade): void {
            $this->db->delete('competition_rades', 'rade_id = :r', ['r' => $id]);
            $this->db->delete('rades', 'id = :id', ['id' => $id]);
            $this->record($actor, 'rade.delete', $id, ['name' => $rade['name']]);
        });
    }

    /**
     * Bulk activate/deactivate.
     *
     * @param array  $ids   Rade ids.
     * @param bool   $active Target state.
     * @param array  $actor Actor.
     * @return int Affected.
     */
    public function bulkSetActive(array $ids, bool $active, array $actor): int
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $count = 0;
        foreach ($ids as $id) {
            $count += $this->db->update('rades', ['is_active' => $active ? 1 : 0, 'updated_at' => now_utc()], 'id = :id', ['id' => (int) $id]);
        }
        $this->record($actor, 'rade.bulk_active', 0, ['count' => $count, 'active' => $active]);
        return $count;
    }

    /**
     * Audit helper.
     *
     * @param array  $actor  Actor.
     * @param string $action Action.
     * @param int    $id     Rade id.
     * @param array  $diff   Diff.
     * @return void
     */
    private function record(array $actor, string $action, int $id, array $diff = []): void
    {
        $this->log->audit([
            'actor_id' => $actor['id'] ?? null, 'actor_role' => $actor['role'] ?? null,
            'action' => $action, 'target_type' => 'rade', 'target_id' => $id, 'diff' => $diff,
        ]);
    }
}
