<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/AdminController.php
 *
 * Purpose:
 *   HTTP layer for admin operations: audit viewer, backups, reset, and demo
 *   seed/clear (Blueprint §10.3, §21; User Usage §7.22–7.23).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: AdminController
 * Purpose: Handle audit, backup, and maintenance endpoints.
 */
final class AdminController extends BaseController
{
    /**
     * Audit log viewer (Admin only).
     *
     * Route:   GET /panel/audit
     * Auth:    role:admin
     * Returns: HTML or JSON
     */
    public function audit(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = [
            'actor_id' => (int) $request->query('actor_id', 0),
            'action' => (string) $request->query('action', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
        $where = ['1=1'];
        $params = [];
        if ($filters['actor_id'] > 0) {
            $where[] = 'actor_id = :a';
            $params['a'] = $filters['actor_id'];
        }
        if ($filters['action'] !== '') {
            $where[] = 'action LIKE :act';
            $params['act'] = '%' . $filters['action'] . '%';
        }
        if ($filters['from'] !== '') {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'created_at <= :to';
            $params['to'] = $filters['to'];
        }
        $page = $this->page($request);
        $perPage = $this->perPage($request);
        $offset = ($page - 1) * $perPage;
        $db = $this->c->get('logs_db');
        $whereSql = implode(' AND ', $where);

        /* CSV export honours exactly the same filters as the on-screen list. */
        if ($request->query('format') === 'csv') {
            $all = $db->select('SELECT * FROM audit_logs WHERE ' . $whereSql . ' ORDER BY id DESC LIMIT 20000', $params);
            $is_array = is_array($all) ? $all : [];
            /* audit_logs lives in the logs database, so actor names are resolved
               separately from the main database. */
            $names = [];
            foreach ($this->c->get('db')->select('SELECT id, username, first_name, last_name FROM users') as $u) {
                $names[(int) $u['id']] = trim((string) $u['first_name'] . ' ' . (string) $u['last_name']) !== ''
                    ? (string) $u['first_name'] . ' ' . (string) $u['last_name']
                    : (string) $u['username'];
            }
            $lines = "\xEF\xBB\xBF" . '"شناسه","کاربر","نقش","عملیات","نوع موجودیت","شناسه موجودیت","نتیجه","نشانی IP","زمان"' . "\n";
            foreach ($is_array as $r) {
                $actorId = (int) ($r['actor_id'] ?? 0);
                $actor = $actorId > 0 ? ($names[$actorId] ?? ('#' . $actorId)) : 'سامانه';
                $cells = [
                    (string) ($r['id'] ?? ''),
                    $actor,
                    (string) ($r['actor_role'] ?? ''),
                    (string) ($r['action'] ?? ''),
                    (string) ($r['target_type'] ?? ''),
                    (string) ($r['target_id'] ?? ''),
                    (string) ($r['result'] ?? ''),
                    (string) ($r['ip'] ?? ''),
                    (string) ($r['created_at'] ?? ''),
                ];
                $lines .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', $v) . '"', $cells)) . "\n";
            }
            $filename = 'audit-log-' . gmdate('Ymd-His') . '.csv';
            $path = BASE_PATH . '/cache/' . $filename;
            @file_put_contents($path, $lines);
            return Response::download($path, $filename);
        }

        $total = (int) $db->scalar('SELECT COUNT(*) FROM audit_logs WHERE ' . $whereSql, $params);
        $rows = $db->select('SELECT * FROM audit_logs WHERE ' . $whereSql . ' ORDER BY id DESC LIMIT :l OFFSET :o', $params + ['l' => $perPage, 'o' => $offset]);
        $actions = $db->select("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action LIMIT 60");
        return $this->ok([
            'rows' => is_array($rows) ? $rows : [],
            'total' => $total,
            'filters' => $filters,
            'actions' => is_array($actions) ? $actions : [],
        ], $ctx, 200, ['total' => $total]);
    }

    /**
     * List backups.
     *
     * Route:   GET /panel/backups
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function backups(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('backup')->list(), $ctx);
    }

    /**
     * Create a backup.
     *
     * Route:   POST /panel/backups
     * Auth:    role:admin
     * Params:  suffix?
     * Returns: JSON envelope { data: { name } }
     */
    public function createBackup(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $result = $this->c->get('backup')->create((string) ($input['suffix'] ?? 'manual'), (int) $ctx->user['id']);
        return $this->ok($result, $ctx, 201);
    }

    /**
     * Restore a backup (typed confirmation enforced client-side).
     *
     * Route:   POST /panel/backups/{name}/restore
     * Auth:    role:admin
     * Returns: JSON envelope { data: null }
     */
    public function restore(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('backup')->restore(
            (string) $request->attr('name'),
            (int) $ctx->user['id'],
            (string) ($request->cookie('session_id') ?? '')
        );
        return $this->ok(null, $ctx);
    }

    /**
     * Download a backup file.
     *
     * Route:   GET /panel/backups/{name}/download
     * Auth:    role:admin
     * Returns: file download
     */
    public function downloadBackup(Request $request, MiddlewareContext $ctx): Response
    {
        $path = $this->c->get('backup')->path((string) $request->attr('name'));
        if (!is_file($path)) {
            return $this->fail('NOT_FOUND', 'Backup not found', $ctx, 404);
        }
        return Response::download($path, basename($path));
    }

    /**
     * Delete a backup.
     *
     * Route:   DELETE /panel/backups/{name}
     * Auth:    role:admin
     * Returns: JSON envelope { data: null }
     */
    public function deleteBackup(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('backup')->delete((string) $request->attr('name'));
        return $this->ok(null, $ctx);
    }

    /**
     * Reset the database (typed confirmation enforced client-side).
     *
     * Route:   POST /panel/reset
     * Auth:    role:admin
     * Returns: JSON envelope { data: null }
     */
    public function reset(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('backup')->reset((int) $ctx->user['id'], (string) ($request->cookie('session_id') ?? ''));
        return $this->ok(null, $ctx);
    }

    /**
     * Seed demo data.
     *
     * Route:   POST /panel/demo/seed
     * Auth:    role:admin
     * Returns: JSON envelope { data: { summary } }
     */
    public function seedDemo(Request $request, MiddlewareContext $ctx): Response
    {
        $summary = $this->c->get('demo')->seed();
        return $this->ok($summary, $ctx);
    }

    /**
     * Clear demo data.
     *
     * Route:   POST /panel/demo/clear
     * Auth:    role:admin
     * Returns: JSON envelope { data: { cleared } }
     */
    public function clearDemo(Request $request, MiddlewareContext $ctx): Response
    {
        $cleared = $this->c->get('demo')->clear();
        return $this->ok(['cleared' => $cleared], $ctx);
    }

    /**
     * Generate a QR code for a data string (cached).
     *
     * Route:   GET /panel/qr?data=...
     * Auth:    auth
     * Returns: JSON envelope { data: { data } } (client renders the QR)
     */
    public function qr(Request $request, MiddlewareContext $ctx): Response
    {
        $data = (string) $request->query('data', '');
        return $this->ok(['data' => $data], $ctx);
    }

    /**
     * Run the scheduled jobs (deadline alerts, auto verification, backups).
     *
     * Route:   POST /panel/cron?key=...
     * Auth:    guest + shared secret matching settings.scheduler.secret, so a
     *          host cron job can trigger it without a browser session.
     * Returns: JSON envelope { data: { deadline_alerts: {...}, ... } }
     */
    public function runCron(Request $request, MiddlewareContext $ctx): Response
    {
        $secret = (string) $this->c->get('settings')->get('scheduler.secret', '');
        $provided = (string) ($request->query('key', '') ?: '');
        if ($secret === '' || !hash_equals($secret, $provided)) {
            return $this->fail('FORBIDDEN', 'کلید زمان‌بند نامعتبر است', $ctx, 403);
        }
        return $this->ok($this->c->get('scheduler')->runAll(), $ctx);
    }
}
