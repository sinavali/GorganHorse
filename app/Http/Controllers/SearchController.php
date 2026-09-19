<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/SearchController.php
 *
 * Purpose: Global search across competitions, riders, horses, clubs, signups.
 *          (User Usage §5.3, Blueprint §10).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: SearchController
 * Purpose: Unified search across all entity types.
 */
final class SearchController extends BaseController
{
    /**
     * Global search endpoint.
     *
     * Route:   GET /api/search
     * Auth:    auth
     * Returns: JSON { competitions: [...], riders: [...], horses: [...], clubs: [...], signups: [...] }
     */
    public function search(Request $request, MiddlewareContext $ctx): Response
    {
        $q = trim((string) ($request->query('q', '')));
        if ($q === '') {
            return $this->ok([
                'competitions' => [], 'riders' => [], 'horses' => [],
                'clubs' => [], 'signups' => [],
            ], $ctx);
        }
        $db = $this->c->get('db');
        $like = '%' . $q . '%';

        $competitions = $db->select(
            'SELECT id, title, status, city FROM competitions WHERE title LIKE :q OR city LIKE :q OR status LIKE :q ORDER BY id DESC LIMIT 10',
            ['q' => $like]
        );
        $competitions = is_array($competitions) ? $competitions : [];

        $riders = $db->select(
            'SELECT id, role, username, phone, first_name, last_name FROM users WHERE first_name LIKE :q OR last_name LIKE :q OR username LIKE :q OR phone LIKE :q ORDER BY id DESC LIMIT 10',
            ['q' => $like]
        );
        $riders = is_array($riders) ? $riders : [];

        $horses = $db->select(
            'SELECT h.id, h.name, h.microchip_number, h.gender, u.first_name AS owner_name FROM horses h LEFT JOIN users u ON h.owner_user_id = u.id WHERE h.name LIKE :q OR h.microchip_number LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q ORDER BY h.id DESC LIMIT 10',
            ['q' => $like]
        );
        $horses = is_array($horses) ? $horses : [];

        $clubs = $db->select(
            'SELECT c.id, c.name, c.city, c.phone FROM clubs c WHERE c.name LIKE :q OR c.city LIKE :q OR c.phone LIKE :q ORDER BY c.id DESC LIMIT 10',
            ['q' => $like]
        );
        $clubs = is_array($clubs) ? $clubs : [];

        $signups = $db->select(
            'SELECT s.id, s.status, c.title AS competition_title, u.first_name AS rider_first, u.last_name AS rider_last FROM signups s JOIN competitions c ON s.competition_id = c.id JOIN users u ON s.rider_user_id = u.id WHERE c.title LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q ORDER BY s.id DESC LIMIT 10',
            ['q' => $like]
        );
        $signups = is_array($signups) ? $signups : [];

        return $this->ok([
            'competitions' => $competitions,
            'riders' => $riders,
            'horses' => $horses,
            'clubs' => $clubs,
            'signups' => $signups,
        ], $ctx);
    }
}