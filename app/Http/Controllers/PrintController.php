<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/PrintController.php
 *
 * Purpose:
 *   Generic print dispatcher for `GET /panel/print/{entity}/{id}` (Blueprint §17).
 *   Individual entity controllers also expose dedicated print routes; this one
 *   provides a single fallback entry point.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: PrintController
 * Purpose: Render A4 print views for any printable entity.
 */
final class PrintController extends BaseController
{
    /**
     * Render a print view for an entity.
     *
     * Route:   GET /panel/print/{entity}/{id}
     * Auth:    auth
     * Params:  entity (competition|horse|rider|club|payment), id (route)
     * Returns: HTML print page
     * Throws:  404 for unknown entity
     *
     * @example
     *   GET /panel/print/horse/42 → A4 horse profile sheet
     */
    public function show(Request $request, MiddlewareContext $ctx): Response
    {
        $entity = (string) $request->attr('entity');
        $id = (int) $request->attr('id');
        $actor = $ctx->actor();

        return match ($entity) {
            'competition' => $this->printCompetition($id, $actor),
            'horse' => $this->printHorse($id, $actor),
            'rider' => $this->printRider($id, $ctx),
            'club' => $this->printClub($id, $actor),
            'payment' => $this->printPayment($id, $actor),
            default => Response::html('Unknown entity', 404),
        };
    }

    private function printCompetition(int $id, array $actor): Response
    {
        $c = $this->c->get('competitions')->get($id, $actor);
        return $this->view('print/competition', ['record' => $c, 'rades' => $c['rades'] ?? [], 'title' => $c['title']], 'print');
    }

    private function printHorse(int $id, array $actor): Response
    {
        $h = $this->c->get('horses')->get($id, $actor);
        return $this->view('print/horse', ['record' => $h, 'title' => $h['name']], 'print');
    }

    private function printRider(int $id, MiddlewareContext $ctx): Response
    {
        if (!in_array($ctx->user['role'], ['admin', 'manager'], true) && (int) $ctx->user['id'] !== $id) {
            return Response::html('Forbidden', 403);
        }
        $r = $this->c->get('users')->get($id);
        return $this->view('print/rider', ['record' => $r, 'title' => $r['first_name'] . ' ' . $r['last_name']], 'print');
    }

    private function printClub(int $id, array $actor): Response
    {
        $club = $this->c->get('clubs')->get($id, $actor);
        return $this->view('print/club', ['record' => $club, 'title' => $club['name']], 'print');
    }

    private function printPayment(int $id, array $actor): Response
    {
        $p = $this->c->get('payments')->getTemplate($id);
        return $this->view('print/payment', ['record' => $p, 'title' => $p['name']], 'print');
    }
}
