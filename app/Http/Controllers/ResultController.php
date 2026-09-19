<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/ResultController.php
 *
 * Purpose: HTTP layer for the results workflow and standings (Blueprint §10.3, User Usage §7.17).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: ResultController
 * Purpose: Handle results entry, state transitions, and print.
 */
final class ResultController extends BaseController
{
    /**
     * Render the results entry grid.
     *
     * Route:   GET /panel/competitions/{id}/results
     * Auth:    role:admin,manager
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $grid = $this->c->get('results')->grid((int) $request->attr('id'), $ctx->actor());
        if ($request->isJson()) { return $this->ok($grid, $ctx); }
        return $this->view('panel/competition-results', ['grid' => $grid, 'csrf' => $ctx->csrf]);
    }

    /**
     * Save draft results.
     *
     * Route:   POST /panel/competitions/{id}/results
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { saved, status } }
     */
    public function save(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('results')->saveDraft((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Confirm results.
     *
     * Route:   POST /panel/competitions/{id}/results/confirm
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { status } }
     */
    public function confirm(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('results')->confirm((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Publish results (notifies riders).
     *
     * Route:   POST /panel/competitions/{id}/results/publish
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { status, notified } }
     */
    public function publish(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('results')->publish((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Reopen published results (Admin only).
     *
     * Route:   POST /panel/competitions/{id}/results/reopen
     * Auth:    role:admin
     * Returns: JSON envelope { data: { status } }
     */
    public function reopen(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('results')->reopen((int) $request->attr('id'), $ctx->actor()), $ctx);
    }

    /**
     * Print standings (per rider, horse, or pair).
     *
     * Route:   GET /panel/standings/print
     * Auth:    auth
     * Returns: HTML print page
     */
    public function printStandings(Request $request, MiddlewareContext $ctx): Response
    {
        $scope = [
            'rider_user_id' => (int) $request->query('rider_user_id', 0),
            'horse_id' => (int) $request->query('horse_id', 0),
        ];
        $rows = $this->c->get('results')->standings($scope);
        return $this->view('print/standings', ['rows' => $rows, 'title' => 'رده‌بندی'], 'print');
    }
}
