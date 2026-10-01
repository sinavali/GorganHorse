<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/PublicController.php
 *
 * Purpose:
 *   Public, unauthenticated competition page used for sharing. Renders a small
 *   server-side HTML page (the only non-JSON response in the app) so a
 *   competition can be shared on messengers/WhatsApp with a stable URL, with a
 *   call-to-action that sends signed-in users into the panel to sign up
 *   (User Usage §9.5/§13).
 *
 *   Everything shown here is public information: title, dates, venue, city,
 *   status, sanitized description/rules, rades with fees and remaining capacity.
 *   No rider or financial data is exposed.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;
use App\Support\Vendor\HtmlSanitizer;
use App\Support\Vendor\Jalali;

/**
 * Class: PublicController
 * Purpose: Render the shareable public competition page.
 */
final class PublicController extends BaseController
{
    /**
     * Show a competition by its public slug.
     *
     * Route:   GET /c/{slug}
     * Auth:    guest (public)
     * Returns: HTML page (200) or a minimal HTML 404.
     */
    public function competition(Request $request, MiddlewareContext $ctx): Response
    {
        $slug = (string) $request->attr('slug');
        $row = $this->c->get('db')->selectOne(
            'SELECT c.*, cl.name AS venue_name, cl.city AS venue_city, cl.address AS venue_address
             FROM competitions c
             LEFT JOIN clubs cl ON cl.id = c.venue_club_id
             WHERE c.slug = :s',
            ['s' => $slug]
        );
        if ($row === null) {
            return Response::html($this->layout('یافت نشد', '<div class="empty">این مسابقه یافت نشد یا حذف شده است.</div>', []), 404);
        }
        $comp = $this->c->get('competitions');
        $rades = $comp->rades((int) $row['id']);
        $isAuthed = $ctx->user !== null;

        $statusLabels = [
            'draft' => 'پیش‌نویس', 'open' => 'ثبت‌نام باز', 'closed' => 'ثبت‌نام بسته',
            'running' => 'در حال اجرا', 'finished' => 'پایان‌یافته', 'cancelled' => 'لغوشده',
        ];
        $statusLabel = $statusLabels[(string) $row['status']] ?? (string) $row['status'];

        $meta = '<title>' . htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') . ' — هیئت سوارکاری گلستان</title>'
            . '<meta property="og:title" content="' . htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') . '"/>'
            . '<meta property="og:description" content="' . htmlspecialchars(mb_substr(strip_tags((string) ($row['description'] ?? '')), 0, 160), ENT_QUOTES, 'UTF-8') . '"/>'
            . '<meta property="og:type" content="website"/>';

        $info = '<div class="grid">'
            . $this->item('وضعیت', $statusLabel)
            . $this->item('شهر', (string) ($row['city'] ?? '—'))
            . $this->item('میدان', (string) ($row['venue_name'] ?? '—'))
            . $this->item('شروع ثبت‌نام', $this->date((string) $row['start_registration_at']))
            . $this->item('پایان ثبت‌نام', $this->date((string) $row['end_registration_at']))
            . $this->item('زمان مسابقه', $this->date((string) $row['start_at']))
            . '</div>';

        $radeRows = '';
        foreach ($rades as $rd) {
            $full = $rd['capacity'] !== null && (int) $rd['capacity'] > 0
                && (int) ($rd['signup_count'] ?? 0) >= (int) $rd['capacity'];
            $remaining = ($rd['capacity'] === null || (int) $rd['capacity'] === 0)
                ? 'نامحدود'
                : $this->fa((int) $rd['capacity'] - (int) ($rd['signup_count'] ?? 0));
            $radeRows .= '<tr>'
                . '<td><b>' . htmlspecialchars((string) ($rd['rade_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</b>'
                . ((int) ($rd['age_min'] ?? 0) > 0 ? '<div class="mut sm">از ' . $this->fa((int) $rd['age_min']) . ' سال</div>' : '')
                . '</td>'
                . '<td>' . $this->fa((int) $rd['amount_irt']) . ' تومان</td>'
                . '<td>' . htmlspecialchars($remaining, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . ($full ? '<span class="tag warn">تکمیل</span>' : '<span class="tag ok">باز</span>') . '</td>'
                . '</tr>';
        }
        $radeTable = $radeRows === ''
            ? '<div class="empty">رده‌ای برای این مسابقه تعریف نشده است.</div>'
            : '<table class="tb"><thead><tr><th>رده</th><th>هزینه</th><th>ظرفیت باقی‌مانده</th><th>وضعیت</th></tr></thead><tbody>' . $radeRows . '</tbody></table>';

        $cta = $isAuthed
            ? '<a class="btn btn-p" href="/rider/competitions">مشاهده و ثبت‌نام</a>'
            : '<a class="btn btn-p" href="/">ورود و ثبت‌نام</a>'
            . '<a class="btn btn-g" href="/">ورود به سامانه</a>';

        $body = '<div class="wrap">'
            . '<a class="brand" href="/c"><span class="mark"></span>هیئت سوارکاری استان گلستان</a>'
            . '<div class="card hero">'
            . '<span class="tag">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<h1>' . htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') . '</h1>'
            . ($row['city'] || $row['venue_name'] ? '<p class="mut">' . htmlspecialchars(trim((string) ($row['venue_name'] ?? '') . ' · ' . (string) ($row['city'] ?? ''), ' ·'), ENT_QUOTES, 'UTF-8') . '</p>' : '')
            . ($row['description'] ? '<div class="rich">' . HtmlSanitizer::clean((string) $row['description']) . '</div>' : '')
            . '<div class="cta">' . $cta . '</div>'
            . '</div>'
            . '<div class="card"><h2>اطلاعات مسابقه</h2>' . $info . '</div>'
            . '<div class="card"><h2>رده‌ها و هزینه‌ها</h2>' . $radeTable . '</div>'
            . ($row['rules'] ? '<div class="card"><h2>قوانین و مقررات</h2><div class="rich">' . HtmlSanitizer::clean((string) $row['rules']) . '</div></div>' : '')
            . '<div class="card note">این صفحه عمومی است و صرفاً برای اطلاع‌رسانی مسابقه ساخته شده است.</div>'
            . '</div>';

        return Response::html($this->layout((string) $row['title'], $body, $meta));
    }

    /**
     * Wrap page content in the public stylesheet.
     *
     * @param string $title Page title.
     * @param string $body  Inner HTML.
     * @param string $meta  Extra <head> markup (OG tags).
     * @return string
     */
    private function layout(string $title, string $body, string $meta = ''): string
    {
        return '<!DOCTYPE html><html lang="fa-IR" dir="rtl"><head><meta charset="UTF-8"/>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"/>'
            . '<link rel="icon" href="/views/assets/favicon.svg" type="image/svg+xml"/>'
            . '<link rel="stylesheet" href="/views/assets/css/public.css"/>' . $meta . '</head><body>' . $body . '</body></html>';
    }

    /**
     * One info cell.
     *
     * @param string $label Label.
     * @param string $value Value.
     * @return string
     */
    private function item(string $label, string $value): string
    {
        return '<div class="cell"><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<b>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</b></div>';
    }

    /**
     * Persian digits for a number.
     *
     * @param int $n Number.
     * @return string
     */
    private function fa(int $n): string
    {
        return str_replace(range(0, 9), ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], (string) $n);
    }

    /**
     * Format an ISO timestamp as a Jalali long date in Tehran time.
     *
     * @param string $iso UTC ISO-8601 timestamp.
     * @return string
     */
    private function date(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try {
            $dt = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
            $dt = $dt->setTimezone(new \DateTimeZone('Asia/Tehran'));
            [$jy, $jm, $jd] = Jalali::gregorianToJalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
            $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
            return $this->fa($jd) . ' ' . $months[$jm - 1] . ' ' . $this->fa($jy);
        } catch (\Throwable) {
            return '—';
        }
    }
}
