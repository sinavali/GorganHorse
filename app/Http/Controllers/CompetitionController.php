<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/CompetitionController.php
 *
 * Purpose:
 *   HTTP layer for competitions: CRUD, pause/resume, cancel, clone, the
 *   competition-rades tab, barrage toggling, print views, and the rider-facing
 *   competition browse + signup flow (Blueprint §10.3; User Usage §7.12–7.14, §9.4–9.6).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: CompetitionController
 * Purpose: Handle competition and competition-rade endpoints.
 */
final class CompetitionController extends BaseController
{
    /**
     * List competitions.
     *
     * Route:   GET /panel/competitions
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'venue_club_id' => (int) $request->query('venue_club_id', 0),
            'search' => (string) $request->query('search', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
        $result = $this->c->get('competitions')->list($filters, $ctx->actor(), $this->page($request), $this->perPage($request));
        if ($request->isJson()) { return $this->ok($result, $ctx, 200, ['total' => $result['total'], 'filtered' => $result['total']]); }
        return $this->view('panel/competitions', ['rows' => $result['rows'], 'total' => $result['total'], 'filters' => $filters, 'csrf' => $ctx->csrf]);
    }

    /**
     * Create a competition.
     *
     * Route:   POST /panel/competitions
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('competitions')->create($this->input($request), $ctx->actor()), $ctx, 201);
    }

    /**
     * Show a competition with its rades.
     *
     * Route:   GET /panel/competitions/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $competition = $this->c->get('competitions')->get((int) $request->attr('id'), $ctx->actor());
        if ($request->isJson()) { return $this->ok($competition, $ctx); }
        return $this->view('panel/competition-edit', [
            'record' => $competition,
            'rades' => $competition['rades'] ?? [],
            'all_rades' => $this->c->get('rades')->list(['active' => 1]),
            'payments' => $this->c->get('payments')->listTemplates(['active' => 1]),
            'csrf' => $ctx->csrf,
        ]);
    }

    /**
     * Update a competition.
     *
     * Route:   PUT /panel/competitions/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('competitions')->update((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Delete a competition.
     *
     * Route:   DELETE /panel/competitions/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('competitions')->delete((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Pause registration.
     *
     * Route:   POST /panel/competitions/{id}/pause
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function pause(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('competitions')->pause((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Resume registration.
     *
     * Route:   POST /panel/competitions/{id}/resume
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function resume(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('competitions')->resume((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Cancel a competition (marks paid orders pending_refund).
     *
     * Route:   POST /panel/competitions/{id}/cancel
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { competition_id, pending_refund_orders } }
     */
    public function cancel(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('competitions')->cancel((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Clone a competition.
     *
     * Route:   POST /panel/competitions/{id}/clone
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function clone(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('competitions')->clone((int) $request->attr('id'), $ctx->actor()), $ctx, 201);
    }

    /**
     * Add a rade to a competition.
     *
     * Route:   POST /panel/competitions/{id}/rades
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function addRade(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('competitions')->addRade((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx, 201);
    }

    /**
     * Update a competition-rade.
     *
     * Route:   PUT /panel/competitions/{id}/rades/{comp_rade_id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id } }
     */
    public function updateRade(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('competitions')->updateRade((int) $request->attr('id'), (int) $request->attr('comp_rade_id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Remove a competition-rade.
     *
     * Route:   DELETE /panel/competitions/{id}/rades/{comp_rade_id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function removeRade(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('competitions')->removeRade((int) $request->attr('id'), (int) $request->attr('comp_rade_id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Toggle barrage for a competition-rade.
     *
     * Route:   POST /panel/competitions/{id}/rades/{comp_rade_id}/barrage
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function barrage(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $this->c->get('competitions')->setBarrage((int) $request->attr('id'), (int) $request->attr('comp_rade_id'), (bool) ($input['had_barrage'] ?? false), (string) ($input['barrage_notes'] ?? ''), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Print a competition paper.
     *
     * Route:   GET /panel/competitions/{id}/print
     * Auth:    auth
     * Returns: HTML print page
     */
    public function print(Request $request, MiddlewareContext $ctx): Response
    {
        $competition = $this->c->get('competitions')->get((int) $request->attr('id'), $ctx->actor());
        return $this->view('print/competition', ['record' => $competition, 'rades' => $competition['rades'] ?? [], 'title' => $competition['title']], 'print');
    }

    /**
     * Print a competition signup sheet.
     *
     * Route:   GET /panel/competitions/{id}/signup-sheet/print
     * Auth:    auth
     * Returns: HTML print page
     */
    public function printSignupSheet(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) $request->attr('id');
        $competition = $this->c->get('competitions')->get($id, $ctx->actor());
        $signups = $this->c->get('signups')->list(['competition_id' => $id], ['id' => 0, 'role' => 'admin'], 1, 5000)['rows'];
        return $this->view('print/signup-sheet', ['competition' => $competition, 'rows' => $signups, 'title' => 'لیست ثبت‌نام - ' . $competition['title']], 'print');
    }

    /**
     * Bulk publish/cancel.
     *
     * Route:   POST /panel/competitions/bulk
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulk(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $action = (string) ($input['action'] ?? '');
        $processed = 0;
        $svc = $this->c->get('competitions');
        foreach ($ids as $id) {
            try {
                if ($action === 'publish') { $svc->update($id, ['status' => 'open'], $ctx->actor()); }
                elseif ($action === 'cancel') { $svc->cancel($id, $ctx->actor()); }
                $processed++;
            } catch (\Throwable) {}
        }
        return $this->ok(['processed' => $processed], $ctx);
    }

    // ---- Rider-facing ----

    /**
     * Rider competition browser.
     *
     * Route:   GET /panel/rider/competitions
     * Auth:    auth (any role; typically rider)
     * Returns: HTML or JSON
     */
    public function riderIndex(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = ['search' => (string) $request->query('search', '')];
        $result = $this->c->get('competitions')->list($filters, ['id' => 0, 'role' => 'guest'], $this->page($request), $this->perPage($request));
        if ($request->isJson()) { return $this->ok($result, $ctx, 200, ['total' => $result['total']]); }
        return $this->view('panel/rider-competitions', ['rows' => $result['rows'], 'csrf' => $ctx->csrf, 'pending' => $ctx->pendingVerification]);
    }

    /**
     * Rider competition detail with rades.
     *
     * Route:   GET /panel/rider/competitions/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function riderShow(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) $request->attr('id');
        $competition = $this->c->get('competitions')->get($id, ['id' => 0, 'role' => 'guest']);
        if ($request->isJson()) { return $this->ok($competition, $ctx); }
        $horses = $this->c->get('horses')->list([], $ctx->actor(), 1, 250)['rows'];
        $clubs = $this->c->get('clubs')->list(['status' => 'active'], $ctx->actor(), 1, 250)['rows'];
        return $this->view('panel/rider-signup', [
            'competition' => $competition,
            'rades' => $competition['rades'] ?? [],
            'horses' => $horses,
            'clubs' => $clubs,
            'csrf' => $ctx->csrf,
            'pending' => $ctx->pendingVerification,
        ]);
    }

    /**
     * Create a rider signup and start the payment flow.
     *
     * Route:   POST /panel/rider/competitions/{id}/signup
     * Auth:    auth
     * Params:  competition_rade_id, horse_id, affiliation_club_id
     * Returns: JSON envelope { data: { signup_id, redirect?, amount_irt } }
     */
    public function riderSignup(Request $request, MiddlewareContext $ctx): Response
    {
        if ($ctx->pendingVerification) {
            return $this->fail('USER_NOT_VERIFIED', 'برای ثبت‌نام، حساب شما باید توسط مدیر تایید شود.', $ctx, 403);
        }
        $input = $this->input($request);
        $result = $this->c->get('signups')->create(
            (int) ($input['competition_rade_id'] ?? 0),
            (int) ($input['horse_id'] ?? 0),
            (int) ($input['affiliation_club_id'] ?? 0),
            $ctx->actor()
        );
        if ($result['requires_payment']) {
            $callback = $request->baseUrl() . '/payment/callback';
            $gateway = $this->c->get('payments')->requestGateway((int) $result['order_id'], $callback);
            return $this->ok([
                'signup_id' => $result['signup_id'],
                'amount_irt' => $result['amount_irt'],
                'redirect' => $gateway['gateway_url'],
            ], $ctx, 201);
        }
        return $this->ok([
            'signup_id' => $result['signup_id'],
            'amount_irt' => 0,
            'redirect' => '/panel/rider/signups',
        ], $ctx, 201);
    }
}
