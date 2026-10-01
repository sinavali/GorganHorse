<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/ClubController.php
 *
 * Purpose:
 *   HTTP layer for clubs: list, create, view, update, delete, and bans
 *   (Blueprint §10.3, User Usage §7.4–7.5, §10). JSON API only.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: ClubController
 * Purpose: Handle club endpoints.
 */
final class ClubController extends BaseController
{
    /**
     * List clubs.
     *
     * Route:   GET /panel/clubs
     * Auth:    auth
     * Params:  status?, city?, search?, page?
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'city' => (string) $request->query('city', ''),
            'search' => (string) $request->query('search', ''),
        ];
        $result = $this->c->get('clubs')->list($filters, $ctx->actor(), $this->page($request), $this->perPage($request));
        return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]);
    }

    /**
     * Create a club.
     *
     * Route:   POST /panel/clubs
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('clubs')->create($this->input($request), $ctx->actor()), $ctx, 201);
    }

    /**
     * Show a club.
     *
     * Route:   GET /panel/clubs/{id}
     * Auth:    auth
     * Returns: JSON envelope
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('clubs')->get((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Update a club.
     *
     * Route:   PUT /panel/clubs/{id}
     * Auth:    auth (admin/manager/club-self)
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('clubs')->update((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Delete a club (Admin only).
     *
     * Route:   DELETE /panel/clubs/{id}
     * Auth:    role:admin
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('clubs')->delete((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * List a club's active bans.
     *
     * Route:   GET /panel/clubs/{id}/bans
     * Auth:    auth
     * Returns: JSON envelope { data: { rows } }
     */
    public function bans(Request $request, MiddlewareContext $ctx): Response
    {
        if (!in_array($ctx->user['role'], ['admin', 'manager', 'club'], true)) {
            return $this->fail('FORBIDDEN', 'Forbidden', $ctx, 403);
        }
        $club = $this->c->get('clubs')->get((int) $request->attr('id'), $ctx->actor());
        $type = (string) $request->query('type', '');
        $rows = $this->c->get('clubs')->bans((int) $club['id'], $ctx->actor(), $type !== '' ? $type : null);
        return $this->ok(['rows' => $rows], $ctx);
    }

    /**
     * Add a club ban.
     *
     * Route:   POST /panel/clubs/{id}/bans
     * Auth:    role:admin,manager (or club-self)
     * Params:  target_type, target_id, reason
     * Returns: JSON envelope { data: { id } }
     */
    public function addBan(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        return $this->ok($this->c->get('clubs')->addBan((int) $request->attr('id'), (string) ($input['target_type'] ?? 'rider'), (int) ($input['target_id'] ?? 0), (string) ($input['reason'] ?? ''), $ctx->actor()), $ctx, 201);
    }

    /**
     * Remove a club ban.
     *
     * Route:   DELETE /panel/clubs/{id}/bans/{ban_id}
     * Auth:    role:admin,manager (or club-self)
     * Returns: JSON envelope { data: null }
     */
    public function removeBan(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('clubs')->removeBan((int) $request->attr('id'), (int) $request->attr('ban_id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

}
