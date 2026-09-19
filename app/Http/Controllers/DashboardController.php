<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/DashboardController.php
 *
 * Purpose:
 *   Role-aware dashboard: renders the panel root and returns KPI data for the
 *   Admin/Manager/Rider/Club dashboards (Blueprint §13.3, User Usage §7.1, §8, §9.1, §10.1).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: DashboardController
 * Purpose: Serve dashboards per role.
 */
final class DashboardController extends BaseController
{
    /**
     * Render the dashboard matching the current role.
     *
     * Route:   GET /panel
     * Auth:    auth (any role)
     * Returns: HTML dashboard
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $kpi = $this->c->get('kpi');
        $role = (string) $ctx->user['role'];
        $data = match ($role) {
            'admin', 'manager' => $kpi->staffDashboard($ctx->user),
            'rider' => $kpi->riderDashboard((int) $ctx->user['id']),
            'club' => $kpi->clubDashboard((int) $ctx->user['id']),
            default => [],
        };
        return $this->view('panel/dashboard', [
            'role' => $role,
            'kpi' => $data,
            'csrf' => $ctx->csrf,
            'user' => $ctx->user,
        ]);
    }

    /**
     * Return dashboard KPI data as JSON.
     *
     * Route:   GET /panel/dashboard/data
     * Auth:    auth (any role)
     * Returns: JSON envelope { data: {...} }
     */
    public function data(Request $request, MiddlewareContext $ctx): Response
    {
        $kpi = $this->c->get('kpi');
        $role = (string) $ctx->user['role'];
        $data = match ($role) {
            'admin', 'manager' => $kpi->staffDashboard($ctx->user),
            'rider' => $kpi->riderDashboard((int) $ctx->user['id']),
            'club' => $kpi->clubDashboard((int) $ctx->user['id']),
            default => [],
        };
        return $this->ok($data, $ctx);
    }
}
