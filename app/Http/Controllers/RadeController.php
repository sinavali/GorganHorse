<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/RadeController.php
 *
 * Purpose: HTTP layer for rades (list, create, show, update, delete, bulk).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: RadeController
 * Purpose: Handle rade endpoints (Blueprint §10.3, User Usage §7.8–7.9).
 */
final class RadeController extends BaseController
{
    /**
     * List rades.
     *
     * Route:   GET /panel/rades
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $filters = ['active' => (string) $request->query('active', 'all'), 'search' => (string) $request->query('search', '')];
        $rows = $this->c->get('rades')->list($filters);
        if ($request->isJson()) { return $this->ok($rows, $ctx, 200, ['total' => count($rows), 'filtered' => count($rows)]); }
        return $this->view('panel/rades', ['rows' => $rows, 'filters' => $filters, 'csrf' => $ctx->csrf]);
    }

    /**
     * Create a rade.
     *
     * Route:   POST /panel/rades
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id, uuid } }
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('rades')->create($this->input($request), $ctx->actor()), $ctx, 201);
    }

    /**
     * Show a rade.
     *
     * Route:   GET /panel/rades/{id}
     * Auth:    auth
     * Returns: HTML or JSON
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $rade = $this->c->get('rades')->get((int) $request->attr('id'));
        if ($request->isJson()) { return $this->ok($rade, $ctx); }
        return $this->view('panel/rade-edit', ['record' => $rade, 'csrf' => $ctx->csrf]);
    }

    /**
     * Update a rade.
     *
     * Route:   PUT /panel/rades/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('rades')->update((int) $request->attr('id'), $this->input($request), $ctx->actor()), $ctx);
    }

    /**
     * Delete a rade.
     *
     * Route:   DELETE /panel/rades/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('rades')->delete((int) $request->attr('id'), $ctx->actor());
        return $this->ok(null, $ctx);
    }

    /**
     * Bulk activate/deactivate.
     *
     * Route:   POST /panel/rades/bulk
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { processed } }
     */
    public function bulk(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $ids = array_map('intval', $input['ids'] ?? []);
        $active = ($input['action'] ?? '') === 'activate';
        return $this->ok(['processed' => $this->c->get('rades')->bulkSetActive($ids, $active, $ctx->actor())], $ctx);
    }
}
