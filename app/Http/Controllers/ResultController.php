<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/ResultController.php
 *
 * Purpose: HTTP layer for the results workflow and standings
 *          (Blueprint §10.3, User Usage §7.17). JSON API only.
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
     * Results entry grid for a competition.
     *
     * Route:   GET /panel/competitions/{id}/results
     * Auth:    role:admin,manager
     * Returns: JSON envelope
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        return $this->ok($this->c->get('results')->grid((int) $request->attr('id'), $ctx->actor()), $ctx);
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
     * Standings (per rider, horse, or pair).
     *
     * Route:   GET /panel/standings
     * Auth:    auth
     * Returns: JSON envelope
     */
    public function printStandings(Request $request, MiddlewareContext $ctx): Response
    {
        $scope = [
            'rider_user_id' => (int) $request->query('rider_user_id', 0),
            'horse_id' => (int) $request->query('horse_id', 0),
        ];
        return $this->ok($this->c->get('results')->standings($scope), $ctx);
    }

    /**
     * Cumulative rider ranking across every competition.
     *
     * Route:   GET /panel/standings/ranking
     * Auth:    auth
     * Query:   limit (1..1000, default 100), format=csv for export
     * Returns: JSON envelope { data: { rows, total } }
     */
    public function ranking(Request $request, MiddlewareContext $ctx): Response
    {
        $limit = max(1, min(1000, (int) ($request->query('limit', 100) ?: 100)));
        $rows = $this->c->get('kpi')->riderRanking($limit);
        if (!is_array($rows)) { $rows = []; }

        if ($request->query('format') === 'csv') {
            $lines = "\xEF\xBB\xBF" . '"رتبه","سوارکار","نام کاربری","امتیاز","قهرمانی","اولی","دومی","سومی","شرکت‌ها"' . "\n";
            foreach ($rows as $i => $r) {
                $cells = [
                    (string) ($i + 1),
                    (string) ($r['rider_name'] ?? ''),
                    (string) ($r['username'] ?? ''),
                    (string) ($r['points'] ?? ''),
                    (string) ($r['wins'] ?? ''),
                    (string) ($r['firsts'] ?? ''),
                    (string) ($r['seconds'] ?? ''),
                    (string) ($r['thirds'] ?? ''),
                    (string) ($r['entries'] ?? ''),
                ];
                $lines .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', $v) . '"', $cells)) . "\n";
            }
            $filename = 'rider-ranking-' . gmdate('Ymd') . '.csv';
            $path = BASE_PATH . '/cache/' . $filename;
            @file_put_contents($path, $lines);
            return Response::download($path, $filename);
        }

        return $this->ok(['rows' => $rows, 'total' => count($rows), 'limit' => $limit], $ctx);
    }
}
