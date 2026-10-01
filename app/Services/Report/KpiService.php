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
            'attention' => $this->attention((string) ($user['role'] ?? 'admin')),
            'is_admin' => ($user['role'] ?? '') === 'admin',
        ];
        return $data;
    }

    /**
     * "What needs my attention" feed for the dashboard.
     *
     * Groups the operational queues a staff member must clear today so the
     * dashboard does not just show counters but actionable, linkable lists.
     *
     * @param string $role Viewer role.
     * @return array<int,array{key:string,label:string,count:int,tone:string,path:string,icon:string}>
     */
    public function attention(string $role): array
    {
        if ($role === 'rider' || $role === 'club') {
            return [];
        }
        $soon = utc_iso(time() + 172800); // 48 hours
        $out = [];
        $add = static function (string $key, string $label, int $count, string $tone, string $path, string $icon) use (&$out): void {
            if ($count > 0) {
                $out[] = ['key' => $key, 'label' => $label, 'count' => $count, 'tone' => $tone, 'path' => $path, 'icon' => $icon];
            }
        };

        $add('verify', 'سوارکاران در انتظار تأیید',
            (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE role='rider' AND verification_status='pending'"),
            'b-warn', '/users?status=pending', 'i-user');
        $add('confirm', 'ثبت‌نام‌های پرداخت‌شده در انتظار تأیید',
            (int) $this->db->scalar("SELECT COUNT(*) FROM signups WHERE status='paid'"),
            'b-info', '/signups?status=paid', 'i-list');
        $add('refund', 'درخواست‌های استرداد',
            (int) $this->db->scalar("SELECT COUNT(*) FROM payment_orders WHERE status='pending_refund'"),
            'b-bad', '/payment-orders?status=pending_refund', 'i-wallet');
        $add('deadline', 'مسابقاتی که تا ۴۸ ساعت دیگر ثبت‌نامشان بسته می‌شود',
            (int) $this->db->scalar("SELECT COUNT(*) FROM competitions WHERE status='open' AND end_registration_at BETWEEN :n AND :s",
                ['n' => now_utc(), 's' => $soon]),
            'b-warn', '/competitions?status=open', 'i-clock');
        $add('draft', 'مسابقات در وضعیت پیش‌نویس',
            (int) $this->db->scalar("SELECT COUNT(*) FROM competitions WHERE status='draft'"),
            'b-mut', '/competitions?status=draft', 'i-file');
        $add('unverified_horse', 'اسبان بدون ریزتراشه',
            (int) $this->db->scalar("SELECT COUNT(*) FROM horses WHERE status='active' AND (microchip_number IS NULL OR microchip_number='')"),
            'b-mut', '/horses', 'i-horse');
        $add('unpaid', 'پرداخت‌های ناموفق',
            (int) $this->db->scalar("SELECT COUNT(*) FROM payment_orders WHERE status='failed'"),
            'b-bad', '/payment-orders?status=failed', 'i-info');
        return $out;
    }

    /**
     * Cumulative rider ranking across all published results.
     *
     * Aggregates confirmed positions and wins per rider so the federation can
     * publish an overall standings table (Blueprint §9.4).
     *
     * @param int $limit Max rows.
     * @return array<int,array>
     */
    public function riderRanking(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db->select(
            "SELECT s.rider_user_id AS rider_user_id,
                    u.first_name || ' ' || u.last_name AS rider_name,
                    u.username,
                    COUNT(*) AS entries,
                    SUM(CASE WHEN s.is_winner = 1 THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN s.position = 1 THEN 1 ELSE 0 END) AS firsts,
                    SUM(CASE WHEN s.position = 2 THEN 1 ELSE 0 END) AS seconds,
                    SUM(CASE WHEN s.position = 3 THEN 1 ELSE 0 END) AS thirds,
                    SUM(CASE s.position WHEN 1 THEN 10 WHEN 2 THEN 6 WHEN 3 THEN 4 ELSE 2 END) AS points
             FROM signups s
             JOIN users u ON u.id = s.rider_user_id
             WHERE s.position IS NOT NULL AND s.status = 'confirmed'
             GROUP BY s.rider_user_id
             ORDER BY points DESC, wins DESC, firsts DESC, entries ASC
             LIMIT :l",
            ['l' => $limit]
        );
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
            'my_ranking' => $this->riderRankFor($userId),
            'pending_shares' => (int) $this->db->scalar("SELECT COUNT(*) FROM horse_shares WHERE recipient_user_id = :u AND status='pending'", ['u' => $userId]),
            'my_horses_list' => $this->db->select("SELECT id, name, microchip_number FROM horses WHERE owner_user_id = :u AND status='active' ORDER BY id DESC LIMIT 5", ['u' => $userId]),
            'recent_results' => $this->db->select(
                "SELECT s.position, s.is_winner, c.title AS competition, r.name AS rade, h.name AS horse
                 FROM signups s JOIN competitions c ON c.id = s.competition_id JOIN rades r ON r.id = s.rade_id JOIN horses h ON h.id = s.horse_id
                 WHERE s.rider_user_id = :u AND s.position IS NOT NULL ORDER BY c.start_at DESC LIMIT 5", ['u' => $userId]),
        ];
    }

    /**
     * Personal activity summary for the current user's own Profile page.
     *
     * Unlike staffDashboard() (organisation-wide) this is scoped to one
     * account, so it is safe to show to every role. It carries the numbers
     * worth charting: a 6-month signup/competition series, per-status
     * distribution, and the account's own standing.
     *
     * @param int    $userId User id.
     * @param string $role   User role.
     * @return array
     */
    public function profileStats(int $userId, string $role): array
    {
        $out = [
            'role' => $role,
            'unread_notifications' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            ),
            'signup_status' => $this->db->select(
                "SELECT status, COUNT(*) AS n FROM signups WHERE rider_user_id = :u GROUP BY status",
                ['u' => $userId]
            ),
            'monthly' => $this->db->select(
                "SELECT substr(created_at,1,7) AS ym, COUNT(*) AS n
                 FROM signups WHERE rider_user_id = :u AND created_at >= :since
                 GROUP BY ym ORDER BY ym",
                ['u' => $userId, 'since' => gmdate('Y-m-01', strtotime('-5 months'))]
            ),
        ];

        if ($role === 'rider') {
            $out['my_horses'] = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM horses WHERE owner_user_id = :u AND status = 'active'",
                ['u' => $userId]
            );
            $out['my_wins'] = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM signups WHERE rider_user_id = :u AND is_winner = 1',
                ['u' => $userId]
            );
            $out['entries'] = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM signups WHERE rider_user_id = :u',
                ['u' => $userId]
            );
            $out['ranking'] = $this->riderRankFor($userId);
        } elseif ($role === 'club') {
            /* A club ACCOUNT owns its club row via clubs.user_id, but
               signups.affiliation_club_id points at clubs.id — resolve one to
               the other before counting. */
            $clubId = (int) $this->db->scalar('SELECT id FROM clubs WHERE user_id = :u LIMIT 1', ['u' => $userId]);
            $out['club_id'] = $clubId;
            if ($clubId > 0) {
                $out['club_riders'] = (int) $this->db->scalar(
                    'SELECT COUNT(DISTINCT rider_user_id) FROM signups WHERE affiliation_club_id = :c',
                    ['c' => $clubId]
                );
                $out['club_horses'] = (int) $this->db->scalar(
                    'SELECT COUNT(DISTINCT horse_id) FROM signups WHERE affiliation_club_id = :c',
                    ['c' => $clubId]
                );
                $out['club_signups'] = (int) $this->db->scalar(
                    'SELECT COUNT(*) FROM signups WHERE affiliation_club_id = :c',
                    ['c' => $clubId]
                );
            } else {
                $out['club_riders'] = 0;
                $out['club_horses'] = 0;
                $out['club_signups'] = 0;
            }
        } else {
            /* Staff have no personal competition record; show their review
               workload instead, which is the number they care about. */
            $out['pending_users'] = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM users WHERE verification_status = 'pending'"
            );
            $out['pending_signups'] = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM signups WHERE status IN ('paid','pending_payment')"
            );
            $out['open_competitions'] = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM competitions WHERE status IN ('open','closed')"
            );
        }
        return $out;
    }

    /**
     * This rider's row in the cumulative ranking (null when unranked).
     *
     * @param int $userId Rider id.
     * @return array{position:int,points:int,wins:int,entries:int}|null
     */
    private function riderRankFor(int $userId): ?array
    {
        $all = $this->riderRanking(1000);
        foreach ($all as $i => $r) {
            if ((int) $r['rider_user_id'] === $userId) {
                return [
                    'position' => $i + 1,
                    'points' => (int) $r['points'],
                    'wins' => (int) $r['wins'],
                    'entries' => (int) $r['entries'],
                ];
            }
        }
        return null;
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
