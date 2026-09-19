<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/HorseController.php
 *
 * Purpose:
 *   HTTP layer for horses: CRUD, status, images, shares, transfers, history,
 *   import/export, print, and bulk operations (Blueprint §10.3; User Usage §7.6–7.7, §9.2).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: HorseController
 * Purpose: Handle horse endpoints.
 */
final class HorseController extends BaseController
{
    /**
     * List horses (role-scoped).
     *
     * Route:   GET /panel/horses
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'gender' => (string) $request->query('gender', ''),
            'race' => (string) $request->query('race', ''),
            'color' => (string) $request->query('color', ''),
            'microchip' => (string) $request->query('microchip', ''),
            'search' => (string) $request->query('search', ''),
            'owner_user_id' => (int) $request->query('owner_user_id', 0),
        ];
        $result = $this->c->get('horses')->list($filters, $ctx->actor(), $this->page($request), $this->perPage($request));
        if ($request->isJson()) {
            return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]);
        }
        return $this->view('panel/horses', ['rows' => $result['rows'], 'total' => $result['total'], 'filters' => $filters, 'csrf' => $ctx->csrf, 'role' => $ctx->user['role']]);
    }

    /**
     * Create a horse.
     *
     * Route:   POST /panel/horses
     * Auth:    auth (rider/manager/admin)
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('horses')->create($this->input($request), $ctx->actor()), $ctx, 201);
    }

    /**
     * Show a horse.
     *
     * Route:   GET /panel/horses/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $horse = $this->c->get('horses')->get((int) $request->attr('id'), $ctx->actor());
        if ($request->isJson()) { return $this->ok($horse, $ctx); }
        $images = $this->c->get('media')->horseImages((int) $horse['id']);
        $shares = $this->c->get('horses')->sharesForHorse((int) $horse['id']);
        $transfers = $this->c->get('horses')->transfersForHorse((int) $horse['id']);
        $history = $this->c->get('horses')->performanceHistory((int) $horse['id']);
        return $this->view('panel/horse-edit', [
            'record' => $horse, 'images' => $images, 'shares' => $shares, 'transfers' => $transfers,
            'history' => $history, 'csrf' => $ctx->csrf, 'role' => $ctx->user['role'],
        ]);
    }

    /**
     * Update a horse.
     *
     * Route:   PUT /panel/horses/{id}
     * Auth:    auth
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('horses')->update((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Soft-delete a horse.
     *
     * Route:   DELETE /panel/horses/{id}
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->setStatus((int) $request->attr('id'), 'soft_deleted', $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Mark a horse as sold to a non-rider.
     *
     * Route:   POST /panel/horses/{id}/sold-to-non-rider
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function soldToNonRider(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->setStatus((int) $request->attr('id'), 'sold_to_non_rider', $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Upload a horse image.
     *
     * Route:   POST /panel/horses/{id}/images
     * Auth:    auth
     * Returns: JSON envelope { data: { media_id } }
     */
    public function addImage(Request $request, MiddlewareContext $ctx): Response
    {
        $file = $request->files('file');
        if (!is_array($file)) { return $this->fail('VALIDATION_FAILED', 'No file uploaded', $ctx, 422, 'file'); }
        return $this->ok($this->c->get('horses')->addImage((int) $request->attr('id'), $file, $ctx->actor()), $ctx, 201);
    }

    /**
     * Remove a horse image.
     *
     * Route:   DELETE /panel/horses/{id}/images/{media_id}
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function removeImage(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->removeImage((int) $request->attr('id'), (int) $request->attr('media_id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Share a horse to a rider by share code.
     *
     * Route:   POST /panel/horses/{id}/share
     * Auth:    auth
     * Returns: JSON envelope { data: { id, recipient_user_id } }
     */
    public function share(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        return $this->ok($this->c->get('horses')->share((int) $request->attr('id'), (string) ($input['share_code'] ?? ''), $ctx->actor()), $ctx, 201);
    }

    /**
     * Revoke a horse share.
     *
     * Route:   DELETE /panel/horses/{id}/share/{share_id}
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function revokeShare(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->revokeShare((int) $request->attr('id'), (int) $request->attr('share_id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Initiate a transfer.
     *
     * Route:   POST /panel/horses/{id}/transfer/initiate
     * Auth:    auth
     * Returns: JSON envelope { data: { transfer_id, code, expires_at } }
     */
    public function initiateTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('horses')->initiateTransfer((int) $request->attr('id'), $ctx->actor()), $ctx, 201);
    }

    /**
     * Lock a horse for transfer (alias of initiate without code display).
     *
     * Route:   POST /panel/horses/{id}/transfer/lock
     * Auth:    auth
     * Returns: JSON envelope { data: {...} }
     */
    public function lockTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('horses')->initiateTransfer((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Unlock a horse (cancel transfer).
     *
     * Route:   POST /panel/horses/{id}/transfer/unlock
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function unlockTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->cancelTransfer((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Claim a transfer with a code.
     *
     * Route:   POST /panel/horses/{id}/transfer/claim
     * Auth:    auth
     * Returns: JSON envelope { data: {...} }
     */
    public function claimTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        return $this->ok($this->c->get('horses')->claimTransfer((int) $request->attr('id'), (string) ($input['code'] ?? ''), $ctx->actor()), $ctx);
    }

    /**
     * Accept a transfer (owner).
     *
     * Route:   POST /panel/horses/{id}/transfer/accept
     * Auth:    auth
     * Returns: JSON envelope { data: {...} }
     */
    public function acceptTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('horses')->acceptTransfer((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Reject a transfer (owner).
     *
     * Route:   POST /panel/horses/{id}/transfer/reject
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function rejectTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->rejectTransfer((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Cancel a transfer.
     *
     * Route:   POST /panel/horses/{id}/transfer/cancel
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function cancelTransfer(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->cancelTransfer((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Horse history (signups + results).
     *
     * Route:   GET /panel/horses/{id}/history
     * Auth:    auth
     * Returns: JSON envelope
     */
    public function history(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horses')->get((int) $request->attr('id'), $ctx->actor());
        return $this->ok($this->c->get('horses')->performanceHistory((int) $request->attr('id')), $ctx);
    }

    /**
     * Render a horse print view.
     *
     * Route:   GET /panel/horses/{id}/print
     * Auth:    auth
     * Returns: HTML print page
     */
    public function print(Request $request, MiddlewareContext $ctx): Response
    {
        $horse = $this->c->get('horses')->get((int) $request->attr('id'), $ctx->actor());
        return $this->view('print/horse', ['record' => $horse, 'title' => $horse['name']], 'print');
    }

    /**
     * Import horses from CSV.
     *
     * Route:   POST /panel/horses/import
     * Auth:    auth
     * Returns: JSON envelope { data: { imported, errors } }
     */
    public function import(Request $request, MiddlewareContext $ctx): Response
    {
        $file = $request->files('file');
        if (!is_array($file)) { return $this->fail('IMPORT_FAILED', 'No file uploaded', $ctx, 422, 'file'); }
        return $this->ok($this->c->get('horses')->importCsv($file, $ctx->actor()), $ctx);
    }

    /**
     * Download the CSV import template.
     *
     * Route:   GET /panel/horses/export-template
     * Auth:    auth
     * Returns: CSV download
     */
    public function exportTemplate(Request $request, MiddlewareContext $ctx): Response
    {
        $t = $this->c->get('horses')->exportTemplate();
        $path = BASE_PATH . '/cache/' . $t['filename'];
        file_put_contents($path, $t['content']);
        return Response::download($path, $t['filename']);
    }

    /**
     * Export horses as CSV.
     *
     * Route:   POST /panel/horses/export
     * Auth:    auth
     * Returns: CSV download
     */
    public function export(Request $request, MiddlewareContext $ctx): Response
    {
        $c = $this->c->get('horses')->exportCsv($this->input($request), $ctx->actor());
        $path = BASE_PATH . '/cache/' . $c['filename'];
        file_put_contents($path, $c['content']);
        return Response::download($path, $c['filename']);
    }

    /**
     * Bulk status change (soft delete/restore).
     *
     * Route:   POST /panel/horses/bulk
     * Auth:    auth
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulk(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $action = (string) ($input['action'] ?? '');
        $status = $action === 'restore' ? 'active' : 'soft_deleted';
        $processed = 0;
        foreach ($ids as $id) {
            try { $this->c->get('horses')->setStatus($id, $status, $ctx->actor()); $processed++; } catch (\Throwable) {}
        }
        return $this->ok(['processed' => $processed], $ctx);
    }

    /**
     * List horse shares received by the current rider.
     *
     * Route:   GET /panel/horse-shares
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function sharesInbox(Request $request, MiddlewareContext $ctx): Response
    {
        $rows = $this->c->get('horses')->sharesForRecipient((int) $ctx->user['id']);
        if ($request->isJson()) { return $this->ok($rows, $ctx); }
        return $this->view('panel/horse-shares', ['rows' => $rows, 'csrf' => $ctx->csrf]);
    }

    /**
     * Respond to a received share.
     *
     * Route:   POST /panel/horse-shares/{id}/accept|reject
     * Auth:    auth
     * Returns: JSON envelope { data: null }
     */
    public function respondShare(Request $request, MiddlewareContext $ctx): Response
    {
        $action = str_ends_with($request->path(), 'accept') ? 'accept' : 'reject';
        $this->c->get('horses')->respondToShare((int) $request->attr('id'), $action, $ctx->actor());
        return $this->ok(null, $ctx);
    }
}
