<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/ReportController.php
 *
 * Purpose:
 *   HTTP layer for the unified report engine: presets, data, export, signed
 *   download, and in-panel sharing (Blueprint §10.3, §13; Technical §18).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: ReportController
 * Purpose: Handle report endpoints.
 */
final class ReportController extends BaseController
{
    /**
     * Render the reports page with presets.
     *
     * Route:   GET /panel/reports
     * Auth:    auth
     * Returns: HTML or JSON (presets)
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $engine = $this->c->get('reports');
        $presets = $engine->presets();
        if ($request->isJson()) { return $this->ok($presets, $ctx); }
        return $this->view('panel/reports', [
            'presets' => $presets,
            'report' => (string) $request->query('report', 'signups'),
            'csrf' => $ctx->csrf,
            'max_export_rows' => (int) $ctx->settings->get('reports.max_export_rows', 50000),
        ]);
    }

    /**
     * Run a report and return rows + meta.
     *
     * Route:   POST /panel/reports/data
     * Auth:    auth
     * Params:  report, page, per_page, sort[], filters[], columns[]
     * Returns: JSON envelope { data: { columns, rows, summary, ... } }
     */
    public function data(Request $request, MiddlewareContext $ctx): Response
    {
        $result = $this->c->get('reports')->run($this->input($request), $ctx->actor());
        return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['filtered']]);
    }

    /**
     * Export a report (returns a signed download URL).
     *
     * Route:   POST /panel/reports/export
     * Auth:    auth
     * Params:  report, filters[], sort[], columns[], format
     * Returns: JSON envelope { data: { url, filename } }
     */
    public function export(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $engine = $this->c->get('reports');
        $payload = $engine->export($input, $ctx->actor());
        $token = $this->signExport($payload['filename'], (int) $ctx->user['id']);
        $this->cacheExport($token, $payload);
        return $this->ok(['url' => '/panel/reports/download/' . $token, 'filename' => $payload['filename']], $ctx);
    }

    /**
     * Download a previously-built export via a signed token (1-hour TTL).
     *
     * Route:   GET /panel/reports/download/{token}
     * Auth:    auth
     * Returns: file download
     */
    public function download(Request $request, MiddlewareContext $ctx): Response
    {
        $token = (string) $request->attr('token');
        $path = $this->exportPath($token);
        if (!is_file($path)) {
            return Response::html('Export expired or not found', 404);
        }
        $meta = json_decode((string) file_get_contents($path . '.meta'), true) ?: [];
        $filename = (string) ($meta['filename'] ?? 'report.csv');
        return Response::download($path, $filename);
    }

    /**
     * Share a report (live view using the sharer's filters).
     *
     * Route:   POST /panel/reports/share
     * Auth:    auth
     * Params:  shared_to_user_id, report, filters[]
     * Returns: JSON envelope { data: { id } }
     */
    public function share(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $recipient = (int) ($input['shared_to_user_id'] ?? 0);
        if ($recipient <= 0) { return $this->fail('VALIDATION_FAILED', 'Recipient required', $ctx, 422, 'shared_to_user_id'); }
        // Riders may only share with managers/admins.
        if ($ctx->user['role'] === 'rider') {
            $to = $this->c->get('users')->get($recipient);
            if (!in_array($to['role'], ['admin', 'manager'], true)) {
                return $this->fail('FORBIDDEN', 'Riders may share only with managers/admins', $ctx, 403);
            }
        }
        $id = $this->c->get('db')->insert('report_shares', [
            'owner_user_id' => (int) $ctx->user['id'],
            'shared_to_user_id' => $recipient,
            'report_key' => (string) ($input['report'] ?? 'signups'),
            'filter_state_json' => json_encode($input['filters'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_demo' => 0,
            'created_at' => now_utc(),
        ]);
        $this->c->get('notifications')->create($recipient, 'report.shared', 'گزارش به اشتراک گذاشته شد', 'یک گزارش برای شما به اشتراک گذاشته شد.', '/panel/reports?report=' . ($input['report'] ?? 'signups'), 'report_share', $id);
        return $this->ok(['id' => $id], $ctx, 201);
    }

    /**
     * List reports shared with the current user.
     *
     * Route:   GET /panel/reports/shares
     * Auth:    auth
     * Returns: JSON envelope
     */
    public function shares(Request $request, MiddlewareContext $ctx): Response
    {
        $rows = $this->c->get('db')->select(
            'SELECT rs.*, (u.first_name || \' \' || u.last_name) AS owner_name FROM report_shares rs JOIN users u ON u.id = rs.owner_user_id
             WHERE rs.shared_to_user_id = :u OR rs.owner_user_id = :u2 ORDER BY rs.id DESC',
            ['u' => (int) $ctx->user['id'], 'u2' => (int) $ctx->user['id']]
        );
        return $this->ok($rows, $ctx);
    }

    /**
     * Revoke a report share (owner only).
     *
     * Route:   POST /panel/reports/shares/{id}/revoke
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function revokeShare(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('db')->delete('report_shares', 'id = :id AND owner_user_id = :u', ['id' => (int) $request->attr('id'), 'u' => (int) $ctx->user['id']]);
        return $this->ok(null, $ctx);
    }

    /**
     * Sign an export filename + owner into a short-lived token.
     *
     * @param string $filename Filename.
     * @param int    $userId   Owner id.
     * @return string Token.
     */
    private function signExport(string $filename, int $userId): string
    {
        $payload = base64_encode(json_encode(['f' => $filename, 'u' => $userId, 'e' => time() + 3600]));
        $sig = hash_hmac('sha256', $payload, (string) $this->c->get('settings')->get('app.key', 'dev'));
        return rtrim(strtr($payload . '.' . $sig, '+/', '-_'), '=');
    }

    /**
     * Persist an export payload under a token path.
     *
     * @param string $token   Token.
     * @param array  $payload Export payload.
     * @return void
     */
    private function cacheExport(string $token, array $payload): void
    {
        $dir = BASE_PATH . '/cache/exports';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = $this->exportPath($token);
        $csv = $this->c->get('reports')->toCsv($payload);
        file_put_contents($path, $csv);
        file_put_contents($path . '.meta', json_encode(['filename' => $payload['filename']]));
    }

    /**
     * Resolve the file path for an export token.
     *
     * @param string $token Token.
     * @return string
     */
    private function exportPath(string $token): string
    {
        return BASE_PATH . '/cache/exports/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.csv';
    }
}
