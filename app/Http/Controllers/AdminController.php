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
        $total = (int) $db->scalar('SELECT COUNT(*) FROM audit_logs WHERE ' . implode(' AND ', $where), $params);
        $rows = $db->select('SELECT * FROM audit_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT :l OFFSET :o', $params + ['l' => $perPage, 'o' => $offset]);
        if ($request->isJson()) {
            return $this->ok(['rows' => $rows, 'total' => $total], $ctx, 200, ['total' => $total]);
        }
        return $this->view('panel/audit', ['rows' => $rows, 'total' => $total, 'filters' => $filters, 'csrf' => $ctx->csrf]);
    }

    /**
     * Backups page.
     *
     * Route:   GET /panel/backups
     * Auth:    role:admin
     * Returns: HTML or JSON
     */
    public function backups(Request $request, MiddlewareContext $ctx): Response
    {
        $list = $this->c->get('backup')->list();
        if ($request->isJson()) {
            return $this->ok($list, $ctx);
        }
        return $this->view('panel/backups', ['rows' => $list, 'csrf' => $ctx->csrf]);
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
            return Response::html('Not found', 404);
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
}
