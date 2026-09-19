<?php
declare(strict_types=1);

/**
 * File: app/Services/Report/Reports.php
 *
 * Purpose:
 *   The report registry and every report class, merged into one file (P23).
 *   Each report declares its key, labels, columns, filters, sortable columns,
 *   default columns, and builds a whitelisted query (Blueprint §13, Technical §18).
 *
 * Conventions:
 *   - Columns and filters are declarations; the engine whitelists against them.
 *   - Every fetch uses named parameters (P06).
 *   - Role scoping is expressed as trusted filter definitions returned by
 *     scopeFilters(); each has '_scope' set to true by the engine, and the SQL
 *     fragment is supplied via its 'sql' key (never user-controlled).
 *
 * @package App\Services\Report
 */

namespace App\Services\Report;

use App\Bootstrap\Database;

/**
 * Interface: ReportInterface
 *
 * Purpose: Contract every report implements (Technical §18.2).
 */
interface ReportInterface
{
    /** @return string Report key. */
    public static function key(): string;

    /** @return string Human label (fa-IR). */
    public function label(): string;

    /** @return array<int,array{key:string,label:string,type:string,exportable:bool}> Column definitions. */
    public function columns(): array;

    /** @return array<int,array{key:string,label:string,type:string,operators:array,options?:array,sql?:string}> Filter definitions. */
    public function filters(): array;

    /** @return string[] Sortable column keys. */
    public function sortable(): array;

    /** @return string[] Default visible columns. */
    public function defaultColumns(): array;

    /** @return string Default sort column. */
    public function defaultSort(): string;

    /** @return bool Whether the actor may run this report. */
    public function authorize(array $actor): bool;

    /**
     * Role-derived scope filters. These are trusted and bypass the user-facing
     * whitelist; the SQL fragment must be supplied via 'sql' when it differs
     * from the declared column name.
     *
     * @return array<int,array{col:string,op:string,value:mixed,sql?:string}>
     */
    public function scopeFilters(array $actor): array;

    /** @return int Total rows (no filters). */
    public function count(Database $db, array $filters): int;

    /** @return array Row set. */
    public function fetch(Database $db, array $filters, array $sort, int $page, int $perPage): array;

    /** @return array Projected display row. */
    public function project(array $row, Database $db): array;

    /** @return array Summary tiles. */
    public function summary(Database $db, array $filters): array;
}

/**
 * Class: BaseReport
 *
 * Purpose: Shared filter/SQL building for all reports.
 */
abstract class BaseReport implements ReportInterface
{
    /** @return string Base FROM clause (aliased). */
    abstract protected function baseFrom(): string;

    /** @return string[] Columns selected in the base query. */
    abstract protected function baseSelect(): array;

    /**
     * Build a WHERE clause from whitelisted filters. Scope filters (flagged
     * _scope=true) skip the whitelist check and use their own 'sql' fragment.
     *
     * Supported operators: eq, ne, gt, lt, in, between, contains, raw.
     * The 'raw' operator substitutes :__val__, :__v0__, :__v1__ placeholders
     * with a single generated named parameter (the same value reused).
     *
     * @param array $filters Normalized filters.
     * @return array{0:string,1:array}
     */
    protected function buildWhere(array $filters): array
    {
        $defs = [];
        foreach ($this->filters() as $f) { $defs[$f['key']] = $f; }
        $clauses = [];
        $params = [];
        foreach ($filters as $f) {
            $col = (string) ($f['col'] ?? '');
            $isScope = !empty($f['_scope']);
            if ($isScope) {
                $sqlCol = (string) ($f['sql'] ?? $col);
            } else {
                if (!isset($defs[$col])) { continue; }
                $sqlCol = (string) ($defs[$col]['sql'] ?? $col);
            }
            $op = (string) ($f['op'] ?? 'eq');
            $value = $f['value'] ?? null;
            $p = 'f_' . preg_replace('/[^a-z0-9_]/i', '', $col) . '_' . count($params);
            switch ($op) {
                case 'eq':
                    $clauses[] = "$sqlCol = :$p";
                    $params[$p] = $value;
                    break;
                case 'ne':
                    $clauses[] = "$sqlCol != :$p";
                    $params[$p] = $value;
                    break;
                case 'gt':
                    $clauses[] = "$sqlCol > :$p";
                    $params[$p] = $value;
                    break;
                case 'lt':
                    $clauses[] = "$sqlCol < :$p";
                    $params[$p] = $value;
                    break;
                case 'in':
                    $values = is_array($value) ? $value : [$value];
                    if ($values === []) { break; }
                    $phs = [];
                    foreach ($values as $i => $v) {
                        $ph = $p . '_' . $i;
                        $phs[] = ":$ph";
                        $params[$ph] = $v;
                    }
                    $clauses[] = "$sqlCol IN (" . implode(',', $phs) . ')';
                    break;
                case 'between':
                    if (is_array($value) && count($value) === 2) {
                        $clauses[] = "$sqlCol >= :{$p}_a AND $sqlCol <= :{$p}_b";
                        $params["{$p}_a"] = $value[0];
                        $params["{$p}_b"] = $value[1];
                    }
                    break;
                case 'contains':
                    $clauses[] = "$sqlCol LIKE :$p";
                    $params[$p] = '%' . $value . '%';
                    break;
                case 'raw':
                    $ph = $p . '_raw';
                    $fragment = str_replace([':__val__', ':__v0__', ':__v1__'], ':' . $ph, $sqlCol);
                    $clauses[] = '(' . $fragment . ')';
                    $params[$ph] = is_array($value) ? ($value[0] ?? null) : $value;
                    break;
                default:
                    $clauses[] = "$sqlCol = :$p";
                    $params[$p] = $value;
            }
        }
        return [$clauses === [] ? '1=1' : implode(' AND ', $clauses), $params];
    }

    /**
     * Build ORDER BY from whitelisted sort.
     *
     * @param array $sort Normalized sort.
     * @return string
     */
    protected function buildOrder(array $sort): string
    {
        $sortable = $this->sortable();
        $parts = [];
        foreach ($sort as $s) {
            if (!in_array($s['col'], $sortable, true)) { continue; }
            $parts[] = $s['col'] . ' ' . strtoupper($s['dir']);
        }
        return $parts === [] ? ($this->defaultSort() . ' DESC') : implode(', ', $parts);
    }

    /** @return array<int,array> Fetch rows. */
    public function fetch(Database $db, array $filters, array $sort, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT ' . implode(', ', $this->baseSelect()) . ' ' . $this->baseFrom()
            . ' WHERE ' . $where . ' ORDER BY ' . $this->buildOrder($sort) . ' LIMIT :_limit OFFSET :_offset';
        $params['_limit'] = $perPage;
        $params['_offset'] = $offset;
        return $db->select($sql, $params);
    }

    /** @return int Count rows matching filters. */
    public function count(Database $db, array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int) $db->scalar('SELECT COUNT(*) ' . $this->baseFrom() . ' WHERE ' . $where, $params);
    }

    /** @return string[] Sortable columns (default: filter keys). */
    public function sortable(): array
    {
        $cols = [];
        foreach ($this->columns() as $c) { $cols[] = $c['key']; }
        return $cols;
    }

    /** @return bool Most reports are visible to staff; overridden below. */
    public function authorize(array $actor): bool
    {
        return in_array($actor['role'] ?? 'guest', ['admin', 'manager'], true);
    }

    /** @return array Default scope filters (none). */
    public function scopeFilters(array $actor): array
    {
        return [];
    }

    /** @return array Empty summary (overridden). */
    public function summary(Database $db, array $filters): array
    {
        return [];
    }
}

/**
 * Class: SignupsReport
 *
 * Key: signups
 * Columns: rider, horse, competition, rade, club, status, amount, position, winner, created_at
 * Filters: competition_id, rade_id, status, club_id, rider, created_at (between)
 * Default sort: created_at desc
 *
 * Scoping:
 *   - Rider: only own signups.
 *   - Club: signups where affiliation_club_id belongs to the club OR the
 *     competition is hosted at the club's venue.
 */
final class SignupsReport extends BaseReport
{
    public static function key(): string { return 'signups'; }
    public function label(): string { return 'ثبت‌نام‌ها'; }
    protected function baseFrom(): string
    {
        return 'FROM signups s JOIN users u ON u.id = s.rider_user_id JOIN horses h ON h.id = s.horse_id
                JOIN competitions c ON c.id = s.competition_id JOIN rades r ON r.id = s.rade_id
                LEFT JOIN clubs cl ON cl.id = s.affiliation_club_id';
    }
    protected function baseSelect(): array
    {
        return ['s.id', 's.status', 's.position', 's.is_winner', 's.payment_amount_irt_snapshot AS amount',
            's.competition_id', 's.rade_id', 's.affiliation_club_id',
            "(u.first_name || ' ' || u.last_name) AS rider", 'h.name AS horse', 'c.title AS competition',
            'r.name AS rade', 'cl.name AS club', 's.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'rider', 'label' => 'سوارکار', 'type' => 'string', 'exportable' => true],
            ['key' => 'horse', 'label' => 'اسب', 'type' => 'string', 'exportable' => true],
            ['key' => 'competition', 'label' => 'مسابقه', 'type' => 'string', 'exportable' => true],
            ['key' => 'rade', 'label' => 'رده', 'type' => 'string', 'exportable' => true],
            ['key' => 'club', 'label' => 'باشگاه', 'type' => 'string', 'exportable' => true],
            ['key' => 'amount', 'label' => 'مبلغ', 'type' => 'int', 'exportable' => true],
            ['key' => 'status', 'label' => 'وضعیت', 'type' => 'enum', 'exportable' => true],
            ['key' => 'position', 'label' => 'مقام', 'type' => 'int', 'exportable' => true],
            ['key' => 'is_winner', 'label' => 'برنده', 'type' => 'bool', 'exportable' => true],
            ['key' => 'created_at', 'label' => 'تاریخ', 'type' => 'datetime', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [
            ['key' => 'competition_id', 'label' => 'مسابقه', 'type' => 'int', 'sql' => 's.competition_id', 'operators' => ['eq']],
            ['key' => 'rade_id', 'label' => 'رده', 'type' => 'int', 'sql' => 's.rade_id', 'operators' => ['eq']],
            ['key' => 'club_id', 'label' => 'باشگاه', 'type' => 'int', 'sql' => 's.affiliation_club_id', 'operators' => ['eq']],
            ['key' => 'status', 'label' => 'وضعیت', 'type' => 'enum', 'sql' => 's.status', 'operators' => ['in', 'eq'], 'options' => ['pending_payment', 'paid', 'confirmed', 'rejected', 'cancelled', 'withdrawn']],
            ['key' => 'rider', 'label' => 'سوارکار', 'type' => 'string', 'sql' => "(u.first_name || ' ' || u.last_name)", 'operators' => ['contains']],
            ['key' => 'created_at', 'label' => 'تاریخ', 'type' => 'datetime', 'sql' => 's.created_at', 'operators' => ['between', 'gt', 'lt']],
        ];
    }
    public function defaultColumns(): array { return ['rider', 'horse', 'competition', 'rade', 'amount', 'status', 'created_at']; }
    public function defaultSort(): string { return 's.created_at'; }
    public function authorize(array $actor): bool { return in_array($actor['role'] ?? '', ['admin', 'manager', 'rider', 'club'], true); }

    public function scopeFilters(array $actor): array
    {
        $role = $actor['role'] ?? 'guest';
        if ($role === 'rider') {
            return [[
                'col' => 'rider_scope',
                'sql' => 's.rider_user_id',
                'op' => 'eq',
                'value' => (int) $actor['id'],
            ]];
        }
        if ($role === 'club') {
            return [[
                'col' => 'club_scope',
                'sql' => 's.affiliation_club_id IN (SELECT id FROM clubs WHERE user_id = :__val__) '
                       . 'OR s.competition_id IN (SELECT id FROM competitions WHERE venue_club_id IN '
                       . '(SELECT id FROM clubs WHERE user_id = :__val__))',
                'op' => 'raw',
                'value' => (int) $actor['id'],
            ]];
        }
        return [];
    }

    public function project(array $row, Database $db): array
    {
        return [
            'rider' => $row['rider'] ?? '', 'horse' => $row['horse'] ?? '', 'competition' => $row['competition'] ?? '',
            'rade' => $row['rade'] ?? '', 'club' => $row['club'] ?? '', 'amount' => (int) ($row['amount'] ?? 0),
            'status' => $row['status'] ?? '', 'position' => $row['position'], 'is_winner' => (bool) ($row['is_winner'] ?? false),
            'created_at' => $row['created_at'] ?? '',
        ];
    }
    public function summary(Database $db, array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $row = $db->selectOne("SELECT COUNT(*) AS total,
            SUM(CASE WHEN s.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN s.status='paid' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN s.status IN ('cancelled','withdrawn') THEN 1 ELSE 0 END) AS cancelled
            " . $this->baseFrom() . ' WHERE ' . $where, $params) ?? [];
        return ['total' => (int) ($row['total'] ?? 0), 'confirmed' => (int) ($row['confirmed'] ?? 0), 'pending' => (int) ($row['pending'] ?? 0), 'cancelled' => (int) ($row['cancelled'] ?? 0)];
    }
}

/**
 * Class: RevenueReport
 *
 * Key: revenue
 * Columns: rider, competition, rade, amount, status, ref_id, verified_at
 * Filters: competition_id, status, verified_at (between)
 * Default sort: verified_at desc
 */
final class RevenueReport extends BaseReport
{
    public static function key(): string { return 'revenue'; }
    public function label(): string { return 'درآمد'; }
    protected function baseFrom(): string
    {
        return 'FROM payment_orders po LEFT JOIN users u ON u.id = po.rider_user_id
                LEFT JOIN competitions c ON c.id = po.competition_id
                LEFT JOIN signups s ON s.id = po.signup_id LEFT JOIN rades r ON r.id = s.rade_id';
    }
    protected function baseSelect(): array
    {
        return ['po.id', 'po.amount_irt AS amount', 'po.status', 'po.ref_id', 'po.authority', 'po.verified_at',
            "(u.first_name || ' ' || u.last_name) AS rider", 'c.title AS competition', 'r.name AS rade', 'po.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'rider', 'label' => 'سوارکار', 'type' => 'string', 'exportable' => true],
            ['key' => 'competition', 'label' => 'مسابقه', 'type' => 'string', 'exportable' => true],
            ['key' => 'rade', 'label' => 'رده', 'type' => 'string', 'exportable' => true],
            ['key' => 'amount', 'label' => 'مبلغ', 'type' => 'int', 'exportable' => true],
            ['key' => 'status', 'label' => 'وضعیت', 'type' => 'enum', 'exportable' => true],
            ['key' => 'ref_id', 'label' => 'شماره پیگیری', 'type' => 'string', 'exportable' => true],
            ['key' => 'verified_at', 'label' => 'زمان تایید', 'type' => 'datetime', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [
            ['key' => 'competition_id', 'label' => 'مسابقه', 'type' => 'int', 'sql' => 'po.competition_id', 'operators' => ['eq']],
            ['key' => 'status', 'label' => 'وضعیت', 'type' => 'enum', 'sql' => 'po.status', 'operators' => ['in', 'eq'], 'options' => ['pending', 'paid', 'failed', 'pending_refund', 'refunded']],
            ['key' => 'verified_at', 'label' => 'زمان تایید', 'type' => 'datetime', 'sql' => 'po.verified_at', 'operators' => ['between', 'gt', 'lt']],
        ];
    }
    public function defaultColumns(): array { return ['rider', 'competition', 'rade', 'amount', 'status', 'verified_at']; }
    public function defaultSort(): string { return 'po.verified_at'; }
    public function project(array $row, Database $db): array
    {
        return [
            'rider' => $row['rider'] ?? '', 'competition' => $row['competition'] ?? '', 'rade' => $row['rade'] ?? '',
            'amount' => (int) ($row['amount'] ?? 0), 'status' => $row['status'] ?? '', 'ref_id' => $row['ref_id'] ?? '',
            'verified_at' => $row['verified_at'] ?? '',
        ];
    }
    public function summary(Database $db, array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $row = $db->selectOne('SELECT COALESCE(SUM(CASE WHEN po.status=\'paid\' THEN po.amount_irt ELSE 0 END),0) AS total,
            COALESCE(AVG(CASE WHEN po.status=\'paid\' THEN po.amount_irt END),0) AS avg_amount
            ' . $this->baseFrom() . ' WHERE ' . $where, $params) ?? [];
        $top = $db->selectOne("SELECT cl.name AS top_club, COUNT(*) AS c FROM payment_orders po JOIN signups s ON s.id = po.signup_id JOIN clubs cl ON cl.id = s.affiliation_club_id WHERE po.status='paid' GROUP BY cl.id ORDER BY c DESC LIMIT 1", []);
        return [
            'total_revenue' => (int) ($row['total'] ?? 0),
            'avg_per_signup' => (int) round((float) ($row['avg_amount'] ?? 0)),
            'top_club' => $top['top_club'] ?? '—',
        ];
    }
}

/**
 * Class: ResultsReport
 *
 * Key: results
 * Columns: rider, horse, competition, rade, position, winner
 */
final class ResultsReport extends BaseReport
{
    public static function key(): string { return 'results'; }
    public function label(): string { return 'نتایج'; }
    protected function baseFrom(): string
    {
        return 'FROM signups s JOIN users u ON u.id = s.rider_user_id JOIN horses h ON h.id = s.horse_id
                JOIN competitions c ON c.id = s.competition_id JOIN rades r ON r.id = s.rade_id';
    }
    protected function baseSelect(): array
    {
        return ['s.id', 's.position', 's.is_winner', "(u.first_name || ' ' || u.last_name) AS rider",
            'h.name AS horse', 'c.title AS competition', 'r.name AS rade', 's.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'rider', 'label' => 'سوارکار', 'type' => 'string', 'exportable' => true],
            ['key' => 'horse', 'label' => 'اسب', 'type' => 'string', 'exportable' => true],
            ['key' => 'competition', 'label' => 'مسابقه', 'type' => 'string', 'exportable' => true],
            ['key' => 'rade', 'label' => 'رده', 'type' => 'string', 'exportable' => true],
            ['key' => 'position', 'label' => 'مقام', 'type' => 'int', 'exportable' => true],
            ['key' => 'is_winner', 'label' => 'برنده', 'type' => 'bool', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [
            ['key' => 'competition_id', 'label' => 'مسابقه', 'type' => 'int', 'sql' => 's.competition_id', 'operators' => ['eq']],
            ['key' => 'rade_id', 'label' => 'رده', 'type' => 'int', 'sql' => 's.rade_id', 'operators' => ['eq']],
            ['key' => 'winner', 'label' => 'برنده', 'type' => 'bool', 'sql' => 's.is_winner', 'operators' => ['eq']],
        ];
    }
    public function defaultColumns(): array { return ['rider', 'horse', 'competition', 'rade', 'position', 'is_winner']; }
    public function defaultSort(): string { return 's.position'; }
    public function project(array $row, Database $db): array
    {
        return ['rider' => $row['rider'] ?? '', 'horse' => $row['horse'] ?? '', 'competition' => $row['competition'] ?? '',
            'rade' => $row['rade'] ?? '', 'position' => $row['position'], 'is_winner' => (bool) ($row['is_winner'] ?? false)];
    }
    public function summary(Database $db, array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $row = $db->selectOne('SELECT SUM(CASE WHEN s.is_winner=1 THEN 1 ELSE 0 END) AS winners, AVG(s.position) AS avg_pos '
            . $this->baseFrom() . ' WHERE ' . $where, $params) ?? [];
        return ['winners' => (int) ($row['winners'] ?? 0), 'avg_position' => round((float) ($row['avg_pos'] ?? 0), 2)];
    }
}

/**
 * Class: HorsesReport
 *
 * Key: horses
 * Columns: name, owner, status, gender, race, color, created_at
 */
final class HorsesReport extends BaseReport
{
    public static function key(): string { return 'horses'; }
    public function label(): string { return 'اسبان'; }
    protected function baseFrom(): string { return 'FROM horses h JOIN users u ON u.id = h.owner_user_id'; }
    protected function baseSelect(): array
    {
        return ['h.id', 'h.name', 'h.status', 'h.gender', 'h.race', 'h.color', "(u.first_name || ' ' || u.last_name) AS owner", 'h.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'نام اسب', 'type' => 'string', 'exportable' => true],
            ['key' => 'owner', 'label' => 'مالک', 'type' => 'string', 'exportable' => true],
            ['key' => 'status', 'label' => 'وضعیت', 'type' => 'enum', 'exportable' => true],
            ['key' => 'gender', 'label' => 'جنسیت', 'type' => 'string', 'exportable' => true],
            ['key' => 'race', 'label' => 'نژاد', 'type' => 'string', 'exportable' => true],
            ['key' => 'color', 'label' => 'رنگ', 'type' => 'string', 'exportable' => true],
            ['key' => 'created_at', 'label' => 'تاریخ ثبت', 'type' => 'datetime', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [
            ['key' => 'status', 'label' => 'وضعیت', 'type' => 'enum', 'sql' => 'h.status', 'operators' => ['in', 'eq'], 'options' => ['active', 'sold_to_non_rider', 'soft_deleted']],
            ['key' => 'owner', 'label' => 'مالک', 'type' => 'string', 'sql' => "(u.first_name || ' ' || u.last_name)", 'operators' => ['contains']],
        ];
    }
    public function defaultColumns(): array { return ['name', 'owner', 'status', 'gender', 'race']; }
    public function defaultSort(): string { return 'h.created_at'; }
    public function project(array $row, Database $db): array
    {
        return ['name' => $row['name'] ?? '', 'owner' => $row['owner'] ?? '', 'status' => $row['status'] ?? '',
            'gender' => $row['gender'] ?? '', 'race' => $row['race'] ?? '', 'color' => $row['color'] ?? '', 'created_at' => $row['created_at'] ?? ''];
    }
    public function summary(Database $db, array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $active = (int) $db->scalar("SELECT COUNT(*) " . $this->baseFrom() . " WHERE " . $where . " AND h.status='active'", $params);
        return ['active' => $active];
    }
}

/**
 * Class: RidersReport
 *
 * Key: riders
 * Columns: rider, phone, verification, disable_state, wins, created_at
 */
final class RidersReport extends BaseReport
{
    public static function key(): string { return 'riders'; }
    public function label(): string { return 'سوارکاران'; }
    protected function baseFrom(): string { return "FROM users u LEFT JOIN rider_profiles rp ON rp.user_id = u.id"; }
    protected function baseSelect(): array
    {
        return ['u.id', "u.first_name || ' ' || u.last_name AS rider", 'u.phone', 'u.verification_status', 'u.disable_state',
            '(SELECT COUNT(*) FROM signups s WHERE s.rider_user_id = u.id AND s.is_winner = 1) AS wins', 'u.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'rider', 'label' => 'سوارکار', 'type' => 'string', 'exportable' => true],
            ['key' => 'phone', 'label' => 'موبایل', 'type' => 'string', 'exportable' => true],
            ['key' => 'verification_status', 'label' => 'تایید', 'type' => 'enum', 'exportable' => true],
            ['key' => 'disable_state', 'label' => 'وضعیت', 'type' => 'enum', 'exportable' => true],
            ['key' => 'wins', 'label' => 'بردها', 'type' => 'int', 'exportable' => true],
            ['key' => 'created_at', 'label' => 'تاریخ عضویت', 'type' => 'datetime', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [
            ['key' => 'verification_status', 'label' => 'تایید', 'type' => 'enum', 'sql' => 'u.verification_status', 'operators' => ['in', 'eq'], 'options' => ['pending', 'verified', 'rejected']],
            ['key' => 'rider', 'label' => 'سوارکار', 'type' => 'string', 'sql' => "(u.first_name || ' ' || u.last_name)", 'operators' => ['contains']],
        ];
    }
    public function defaultColumns(): array { return ['rider', 'phone', 'verification_status', 'wins']; }
    public function defaultSort(): string { return 'u.created_at'; }
    public function authorize(array $actor): bool { return in_array($actor['role'] ?? '', ['admin', 'manager'], true); }
    public function project(array $row, Database $db): array
    {
        return ['rider' => $row['rider'] ?? '', 'phone' => $row['phone'] ?? '', 'verification_status' => $row['verification_status'] ?? '',
            'disable_state' => $row['disable_state'] ?? '', 'wins' => (int) ($row['wins'] ?? 0), 'created_at' => $row['created_at'] ?? ''];
    }
    public function summary(Database $db, array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $total = (int) $db->scalar('SELECT COUNT(*) ' . $this->baseFrom() . ' WHERE ' . $where . " AND u.role='rider'", $params);
        return ['active_riders' => $total];
    }
}

/**
 * Class: ClubsReport
 *
 * Key: clubs
 * Columns: name, city, riders, signups, revenue
 *
 * Scoping:
 *   - Club: only the club that belongs to the current club user.
 */
final class ClubsReport extends BaseReport
{
    public static function key(): string { return 'clubs'; }
    public function label(): string { return 'باشگاه‌ها'; }
    protected function baseFrom(): string { return 'FROM clubs cl'; }
    protected function baseSelect(): array
    {
        return ['cl.id', 'cl.name', 'cl.city',
            "(SELECT COUNT(DISTINCT s.rider_user_id) FROM signups s WHERE s.affiliation_club_id = cl.id) AS riders",
            "(SELECT COUNT(*) FROM signups s WHERE s.affiliation_club_id = cl.id) AS signups",
            "(SELECT COALESCE(SUM(po.amount_irt),0) FROM payment_orders po JOIN signups s ON s.id = po.signup_id WHERE s.affiliation_club_id = cl.id AND po.status='paid') AS revenue",
            'cl.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'نام باشگاه', 'type' => 'string', 'exportable' => true],
            ['key' => 'city', 'label' => 'شهر', 'type' => 'string', 'exportable' => true],
            ['key' => 'riders', 'label' => 'سوارکاران', 'type' => 'int', 'exportable' => true],
            ['key' => 'signups', 'label' => 'ثبت‌نام‌ها', 'type' => 'int', 'exportable' => true],
            ['key' => 'revenue', 'label' => 'درآمد', 'type' => 'int', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [['key' => 'name', 'label' => 'نام', 'type' => 'string', 'sql' => 'cl.name', 'operators' => ['contains']]];
    }
    public function defaultColumns(): array { return ['name', 'city', 'riders', 'signups', 'revenue']; }
    public function defaultSort(): string { return 'cl.created_at'; }
    public function authorize(array $actor): bool { return in_array($actor['role'] ?? '', ['admin', 'manager', 'club'], true); }

    public function scopeFilters(array $actor): array
    {
        if (($actor['role'] ?? '') === 'club') {
            return [[
                'col' => 'club_user_id',
                'sql' => 'cl.user_id',
                'op' => 'eq',
                'value' => (int) $actor['id'],
            ]];
        }
        return [];
    }

    public function project(array $row, Database $db): array
    {
        return ['name' => $row['name'] ?? '', 'city' => $row['city'] ?? '', 'riders' => (int) ($row['riders'] ?? 0),
            'signups' => (int) ($row['signups'] ?? 0), 'revenue' => (int) ($row['revenue'] ?? 0)];
    }
}

/**
 * Class: PaymentsReport
 *
 * Key: payments
 * Columns: name, amount, active, created_at
 */
final class PaymentsReport extends BaseReport
{
    public static function key(): string { return 'payments'; }
    public function label(): string { return 'پرداخت‌ها'; }
    protected function baseFrom(): string { return 'FROM payments p'; }
    protected function baseSelect(): array { return ['p.id', 'p.name', 'p.amount_irt AS amount', 'p.is_active', 'p.created_at']; }
    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'نام', 'type' => 'string', 'exportable' => true],
            ['key' => 'amount', 'label' => 'مبلغ', 'type' => 'int', 'exportable' => true],
            ['key' => 'is_active', 'label' => 'فعال', 'type' => 'bool', 'exportable' => true],
            ['key' => 'created_at', 'label' => 'تاریخ', 'type' => 'datetime', 'exportable' => true],
        ];
    }
    public function filters(): array { return [['key' => 'name', 'label' => 'نام', 'type' => 'string', 'sql' => 'p.name', 'operators' => ['contains']]]; }
    public function defaultColumns(): array { return ['name', 'amount', 'is_active']; }
    public function defaultSort(): string { return 'p.created_at'; }
    public function project(array $row, Database $db): array
    {
        return ['name' => $row['name'] ?? '', 'amount' => (int) ($row['amount'] ?? 0), 'is_active' => (bool) ($row['is_active'] ?? false), 'created_at' => $row['created_at'] ?? ''];
    }
}

/**
 * Class: BansReport
 *
 * Key: bans
 * Columns: target, type, scope, reason, expires_at
 */
final class BansReport extends BaseReport
{
    public static function key(): string { return 'bans'; }
    public function label(): string { return 'تحریم‌ها'; }
    protected function baseFrom(): string { return 'FROM rider_bans b'; }
    protected function baseSelect(): array
    {
        return ['b.id', "CASE WHEN b.target_type='rider' THEN (SELECT first_name || ' ' || last_name FROM users WHERE id=b.target_id) ELSE (SELECT name FROM horses WHERE id=b.target_id) END AS target",
            'b.target_type', 'b.scope', 'b.reason', 'b.expires_at', 'b.created_at'];
    }
    public function columns(): array
    {
        return [
            ['key' => 'target', 'label' => 'هدف', 'type' => 'string', 'exportable' => true],
            ['key' => 'target_type', 'label' => 'نوع', 'type' => 'enum', 'exportable' => true],
            ['key' => 'scope', 'label' => 'دامنه', 'type' => 'enum', 'exportable' => true],
            ['key' => 'reason', 'label' => 'دلیل', 'type' => 'string', 'exportable' => true],
            ['key' => 'created_at', 'label' => 'تاریخ', 'type' => 'datetime', 'exportable' => true],
        ];
    }
    public function filters(): array
    {
        return [
            ['key' => 'scope', 'label' => 'دامنه', 'type' => 'enum', 'sql' => 'b.scope', 'operators' => ['in', 'eq'], 'options' => ['global', 'competition', 'rade']],
            ['key' => 'target_type', 'label' => 'نوع', 'type' => 'enum', 'sql' => 'b.target_type', 'operators' => ['in', 'eq'], 'options' => ['rider', 'horse']],
        ];
    }
    public function defaultColumns(): array { return ['target', 'target_type', 'scope', 'created_at']; }
    public function defaultSort(): string { return 'b.created_at'; }
    public function project(array $row, Database $db): array
    {
        return ['target' => $row['target'] ?? '', 'target_type' => $row['target_type'] ?? '', 'scope' => $row['scope'] ?? '',
            'reason' => $row['reason'] ?? '', 'created_at' => $row['created_at'] ?? ''];
    }
}

/**
 * Class: Reports
 *
 * Purpose: Registry mapping report keys to instances.
 */
final class Reports
{
    /**
     * All report keys.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return ['signups', 'revenue', 'results', 'horses', 'riders', 'clubs', 'payments', 'bans'];
    }

    /**
     * Instantiate a report by key.
     *
     * @param string $key Report key.
     * @return ReportInterface|null
     */
    public static function make(string $key): ?ReportInterface
    {
        return match ($key) {
            'signups' => new SignupsReport(),
            'revenue' => new RevenueReport(),
            'results' => new ResultsReport(),
            'horses' => new HorsesReport(),
            'riders' => new RidersReport(),
            'clubs' => new ClubsReport(),
            'payments' => new PaymentsReport(),
            'bans' => new BansReport(),
            default => null,
        };
    }

    /**
     * Instantiate all reports keyed by key.
     *
     * @return array<string,ReportInterface>
     */
    public static function registry(): array
    {
        $out = [];
        foreach (self::keys() as $k) {
            $out[$k] = self::make($k);
        }
        return $out;
    }
}