<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/HorseController.php
 *
 * Purpose:
 *   HTTP layer for horses: CRUD, status, images, shares, transfers, history,
 *   import/export, and bulk operations (Blueprint §10.3; User Usage §7.6–7.7, §9.2).
 *   JSON API only.
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
        return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]);
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
     * Show a horse with its images, shares, transfers, and history.
     *
     * Route:   GET /panel/horses/{id}
     * Auth:    auth
     * Returns: JSON envelope { data: { horse, images, shares, transfers, history } }
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $horse = $this->c->get('horses')->get((int) $request->attr('id'), $ctx->actor());
        return $this->ok([
            'horse' => $horse,
            'images' => $this->c->get('media')->horseImages((int) $horse['id']),
            'shares' => $this->c->get('horses')->sharesForHorse((int) $horse['id']),
            'transfers' => $this->c->get('horses')->transfersForHorse((int) $horse['id']),
            'history' => $this->c->get('horses')->performanceHistory((int) $horse['id']),
        ], $ctx);
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
     * Returns: JSON envelope
     */
    public function sharesInbox(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('horses')->sharesForRecipient((int) $ctx->user['id']), $ctx);
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

    /**
     * Health / vaccination records of one horse.
     *
     * Route:   GET /panel/horses/{id}/health
     * Auth:    auth (riders see only their own horses)
     * Returns: JSON envelope { data: { rows, types } }
     */
    public function health(Request $request, MiddlewareContext $ctx): Response
    {
        $horseId = (int) $request->attr('id');
        return $this->ok([
            'rows' => $this->c->get('horse_health')->listFor($horseId, $ctx->actor()),
            'types' => \App\Services\HorseHealthService::types(),
        ], $ctx);
    }

    /**
     * Add a health record.
     *
     * Route:   POST /panel/horses/{id}/health
     * Auth:    role:admin,manager + csrf
     * Body:    { record_type, title, performed_at, next_due_at, vet_name, notes, cost_irt }
     * Returns: JSON envelope { data: { id } } (201)
     */
    public function createHealth(Request $request, MiddlewareContext $ctx): Response
    {
        $id = $this->c->get('horse_health')->create((int) $request->attr('id'), $this->input($request), $ctx->actor());
        return $this->ok($id, $ctx, 201);
    }

    /**
     * Update a health record.
     *
     * Route:   PUT /panel/horses/health/{recordId}
     * Auth:    role:admin,manager + csrf
     * Returns: JSON envelope { data: { id } }
     */
    public function updateHealth(Request $request, MiddlewareContext $ctx): Response
    {
        $id = $this->c->get('horse_health')->update((int) $request->attr('recordId'), $this->input($request), $ctx->actor());
        return $this->ok($id, $ctx);
    }

    /**
     * Delete a health record.
     *
     * Route:   DELETE /panel/horses/health/{recordId}
     * Auth:    role:admin,manager + csrf
     * Returns: JSON envelope { data: null }
     */
    public function deleteHealth(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('horse_health')->delete((int) $request->attr('recordId'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Records due (next dose / check) within the next N days.
     *
     * Route:   GET /panel/horses/health/due?days=30
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { rows, days } }
     */
    public function healthDue(Request $request, MiddlewareContext $ctx): Response
    {
        $days = (int) ($request->query('days', 30) ?: 30);
        return $this->ok([
            'rows' => $this->c->get('horse_health')->dueWithin($days),
            'days' => $days,
        ], $ctx);
    }
}
