<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/SignupController.php
 *
 * Purpose:
 *   HTTP layer for signups: staff list/view/confirm/reject/position/bulk, and
 *   the rider's own signup list (Blueprint §10.3; User Usage §7.15, §9.7).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: SignupController
 * Purpose: Handle signup endpoints.
 */
final class SignupController extends BaseController
{
    /**
     * List signups (role-scoped).
     *
     * Route:   GET /panel/signups
     * Auth:    role:admin,manager
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = [
            'competition_id' => (int) $request->query('competition_id', 0),
            'rade_id' => (int) $request->query('rade_id', 0),
            'rider_user_id' => (int) $request->query('rider_user_id', 0),
            'horse_id' => (int) $request->query('horse_id', 0),
            'club_id' => (int) $request->query('club_id', 0),
            'status' => (string) $request->query('status', ''),
            'search' => (string) $request->query('search', ''),
        ];
        $result = $this->c->get('signups')->list($filters, $ctx->actor(), $this->page($request), $this->perPage($request));
        if ($request->isJson()) { return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]); }
        return $this->view('panel/signups', ['rows' => $result['rows'], 'total' => $result['total'], 'filters' => $filters, 'csrf' => $ctx->csrf]);
    }

    /**
     * Show a signup.
     *
     * Route:   GET /panel/signups/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('signups')->get((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Confirm a signup.
     *
     * Route:   POST /panel/signups/{id}/confirm
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { signup_id, confirmed_at } }
     */
    public function confirm(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('signups')->confirm((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Reject a signup.
     *
     * Route:   POST /panel/signups/{id}/reject
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function reject(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $this->c->get('signups')->reject((int) $request->attr('id'), (string) ($input['reason'] ?? ''), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Set a signup's position/winner.
     *
     * Route:   POST /panel/signups/{id}/position
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function position(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $this->c->get('signups')->setPosition((int) $request->attr('id'), isset($input['position']) && $input['position'] !== '' ? (int) $input['position'] : null, (bool) ($input['is_winner'] ?? false), (string) ($input['result_notes'] ?? ''), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Bulk confirm/reject.
     *
     * Route:   POST /panel/signups/bulk
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulk(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $action = (string) ($input['action'] ?? '');
        return $this->ok(['processed' => $this->c->get('signups')->bulk($ids, $action, $ctx->actor())], $ctx);
    }

    /**
     * Rider's own signups.
     *
     * Route:   GET /panel/rider/signups
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function riderIndex(Request $request, MiddlewareContext $ctx): Response
    {
        $result = $this->c->get('signups')->list([], $ctx->actor(), $this->page($request), $this->perPage($request));
        if ($request->isJson()) { return $this->ok($result, $ctx, 200, ['total' => $result['total']]); }
        return $this->view('panel/rider-signups', ['rows' => $result['rows'], 'total' => $result['total'], 'csrf' => $ctx->csrf]);
    }

    /**
     * Rider's signup detail.
     *
     * Route:   GET /panel/rider/signups/{id}
     * Auth:    auth
     * Returns: JSON envelope
     */
    public function riderShow(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('signups')->get((int) $request->attr('id'), $ctx->actor()), $ctx);
    }
}
