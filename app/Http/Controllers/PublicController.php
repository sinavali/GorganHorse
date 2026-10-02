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
            return $this->notFound('این مسابقه یافت نشد یا حذف شده است.');
        }
        $comp = $this->c->get('competitions');
        $rades = $comp->rades((int) $row['id']);
        $isAuthed = $ctx->user !== null;
        $banner = $comp->banner((int) $row['id']);
        $slug = rawurlencode((string) $row['slug']);
        $cta = $isAuthed
            ? '<a class="btn btn-p" href="/rider/competitions">مشاهده و ثبت‌نام</a>'
            : '<a class="btn btn-p" href="/">ورود و ثبت‌نام</a>'
            . '<a class="btn btn-g" href="/">ورود به سامانه</a>';

        $statusLabels = [
            'draft' => 'پیش‌نویس', 'open' => 'ثبت‌نام باز', 'closed' => 'ثبت‌نام بسته',
            'running' => 'در حال اجرا', 'finished' => 'پایان‌یافته', 'cancelled' => 'لغوشده',
        ];
        $status = (string) $row['status'];
        $statusLabel = $statusLabels[$status] ?? $status;
        $statusTone = in_array($status, ['open', 'running'], true) ? 'ok' : (in_array($status, ['cancelled', 'closed'], true) ? 'warn' : '');
        $venueCity = (string) ($row['venue_city'] ?: $row['city'] ?: '');
        $place = trim((string) ($row['venue_name'] ?? '') . ($venueCity !== '' ? ' · ' . $venueCity : ''), ' ·');

        $title = (string) $row['title'];
        $meta = '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — هیئت سوارکاری گلستان</title>'
            . '<meta name="description" content="' . htmlspecialchars(mb_substr(strip_tags((string) ($row['description'] ?? '')), 0, 160), ENT_QUOTES, 'UTF-8') . '"/>'
            . '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"/>'
            . '<meta property="og:description" content="' . htmlspecialchars(mb_substr(strip_tags((string) ($row['description'] ?? '')), 0, 160), ENT_QUOTES, 'UTF-8') . '"/>'
            . '<meta property="og:type" content="website"/>'
            . ($banner !== null ? '<meta property="og:image" content="' . htmlspecialchars($this->absolute('/c/' . $slug . '/banner'), ENT_QUOTES, 'UTF-8') . '"/>' : '');

        /* Days/hours left until a deadline, in Persian. Server-rendered so the
           text is there without JavaScript; the tiny script at the bottom
           keeps it live. */
        $countdown = '';
        $deadline = $status === 'open'
            ? (string) $row['end_registration_at']
            : (string) $row['start_at'];
        $left = $this->untilLabel($deadline, $status === 'open');
        if ($left !== '') {
            $countdown = '<div class="countdown" data-cd="' . htmlspecialchars($deadline, ENT_QUOTES, 'UTF-8') . '">'
                . '<span class="cd-i">' . self::icon('i-clock') . '</span><span>' . $left . '</span></div>';
        }
        $share = '<a class="btn btn-g btn-sm" href="#" data-copy-link>' . self::icon('i-link') . ' کپی لینک</a>'
            . '<button class="btn btn-g btn-sm" type="button" data-share>هم‌رسانی</button>';

        /* ---- hero ---- */
        $heroMedia = $banner !== null
            ? '<img src="/c/' . $slug . '/banner" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"/>'
            : '';
        $hero = '<header class="hero' . ($banner !== null ? ' has-img' : '') . '">'
            . $heroMedia
            . '<div class="hero-veil"></div>'
            . '<div class="hero-in">'
            . '<span class="tag' . ($statusTone !== '' ? ' ' . $statusTone : '') . '">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . ($place !== '' ? '<p class="hero-place">' . htmlspecialchars($place, ENT_QUOTES, 'UTF-8') . '</p>' : '')
            . $countdown
            . '<div class="cta">' . $cta . $share . '</div>'
            . '</div></header>';

        $info = '<dl class="facts">'
            . $this->fact('زمان مسابقه', $this->date((string) $row['start_at']), 'i-trophy')
            . $this->fact('شروع ثبت‌نام', $this->date((string) $row['start_registration_at']), 'i-flag')
            . $this->fact('پایان ثبت‌نام', $this->date((string) $row['end_registration_at']), 'i-clock')
            . $this->fact('میدان', (string) ($row['venue_name'] ?? '—'), 'i-horse')
            . $this->fact('شهر', (string) ($row['city'] ?? '—'), 'i-pin')
            . '</dl>';

$totalCapacity = 0;
$totalTaken = 0;
$openRades = 0;
foreach ($rades as $rd) {
    $cap = (int) ($rd['capacity'] ?? 0);
    $used = (int) ($rd['signup_count'] ?? 0);
    $totalCapacity += $cap;
    $totalTaken += $used;
    if ($cap === 0 || $used < $cap) { $openRades++; }
}
$summary = '<div class="summary">'
    . '<div class="sum"><b>' . $this->fa(count($rades)) . '</b><span>رده</span></div>'
    . '<div class="sum"><b>' . $this->fa($openRades) . '</b><span>ردهٔ باز</span></div>'
    . '<div class="sum"><b>' . ($totalCapacity > 0 ? $this->fa(max(0, $totalCapacity - $totalTaken)) : '∞') . '</b><span>جای باقی‌مانده</span></div>'
    . '<div class="sum"><b>' . $this->fa($totalTaken) . '</b><span>ثبت‌نام</span></div>'
            . '</div>';

        $radeRows = '';
        foreach ($rades as $rd) {
            $cap = (int) ($rd['capacity'] ?? 0);
            $used = (int) ($rd['signup_count'] ?? 0);
            $full = $cap > 0 && $used >= $cap;
            $bar = $cap > 0
                ? '<div class="cap"><i style="width:' . min(100, (int) round($used / $cap * 100)) . '%" class="' . ($full ? 'full' : '') . '"></i></div>'
                    . '<div class="cap-txt">' . $this->fa($used) . ' از ' . $this->fa($cap) . ' نفر</div>'
                : '<div class="cap-txt">ظرفیت نامحدود</div>';
            $radeRows .= '<tr>'
                . '<td><b>' . htmlspecialchars((string) ($rd['rade_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</b>'
                . ((int) ($rd['age_min'] ?? 0) > 0 ? '<div class="mut sm">از ' . $this->fa((int) $rd['age_min']) . ' سال</div>' : '')
                . '</td>'
                . '<td class="amt">' . $this->fa((int) $rd['amount_irt']) . ' <span class="mut sm">تومان</span></td>'
                . '<td class="capc">' . $bar . '</td>'
                . '<td>' . ($full ? '<span class="tag warn">تکمیل</span>' : '<span class="tag ok">باز</span>') . '</td>'
                . '</tr>';
        }
        $radeTable = $radeRows === ''
            ? '<div class="empty">رده‌ای برای این مسابقه تعریف نشده است.</div>'
            : '<div class="tw"><table class="tb"><thead><tr><th>رده</th><th>هزینه</th><th>ظرفیت</th><th>وضعیت</th></tr></thead><tbody>' . $radeRows . '</tbody></table></div>';

        $body = '<a class="brand" href="/"><span class="mark"></span>هیئت سوارکاری استان گلستان</a>'
            . '<div class="shell">'
            . $hero
            . '<div class="cols">'
            . '<main>'
            . $summary
            . ($row['announcement'] ? '<div class="card note-card"><h2>' . self::icon('i-flag') . 'اطلاعیه مسابقه</h2>'
                . '<div class="rich">' . HtmlSanitizer::clean((string) $row['announcement']) . '</div></div>' : '')
            . ($row['description'] ? '<div class="card"><h2>درباره این مسابقه</h2><div class="rich">' . HtmlSanitizer::clean((string) $row['description']) . '</div></div>' : '')
            . '<div class="card"><h2>رده‌ها، هزینه‌ها و ظرفیت</h2>' . $radeTable . '</div>'
            . ($row['rules'] ? '<div class="card"><h2>قوانین و مقررات</h2><div class="rich">' . HtmlSanitizer::clean((string) $row['rules']) . '</div></div>' : '')
            . '</main>'
            . '<aside>'
            . '<div class="card sticky"><h2>اطلاعات مسابقه</h2>' . $countdown . $info
            . '<div class="cta cta-col">' . $cta . '</div></div>'
            . '<div class="card foot">این صفحه عمومی است و صرفاً برای اطلاع‌رسانی مسابقه ساخته شده است.</div>'
            . '</aside>'
            . '</div></div>'
            /* Sticky CTA for phones: the register button is the one thing a
               visitor came for, and the sidebar version scrolls off screen. */
            . '<div class="sticky-cta"><span class="s-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span class="s-cta">' . $cta . '</span></div>';

        return Response::html($this->layout((string) $row['title'], $body, $meta, $this->pageScript($deadline)));
    }

/**
     * Stream the wide banner of a public competition.
     *
     * Route:   GET /c/{slug}/banner
     * Auth:    guest (public)
     * Returns: the image bytes (200), or 404.
     *
     * Purpose: uploads live outside the web root and the authenticated
     * `GET /media/{id}` route cannot serve an anonymous visitor, so the banner
     * — the one asset `/c/{slug}` needs — has its own read-only route. Only
     * `competitions.banner_media_id` is reachable through it, so this cannot be
     * used to guess arbitrary media ids.
     */
    public function banner(Request $request, MiddlewareContext $ctx): Response
    {
        $slug = (string) $request->attr('slug');
        $media = $this->c->get('db')->selectOne(
            'SELECT m.path, m.mime, m.original_name
             FROM competitions c JOIN media m ON m.id = c.banner_media_id
             WHERE c.slug = :s',
            ['s' => $slug]
        );
        if ($media === null) {
            return $this->notFound('تصویری برای این مسابقه ثبت نشده است.');
        }
        $base = realpath(BASE_PATH . '/uploads');
        $real = realpath(BASE_PATH . '/' . ltrim((string) $media['path'], '/'));
        // Defence in depth: never serve anything outside the uploads directory.
        if ($base === false || $real === false || !str_starts_with($real, $base . '/') || !is_file($real)) {
            return $this->notFound('تصویری برای این مسابقه ثبت نشده است.');
        }
        $mime = (string) ($media['mime'] ?: 'application/octet-stream');
        // Only inline image types; anything else (a stray PDF) is not rendered.
        if (!str_starts_with($mime, 'image/')) {
            return $this->notFound('تصویری برای این مسابقه ثبت نشده است.');
        }
        $name = (string) ($media['original_name'] ?: basename($real));
        return new Response(
            (string) file_get_contents($real),
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $name) . '"',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }

    /**
     * How much time is left until a deadline, in Persian words.
     *
     * @param string $iso       UTC ISO-8601 deadline.
     * @param bool   $isClosing True when the deadline closes registration.
     * @return string
     */
    private function untilLabel(string $iso, bool $isClosing): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $at = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return '';
        }
        $secs = $at->getTimestamp() - $now->getTimestamp();
        if ($secs <= 0) {
            return $isClosing ? 'مهلت ثبت‌نام به پایان رسیده است' : 'این مسابقه برگزار شده است';
        }
        $days = intdiv($secs, 86400);
        $hours = intdiv($secs % 86400, 3600);
        $label = $isClosing ? 'تا پایان ثبت‌نام ' : 'تا شروع مسابقه ';
        if ($days > 0) {
            return $label . $this->fa($days) . ' روز و ' . $this->fa($hours) . ' ساعت';
        }
        if ($hours > 0) {
            return $label . $this->fa($hours) . ' ساعت';
        }
        return $label . $this->fa(intdiv($secs, 60)) . ' دقیقه';
    }

    /**
     * The page's only script: a live countdown, copy-link and Web Share.
     *
     * Purpose: the rest of the page is server-rendered HTML on purpose (it has
     * to survive being pasted into a messenger with no script at all), so this
     * stays small and degrades to a plain page if it fails.
     *
     * @param string $deadline UTC ISO-8601 deadline for the countdown.
     * @return string
     */
    private function pageScript(string $deadline): string
    {
        $ms = $deadline !== '' ? (string) ((new \DateTimeImmutable($deadline, new \DateTimeZone('UTC')))->getTimestamp() * 1000) : '0';
        $fa = 'function(n){return String(n).replace(/[0-9]/g,function(d){return"۰۱۲۳۴۵۶۷۸۹"[d];});}';
        return '<script>(function(){'
            . 'var fa=' . $fa . ';'
            . 'var cd=document.querySelector("[data-cd]");'
            . 'if(cd){var end=' . $ms . ';'
            . 'var tick=function(){var s=Math.floor((end-Date.now())/1000);if(s<=0){cd.querySelector("span:last-child").textContent="زمان این مهلت به پایان رسیده است";return;}'
            . 'var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);'
            . 'cd.querySelector("span:last-child").textContent=fa(d)+" روز و "+fa(h)+" ساعت و "+fa(m)+" دقیقه";};'
            . 'tick();setInterval(tick,30000);}'
            . 'document.addEventListener("click",function(e){'
            . 'var b=e.target.closest("[data-copy-link],[data-share]");if(!b){return;}e.preventDefault();'
            . 'var url=location.href;var t=b.textContent;'
            . 'if(b.hasAttribute("data-share")&&navigator.share){navigator.share({title:document.title,url:url}).catch(function(){});return;}'
            . 'var done=function(){b.textContent="کپی شد";setTimeout(function(){b.textContent=t;},1800);};'
            . 'if(navigator.clipboard){navigator.clipboard.writeText(url).then(done,function(){});}else{done();}'
            . '});'
            . '})();</script>';
    }

    /**
     * A 404 rendered in the public stylesheet.
     *
     * Purpose: single place for the public 404 — passing an empty array as the
     * third layout() argument used to raise a TypeError and answer 500.
     *
     * @param string $message Message shown on the page.
     * @return Response
     */
    private function notFound(string $message): Response
    {
        return Response::html(
            $this->layout('یافت نشد', '<div class="wrap"><div class="card"><div class="empty">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div></div></div>', ''),
            404
        );
    }

    /**
     * Wrap page content in the public stylesheet.
     *
     * @param string $title Page title.
     * @param string $body  Inner HTML.
     * @param string $meta  Extra <head> markup (OG tags).
     * @param string $script Optional inline <script> appended before </body>.
     * @return string
     */
    private function layout(string $title, string $body, string $meta = '', string $script = ''): string
    {
        return '<!DOCTYPE html><html lang="fa-IR" dir="rtl"><head><meta charset="UTF-8"/>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"/>'
            . '<meta name="theme-color" content="#0d4a32"/>'
            . '<link rel="icon" href="/views/assets/favicon.svg" type="image/svg+xml"/>'
            /* The Persian webfont is vendored so this page renders identically on
               a host with no outbound network; preloading it stops the hero
               from reflowing when it lands. */
            . '<link rel="preload" href="/views/assets/fonts/vazirmatn-arabic-var.woff2" as="font" type="font/woff2" crossorigin/>'
            . '<link rel="stylesheet" href="/views/assets/css/public.css"/>' . $meta . '</head><body>' . $body . $script . '</body></html>';
    }

    /**
     /**
     * One fact row in the public sidebar: inline icon + label + value.
     *
     * @param string $label Label.
     * @param string $value Value.
     * @param string $icon  Icon name from the panel sprite.
     * @return string
     */
    private function fact(string $label, string $value, string $icon): string
    {
        return '<div class="fact">' . self::icon($icon)
            . '<div><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<b>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</b></div></div>';
    }

    /**
     * Minimal inline SVG icon for the public page.
     *
     * The panel draws its icons from a JS sprite in ui.js; this page ships no
     * JS at all, so the few glyphs it needs are inlined as stroke paths.
     *
     * @param string $name Icon name.
     * @return string
     */
    private static function icon(string $name): string
    {
        $paths = [
            'i-trophy' => '<path d="M8 3h8v4a4 4 0 0 1-8 0V3z"/><path d="M8 4H5a2 2 0 0 0 2 3M16 4h3a2 2 0 0 1-2 3M12 11v3M9 17h6M10 14h4l1 3H9z"/>',
            'i-flag' => '<path d="M5 21V4M5 5h11l-2 3 2 3H5"/>',
            'i-clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
            'i-horse' => '<path d="M4 20c0-5 2-8 5-9l2-4 3 2 4-1-1 4-4 2-2 6"/>',
            'i-pin' => '<path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'i-info' => '<circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01"/>',
        ];
        if (!isset($paths[$name])) {
            return '';
        }
        return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
    }

    /**
     * Absolute URL for a path, built from the current request host.
     *
     * Needed for og:image, which crawlers resolve against an absolute URL.
     *
     * @param string $path Absolute path.
     * @return string
     */
    private function absolute(string $path): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . $path;
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
