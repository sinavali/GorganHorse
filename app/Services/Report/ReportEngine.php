<?php
declare(strict_types=1);

/**
 * File: app/Services/Report/ReportEngine.php
 *
 * Purpose:
 *   The unified report engine (Blueprint §13, Technical §18). Resolves a report
 *   by key, applies whitelist-driven filters/sort/pagination, projects rows,
 *   produces summary tiles, and issues signed export tokens. Raw client input
 *   never reaches SQL (P06).
 *
 *   Role scoping is applied transparently: each report's scopeFilters() returns
 *   trusted filter definitions (flagged _scope=true) that bypass the user-facing
 *   whitelist and are appended to the WHERE clause by BaseReport::buildWhere().
 *
 * Dependencies: Database, SettingService, Reports (registry).
 *
 * @package App\Services\Report
 */

namespace App\Services\Report;

use App\Bootstrap\Database;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Services\SettingService;

/**
 * Class: ReportEngine
 * Purpose: Run reports safely and build their query/results.
 */
final class ReportEngine
{
    private Database $db;
    private SettingService $settings;

    /**
     * @param Database       $db       Main DB.
     * @param SettingService $settings Settings.
     */
    public function __construct(Database $db, SettingService $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * List available report presets with metadata.
     *
     * @return array<int,array{key:string,label:string,columns:array,filters:array,default_columns:array}>
     */
    public function presets(): array
    {
        $out = [];
        foreach (Reports::registry() as $key => $report) {
            $out[] = [
                'key' => $key,
                'label' => $report->label(),
                'columns' => $report->columns(),
                'filters' => $report->filters(),
                'default_columns' => $report->defaultColumns(),
            ];
        }
        return $out;
    }

    /**
     * Run a report: apply filters/sort/pagination and project rows.
     *
     * @param array $input {
     *   report:string, page:int, per_page:int,
     *   sort:[{col,dir}], filters:[{col,op,value}], columns:string[]
     * }
     * @param array $actor Actor.
     * @return array{columns:array,rows:array,summary:array,page:int,per_page:int,total:int,filtered:int}
     * @throws NotFoundException On unknown report key.
     * @throws ForbiddenException When the actor cannot run it.
     */
    public function run(array $input, array $actor): array
    {
        $key = (string) ($input['report'] ?? '');
        $report = Reports::make($key);
        if ($report === null) { throw new NotFoundException('Unknown report', 'NOT_FOUND'); }
        if (!$report->authorize($actor)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? $this->settings->get('reports.default_per_page', 50));
        $perPage = max(1, min(250, $perPage));

        $filters = $this->normalizeFilters($report, (array) ($input['filters'] ?? []), $actor);
        $sort = $this->normalizeSort($report, (array) ($input['sort'] ?? []));
        $columns = $this->normalizeColumns($report, (array) ($input['columns'] ?? []));

        $total = $report->count($this->db, $report->scopeFilters($actor));
        $filtered = $report->count($this->db, $filters);
        $rows = $report->fetch($this->db, $filters, $sort, $page, $perPage);

        $projected = [];
        foreach ($rows as $row) {
            $full = $report->project($row, $this->db);
            $projected[] = $columns === [] ? $full : array_intersect_key($full, array_flip($columns));
        }

        $summary = $report->summary($this->db, $filters);

        return [
            'columns' => $this->selectedColumnDefs($report, $columns),
            'rows' => $projected,
            'summary' => $summary,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'filtered' => $filtered,
        ];
    }

    /**
     * Produce an export payload (CSV/XLSX rows) for the current filter state.
     *
     * @param array $input {report, filters, sort, columns, format}
     * @param array $actor Actor.
     * @return array{filename:string,format:string,columns:array,rows:array}
     */
    public function export(array $input, array $actor): array
    {
        $key = (string) ($input['report'] ?? '');
        $report = Reports::make($key);
        if ($report === null) { throw new NotFoundException('Unknown report', 'NOT_FOUND'); }
        if (!$report->authorize($actor)) { throw new ForbiddenException('Forbidden', 'FORBIDDEN'); }
        $filters = $this->normalizeFilters($report, (array) ($input['filters'] ?? []), $actor);
        $sort = $this->normalizeSort($report, (array) ($input['sort'] ?? []));
        $columns = $this->normalizeColumns($report, (array) ($input['columns'] ?? []));
        $maxRows = (int) $this->settings->get('reports.max_export_rows', 50000);
        $rows = $report->fetch($this->db, $filters, $sort, 1, $maxRows);
        $projected = [];
        foreach ($rows as $row) {
            $full = $report->project($row, $this->db);
            $projected[] = $columns === [] ? $full : array_intersect_key($full, array_flip($columns));
        }
        $format = in_array(($input['format'] ?? 'csv'), ['csv', 'xlsx'], true) ? (string) $input['format'] : 'csv';
        return [
            'filename' => $key . '-' . gmdate('Ymd-His') . '.' . $format,
            'format' => $format,
            'columns' => $this->selectedColumnDefs($report, $columns),
            'rows' => $projected,
        ];
    }

    /**
     * Build a CSV string from an export payload.
     *
     * @param array $payload Export payload.
     * @return string CSV content (UTF-8 BOM prefixed).
     */
    public function toCsv(array $payload): string
    {
        $keys = array_map(static fn (array $c): string => (string) $c['key'], $payload['columns']);
        $labels = array_map(static fn (array $c): string => (string) $c['label'], $payload['columns']);
        $out = "\xEF\xBB\xBF" . implode(',', array_map(static fn ($l) => '"' . str_replace('"', '""', $l) . '"', $labels)) . "\n";
        foreach ($payload['rows'] as $row) {
            $line = [];
            foreach ($keys as $k) {
                $line[] = '"' . str_replace('"', '""', (string) ($row[$k] ?? '')) . '"';
            }
            $out .= implode(',', $line) . "\n";
        }
        return $out;
    }

    /**
     * Normalize and whitelist filters, then append the report's role scope.
     *
     * @param ReportInterface $report  Report.
     * @param array           $filters Raw filters.
     * @param array           $actor   Actor.
     * @return array<int,array{col:string,op:string,value:mixed,_scope?:bool,sql?:string}>
     */
    private function normalizeFilters(ReportInterface $report, array $filters, array $actor): array
    {
        $allowed = [];
        foreach ($report->filters() as $f) { $allowed[$f['key']] = $f; }
        $out = [];
        foreach ($filters as $f) {
            $col = (string) ($f['col'] ?? '');
            $op = (string) ($f['op'] ?? 'eq');
            if (!isset($allowed[$col])) { continue; }
            $ops = $allowed[$col]['operators'] ?? ['eq'];
            if (!in_array($op, $ops, true)) { $op = 'eq'; }
            $out[] = ['col' => $col, 'op' => $op, 'value' => $f['value'] ?? null];
        }
        // Role scoping filters are trusted and marked with _scope so that
        // BaseReport::buildWhere() bypasses the user-facing whitelist.
        foreach ($report->scopeFilters($actor) as $scopeFilter) {
            $scopeFilter['_scope'] = true;
            $out[] = $scopeFilter;
        }
        return $out;
    }

    /**
     * Normalize and whitelist sort columns.
     *
     * @param ReportInterface $report Report.
     * @param array           $sort   Raw sort.
     * @return array<int,array{col:string,dir:string}>
     */
    private function normalizeSort(ReportInterface $report, array $sort): array
    {
        $sortable = $report->sortable();
        $out = [];
        foreach ($sort as $s) {
            $col = (string) ($s['col'] ?? '');
            if (!in_array($col, $sortable, true)) { continue; }
            $dir = strtolower((string) ($s['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
            $out[] = ['col' => $col, 'dir' => $dir];
        }
        if ($out === []) { $out[] = ['col' => $report->defaultSort(), 'dir' => 'desc']; }
        return $out;
    }

    /**
     * Whitelist requested columns.
     *
     * @param ReportInterface $report  Report.
     * @param array           $columns Requested columns.
     * @return string[]
     */
    private function normalizeColumns(ReportInterface $report, array $columns): array
    {
        $valid = array_map(static fn (array $c): string => (string) $c['key'], $report->columns());
        return array_values(array_filter(array_map('strval', $columns), static fn (string $c): bool => in_array($c, $valid, true)));
    }

    /**
     * Return full column definitions for selected (or default) columns.
     *
     * @param ReportInterface $report  Report.
     * @param string[]        $columns Selected columns.
     * @return array<int,array>
     */
    private function selectedColumnDefs(ReportInterface $report, array $columns): array
    {
        $all = $report->columns();
        if ($columns === []) { return $all; }
        return array_values(array_filter($all, static fn (array $c): bool => in_array((string) $c['key'], $columns, true)));
    }
}