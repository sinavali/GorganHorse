<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/LookupController.php
 *
 * Purpose:
 *   HTTP layer for the DB-managed horse lookup lists (breeds/races, colours,
 *   genders). Managers and Admins can add, rename, reorder, deactivate and
 *   delete entries; every authenticated user may read the lists so the horse
 *   forms can offer searchable, pre-filled selects (User Usage §7.6).
 *
 *   Values are controlled vocabulary stored in horse_races / horse_colors /
 *   horse_genders and referenced by name from horses.{gender,race,color}.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;
use App\Services\LookupService;

/**
 * Class: LookupController
 * Purpose: Read and maintain the horse lookup lists.
 */
final class LookupController extends BaseController
{
    /**
     * List the active entries of one lookup type.
     *
     * Route:   GET /panel/lookups/{type}
     * Auth:    any authenticated user
     * Returns: JSON envelope { data: [{ id, name, sort_order, is_active }] }
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $service = $this->c->get('lookups');
        return $this->ok($service->list($this->type($request)), $ctx);
    }

    /**
     * List every lookup type in one call (used to prime the form selects).
     *
     * Route:   GET /panel/lookups
     * Auth:    any authenticated user
     * Returns: JSON envelope { data: { genders: [], colors: [], races: [] } }
     */
    public function all(Request $request, MiddlewareContext $ctx): Response
    {
        $service = $this->c->get('lookups');
        return $this->ok([
            'genders' => $service->list('genders'),
            'colors' => $service->list('colors'),
            'races' => $service->list('races'),
        ], $ctx);
    }

    /**
     * Create an entry.
     *
     * Route:   POST /panel/lookups/{type}
     * Auth:    role:admin,manager
     * Body:    { name, sort_order?, is_active? }
     * Returns: JSON envelope { data: { id } } (201)
     */
    public function store(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $id = $this->c->get('lookups')->create($this->type($request), (string) ($input['name'] ?? ''), $ctx->user);
        return $this->ok(['id' => $id], $ctx, 201);
    }

    /**
     * Update an entry (rename / reorder / activate).
     *
     * Route:   PUT /panel/lookups/{type}/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { id } }
     */
    public function update(Request $request, MiddlewareContext $ctx): Response
    {
        $id = (int) $request->attr('id', 0);
        $this->c->get('lookups')->update($this->type($request), $id, $this->input($request), $ctx->user);
        return $this->ok(['id' => $id], $ctx);
    }

    /**
     * Delete an entry.
     *
     * Route:   DELETE /panel/lookups/{type}/{id}
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: null }
     */
    public function destroy(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('lookups')->delete($this->type($request), (int) $request->attr('id', 0), $ctx->user);
        return $this->ok(null, $ctx);
    }

    /**
     * Read the {type} route parameter.
     *
     * The Kernel invokes every controller as method($request, $ctx) and puts
     * matched route parameters on the request as attributes, so controllers
     * must never declare extra positional arguments.
     *
     * @param Request $request Request.
     * @return string
     */
    private function type(Request $request): string
    {
        return (string) $request->attr('type', '');
    }
}
