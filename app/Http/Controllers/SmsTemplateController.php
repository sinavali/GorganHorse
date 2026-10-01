<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/SmsTemplateController.php
 *
 * Purpose:
 *   Admin CRUD for SMS templates + SMS log viewer (Blueprint §14.2, Technical §20).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\SmsTemplate;
use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: SmsTemplateController
 * Purpose: Manage SMS templates and view SMS delivery logs.
 */
final class SmsTemplateController extends BaseController
{
    /**
     * Get a single template (JSON).
     *
     * Route:   GET /panel/sms/templates/{id}
     * Auth:    role:admin
     * Returns: JSON
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) ($request->attr('id') ?? 0);
        $row = $this->c->get('db')->selectOne('SELECT * FROM sms_templates WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return $this->fail('NOT_FOUND', 'قالب یافت نشد', $ctx, 404);
        }
        $row['variables'] = json_decode((string) ($row['variables'] ?? '[]'), true) ?? [];
        return $this->ok($row, $ctx);
    }

    /**
     * List templates or create new.
     *
     * Route:   GET|POST /panel/sms/templates
     * Auth:    role:admin
     * Returns: HTML (GET) or JSON envelope (POST)
     */
    public function templates(Request $request, MiddlewareContext $ctx): Response
    {
        if ($request->isMethod('POST')) {
            $input = $this->input($request);
            $name = trim((string) ($input['name'] ?? ''));
            $body = trim((string) ($input['body'] ?? ''));
            if ($name === '' || $body === '') {
                return $this->fail('VALIDATION_FAILED', 'نام و محتوا الزامی هستند', $ctx, 422);
            }
            $variables = isset($input['variables']) && is_array($input['variables'])
                ? $input['variables']
                : [];
            $now = now_utc();
            $id = $this->c->get('db')->insert('sms_templates', [
                'name' => $name,
                'body' => $body,
                'variables' => json_encode($variables),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->c->get('log')->audit(['actor_id' => (int) $ctx->user['id'], 'actor_role' => $ctx->user['role'] ?? 'admin', 'action' => 'sms_template.create', 'target_type' => 'sms_template', 'target_id' => (int) $id, 'diff' => ['name' => $name]]);
            return $this->ok(['id' => (int) $id, 'name' => $name], $ctx);
        }

        $rows = $this->c->get('db')->select('SELECT * FROM sms_templates ORDER BY id DESC');
        return $this->ok(is_array($rows) ? $rows : [], $ctx);
    }

    /**
     * Update a template.
     *
     * Route:   POST /panel/sms/templates/{id}
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) ($request->attr('id') ?? 0);
        $input = $this->input($request);
        $name = trim((string) ($input['name'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if ($name === '' || $body === '') {
            return $this->fail('VALIDATION_FAILED', 'نام و محتوا الزامی هستند', $ctx, 422);
        }
        $variables = isset($input['variables']) && is_array($input['variables'])
            ? $input['variables']
            : [];
        $now = now_utc();
        $this->c->get('db')->update('sms_templates', [
            'name' => $name,
            'body' => $body,
            'variables' => json_encode($variables),
            'updated_at' => $now,
        ], 'id = :id', ['id' => $id]);
        $this->c->get('log')->audit(['actor_id' => (int) $ctx->user['id'], 'actor_role' => $ctx->user['role'] ?? 'admin', 'action' => 'sms_template.update', 'target_type' => 'sms_template', 'target_id' => $id, 'diff' => ['name' => $name]]);
        return $this->ok(['id' => $id, 'name' => $name], $ctx);
    }

    /**
     * Toggle template active state.
     *
     * Route:   POST /panel/sms/templates/{id}/toggle
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function toggle(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) ($request->attr('id') ?? 0);
        $row = $this->c->get('db')->selectOne('SELECT is_active FROM sms_templates WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return $this->fail('NOT_FOUND', 'قالب یافت نشد', $ctx, 404);
        }
        $newState = ((int) ($row['is_active'] ?? 0)) === 1 ? 0 : 1;
        $this->c->get('db')->update('sms_templates', ['is_active' => $newState, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
        return $this->ok(['id' => $id, 'is_active' => $newState], $ctx);
    }

    /**
     * Delete a template.
     *
     * Route:   POST /panel/sms/templates/{id}/delete
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function delete(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) ($request->attr('id') ?? 0);
        $row = $this->c->get('db')->selectOne('SELECT id FROM sms_templates WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return $this->fail('NOT_FOUND', 'قالب یافت نشد', $ctx, 404);
        }
        $this->c->get('db')->delete('sms_templates', 'id = :id', ['id' => $id]);
        $this->c->get('log')->audit(['actor_id' => (int) $ctx->user['id'], 'actor_role' => $ctx->user['role'] ?? 'admin', 'action' => 'sms_template.delete', 'target_type' => 'sms_template', 'target_id' => $id]);
        return $this->ok(['deleted' => true], $ctx);
    }

    /**
     * SMS delivery log (last 50 with status filter).
     *
     * Route:   GET /panel/sms/log
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function log(Request $request, MiddlewareContext $ctx): Response
    {
        $status = (string) ($request->query('status', '') ?: '');
        $search = trim((string) ($request->query('search', '') ?: ''));
        $days = (int) ($request->query('days', 0) ?: 0);
        /** @var \App\Bootstrap\Database $logsDb */
        $logsDb = $this->c->get('logs_db');
        $sql = 'SELECT * FROM sms_logs WHERE 1=1';
        $params = [];
        if ($status !== '' && in_array($status, ['sent', 'failed', 'skipped'], true)) {
            $sql .= ' AND status = :s';
            $params['s'] = $status;
        }
        if ($search !== '') {
            $sql .= ' AND (recipient LIKE :q OR body LIKE :q OR error LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        if ($days > 0) {
            $sql .= ' AND created_at >= :since';
            $params['since'] = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
        }
        /* Delivery report as CSV, honouring the same filters. */
        if ($request->query('format') === 'csv') {
            $all = $logsDb->select($sql . ' ORDER BY id DESC LIMIT 20000', $params);
            $is_array = is_array($all) ? $all : [];
            $lines = "\xEF\xBB\xBF" . '"شناسه","شماره","وضعیت","پیام","خطا","زمان"' . "\n";
            $culture = $this->c->get('culture');
            foreach ($is_array as $r) {
                $cells = [
                    (string) ($r['id'] ?? ''),
                    (string) ($r['recipient'] ?? ''),
                    (string) ($r['status'] ?? ''),
                    (string) ($r['body'] ?? ''),
                    (string) ($r['error'] ?? ''),
                    /* Readable, culture-aware timestamp instead of raw UTC ISO. */
                    $culture->exportCell((string) ($r['created_at'] ?? '')),
                ];
                $lines .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', $v) . '"', $cells)) . "\n";
            }
            $filename = 'sms-delivery-' . gmdate('Ymd-His') . '.csv';
            $path = BASE_PATH . '/cache/' . $filename;
            @file_put_contents($path, $lines);
            return Response::download($path, $filename);
        }

        $sql .= ' ORDER BY id DESC LIMIT 50';
        $rows = $logsDb->select($sql, $params);
        if (!is_array($rows)) { $rows = []; }

        $stats = $logsDb->select('SELECT status, COUNT(*) as cnt FROM sms_logs GROUP BY status');
        $stats = is_array($stats) ? $stats : [];
        $total = 0;
        foreach ($stats as $s) { $total += (int) ($s['cnt'] ?? 0); }
        $sent = 0;
        foreach ($stats as $s) { if (($s['status'] ?? '') === 'sent') { $sent += (int) ($s['cnt'] ?? 0); } }

        return $this->ok([
            'rows' => $rows,
            'status' => $status,
            'search' => $search,
            'days' => $days,
            'stats' => $stats,
            'summary' => [
                'total' => $total,
                'sent' => $sent,
                'failed' => $total - $sent,
                'success_rate' => $total > 0 ? (int) round(($sent / $total) * 100) : 0,
            ],
        ], $ctx);
    }
}