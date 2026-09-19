<?php
declare(strict_types=1);

/**
 * File: app/Services/Report/KpiService.php
 *
 * Purpose:
 *   Compute dashboard KPIs and summary tiles per role (Blueprint §13.3–13.4,
 *   User Usage §20). All formulas follow the documented KPI definitions.
 *
 * Dependencies: Database, SettingService, ClubService.
 *
 * @package App\Services\Report
 */

namespace App\Services\Report;

use App\Bootstrap\Database;

/**
 * Class: KpiService
 * Purpose: Produce role dashboards and KPI values.
 */
final class KpiService
{
    private Database $db;

    /**
     * @param Database $db Main database.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Admin/Manager dashboard data.
     *
     * @param array $user Current user.
     * @return array
     */
    public function staffDashboard(array $user): array
    {
        $monthStart = utc_iso(strtotime(gmdate('Y-m-01')));
        $weekStart = utc_iso(strtotime('-7 days'));
        $dayStart = utc_iso(strtotime('today'));

        $data = [
            'total_riders' => (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE role='rider'"),
            'riders_delta' => $this->delta7d('users', "role='rider'"),
            'total_horses' => (int) $this->db->scalar("SELECT COUNT(*) FROM horses WHERE status='active'"),
            'horses_delta' => $this->delta7d('horses', "status='active'"),
            'total_clubs' => (int) $this->db->scalar('SELECT COUNT(*) FROM clubs'),
            'active_competitions' => (int) $this->db->scalar("SELECT COUNT(*) FROM competitions WHERE status='open'"),
            'revenue_month' => $this->revenue($monthStart, null),
            'revenue_week' => $this->revenue($weekStart, null),
            'revenue_today' => $this->revenue($dayStart, null),
            'pending_verifications' => (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE role='rider' AND verification_status='pending'"),
            'pending_confirmations' => (int) $this->db->scalar("SELECT COUNT(*) FROM signups WHERE status='paid'"),
            'pending_refunds' => (int) $this->db->scalar("SELECT COUNT(*) FROM payment_orders WHERE status='pending_refund'"),
            'active_sessions' => (int) $this->db->scalar('SELECT COUNT(*) FROM sessions WHERE expires_at > :n', ['n' => now_utc()]),
            'signups_today' => (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE created_at >= :d', ['d' => $dayStart]),
            'pending_payments' => (int) $this->db->scalar("SELECT COUNT(*) FROM payment_orders WHERE status='pending'"),
            'todays_competitions' => (int) $this->db->scalar('SELECT COUNT(*) FROM competitions WHERE date(start_at) = date(:n)', ['n' => now_utc()]),
            'signups_over_time' => $this->timeSeries('signups', 30),
            'revenue_over_time' => $this->timeSeries('revenue', 30),
            'rade_popularity' => $this->radePopularity(90),
            'recent_changelog' => $this->recentChangelog(),
            'is_admin' => ($user['role'] ?? '') === 'admin',
        ];
        return $data;
    }

    /**
     * Read the most recent changelog entries from the logs database.
     *
     * The changelog table lives in logs.sqlite (Blueprint §7.2, P13), so it is
     * resolved from the container rather than the main connection.
     *
     * @return array<int,array>
     */
    private function recentChangelog(): array
    {
        try {
            $logsDb = \App\Support\container('logs_db');
            if ($logsDb instanceof \App\Bootstrap\Database) {
                return $logsDb->select('SELECT * FROM changelog ORDER BY id DESC LIMIT 20');
            }
        } catch (\Throwable) {
            // Logs DB unavailable; the dashboard degrades gracefully.
        }
        return [];
    }

    /**
     * Rider dashboard data.
     *
     * @param int $userId Rider id.
     * @return array
     */
    public function riderDashboard(int $userId): array
    {
        return [
            'my_horses' => (int) $this->db->scalar("SELECT COUNT(*) FROM horses WHERE owner_user_id = :u AND status='active'", ['u' => $userId]),
            'my_signups_pending' => (int) $this->db->scalar("SELECT COUNT(*) FROM signups WHERE rider_user_id = :u AND status IN ('pending_payment','paid')", ['u' => $userId]),
            'my_signups_confirmed' => (int) $this->db->scalar("SELECT COUNT(*) FROM signups WHERE rider_user_id = :u AND status='confirmed'", ['u' => $userId]),
            'my_wins' => (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE rider_user_id = :u AND is_winner = 1', ['u' => $userId]),
            'upcoming_competitions' => (int) $this->db->scalar("SELECT COUNT(*) FROM competitions WHERE status IN ('open','closed') AND start_at >= :n", ['n' => now_utc()]),
            'pending_shares' => (int) $this->db->scalar("SELECT COUNT(*) FROM horse_shares WHERE recipient_user_id = :u AND status='pending'", ['u' => $userId]),
            'my_horses_list' => $this->db->select("SELECT id, name, microchip_number FROM horses WHERE owner_user_id = :u AND status='active' ORDER BY id DESC LIMIT 5", ['u' => $userId]),
            'recent_results' => $this->db->select(
                "SELECT s.position, s.is_winner, c.title AS competition, r.name AS rade, h.name AS horse
                 FROM signups s JOIN competitions c ON c.id = s.competition_id JOIN rades r ON r.id = s.rade_id JOIN horses h ON h.id = s.horse_id
                 WHERE s.rider_user_id = :u AND s.position IS NOT NULL ORDER BY c.start_at DESC LIMIT 5", ['u' => $userId]),
        ];
    }

    /**
     * Club dashboard data.
     *
     * @param int $clubUserId Club user id.
     * @return array
     */
    public function clubDashboard(int $clubUserId): array
    {
        $club = $this->db->selectOne('SELECT * FROM clubs WHERE user_id = :u', ['u' => $clubUserId]);
        if ($club === null) { return []; }
        $clubId = (int) $club['id'];
        $monthStart = utc_iso(strtotime(gmdate('Y-m-01')));
        return [
            'venue_competitions' => (int) $this->db->scalar('SELECT COUNT(*) FROM competitions WHERE venue_club_id = :c', ['c' => $clubId]),
            'affiliated_riders' => (int) $this->db->scalar('SELECT COUNT(DISTINCT rider_user_id) FROM signups WHERE affiliation_club_id = :c', ['c' => $clubId]),
            'revenue_month' => (int) $this->db->scalar(
                "SELECT COALESCE(SUM(po.amount_irt),0) FROM payment_orders po JOIN signups s ON s.id = po.signup_id
                 JOIN competitions c ON c.id = s.competition_id WHERE c.venue_club_id = :c AND po.status='paid' AND po.verified_at >= :m",
                ['c' => $clubId, 'm' => $monthStart]
            ),
            'active_bans' => (int) $this->db->scalar('SELECT COUNT(*) FROM club_bans WHERE club_id = :c AND is_active = 1', ['c' => $clubId]),
            'recent_bans' => $this->db->select('SELECT * FROM club_bans WHERE club_id = :c ORDER BY id DESC LIMIT 5', ['c' => $clubId]),
        ];
    }

    /**
     * Compute a 7-day delta count for a table with a condition.
     *
     * @param string $table     Table (trusted).
     * @param string $condition Condition.
     * @return int
     */
    private function delta7d(string $table, string $condition): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $condition . ' AND created_at >= :since', ['since' => utc_iso(strtotime('-7 days'))]);
    }

    /**
     * Sum paid revenue in a UTC window.
     *
     * @param string      $from UTC lower bound.
     * @param string|null $to   UTC upper bound.
     * @return int IRT total.
     */
    private function revenue(string $from, ?string $to): int
    {
        $sql = "SELECT COALESCE(SUM(amount_irt),0) FROM payment_orders WHERE status='paid' AND verified_at >= :from";
        $params = ['from' => $from];
        if ($to !== null) { $sql .= ' AND verified_at <= :to'; $params['to'] = $to; }
        return (int) $this->db->scalar($sql, $params);
    }

    /**
     * Build a daily time series for the last N days (signups or revenue).
     *
     * @param string $kind signups|revenue.
     * @param int    $days Day count.
     * @return array<int,array{date:string,value:int}>
     */
    private function timeSeries(string $kind, int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-$i days"));
            if ($kind === 'signups') {
                $v = (int) $this->db->scalar('SELECT COUNT(*) FROM signups WHERE date(created_at) = :d', ['d' => $day]);
            } else {
                $v = (int) $this->db->scalar("SELECT COALESCE(SUM(amount_irt),0) FROM payment_orders WHERE status='paid' AND date(verified_at) = :d", ['d' => $day]);
            }
            $out[] = ['date' => $day, 'value' => $v];
        }
        return $out;
    }

    /**
     * Rade popularity over the last N days.
     *
     * @param int $days Day count.
     * @return array<int,array{name:string,value:int}>
     */
    private function radePopularity(int $days): array
    {
        return $this->db->select(
            'SELECT r.name, COUNT(*) AS value FROM signups s JOIN rades r ON r.id = s.rade_id
             WHERE s.created_at >= :since GROUP BY r.id ORDER BY value DESC LIMIT 10',
            ['since' => utc_iso(strtotime("-$days days"))]
        );
    }
}
