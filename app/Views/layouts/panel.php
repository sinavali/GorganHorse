<?php
declare(strict_types=1);

/**
 * File: app/Views/layouts/panel.php
 *
 * Purpose:
 *   Panel chrome: RTL-first, sidebar, topbar, banners, breadcrumb, flash
 *   messages, toast region, and script tags. Wraps every panel page rendered
 *   via BaseController::view() (Technical §26.2, User Usage §5).
 *
 * @var string $content  Rendered inner template HTML.
 * @var array  $view     Escape/format helper bundle.
 * @var array  $user     Current user row.
 * @var string $role     Current user role.
 * @var array  $kpi      KPI data (dashboard only; may be absent).
 * @var string $csrf     Current CSRF token.
 */
$dir = $view['dir'] ?? 'rtl';
$brand = (string) ($settings->get('app.name', 'هیئت سوارکاری استان گلستان'));
$role = (string) ($user['role'] ?? 'rider');
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = (string) ($user['username'] ?? 'کاربر');
}
$current = $_SERVER['REQUEST_URI'] ?? '/panel';
$path = parse_url($current, PHP_URL_PATH) ?: '/panel';

/** Sidebar item descriptor. */
$items = static function (string $label, string $href, string $match, string $icon = ''): array {
    return ['label' => $label, 'href' => $href, 'match' => $match, 'icon' => $icon];
};

$sidebar = [];
if ($role === 'admin' || $role === 'manager') {
    $sidebar[] = $items('داشبورد', '/panel', '/panel');
    $sidebar[] = $items('کاربران', '/panel/users', '/panel/users');
    $sidebar[] = $items('باشگاه‌ها', '/panel/clubs', '/panel/clubs');
    $sidebar[] = $items('اسبان', '/panel/horses', '/panel/horses');
    $sidebar[] = $items('رده‌ها', '/panel/rades', '/panel/rades');
    $sidebar[] = $items('پرداخت‌ها', '/panel/payments', '/panel/payments');
    $sidebar[] = $items('مسابقات', '/panel/competitions', '/panel/competitions');
    $sidebar[] = $items('ثبت‌نام‌ها', '/panel/signups', '/panel/signups');
    $sidebar[] = $items('صورت‌حساب‌ها', '/panel/payment-orders', '/panel/payment-orders');
    $sidebar[] = $items('گزارش‌ها', '/panel/reports', '/panel/reports');
    $sidebar[] = $items('اعلان‌ها', '/panel/notifications', '/panel/notifications');
    $sidebar[] = $items('پیام‌ها', '/panel/messages', '/panel/messages');
    if ($role === 'admin') {
        $sidebar[] = $items('تنظیمات', '/panel/settings', '/panel/settings');
        $sidebar[] = $items('حسابرسی', '/panel/audit', '/panel/audit');
        $sidebar[] = $items('پشتیبان‌گیری', '/panel/backups', '/panel/backups');
    }
} elseif ($role === 'rider') {
    $sidebar[] = $items('داشبورد', '/panel', '/panel');
    $sidebar[] = $items('اسبان من', '/panel/horses', '/panel/horses');
    $sidebar[] = $items('اشتراک‌ها', '/panel/horse-shares', '/panel/horse-shares');
    $sidebar[] = $items('مسابقات', '/panel/rider/competitions', '/panel/rider/competitions');
    $sidebar[] = $items('ثبت‌نام‌های من', '/panel/rider/signups', '/panel/rider/signups');
    $sidebar[] = $items('اعلان‌ها', '/panel/notifications', '/panel/notifications');
    $sidebar[] = $items('پیام‌ها', '/panel/messages', '/panel/messages');
} elseif ($role === 'club') {
    $sidebar[] = $items('داشبورد', '/panel', '/panel');
    $sidebar[] = $items('پروفایل باشگاه', '/panel/profile', '/panel/profile');
    $sidebar[] = $items('سوارکاران وابسته', '/panel/club/riders', '/panel/club/riders');
    $sidebar[] = $items('مسابقات', '/panel/competitions', '/panel/competitions');
    $sidebar[] = $items('تحریم‌ها', '/panel/club/bans', '/panel/club/bans');
    $sidebar[] = $items('گزارش‌ها', '/panel/reports', '/panel/reports');
    $sidebar[] = $items('اعلان‌ها', '/panel/notifications', '/panel/notifications');
    $sidebar[] = $items('پیام‌ها', '/panel/messages', '/panel/messages');
}

$flashSuccess = $flash['success'] ?? null;
$flashError = $flash['error'] ?? null;
?>
<!doctype html>
<html lang="<?= e($culture->code()) ?>" dir="<?= e($dir) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
    <title><?= e($brand) ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/panel.css">
</head>

<body>
    <a href="#main" class="skip-link">پرش به محتوا</a>
    <div class="panel-shell">
        <aside class="panel-sidebar" aria-label="ناوبری اصلی">
            <div class="panel-brand" style="color:#fff;font-weight:700;padding:8px 12px 16px"><?= e($brand) ?></div>
            <?php foreach ($sidebar as $item): ?>
                <?php
                $active = ($item['match'] !== '' && str_starts_with($path, $item['match']))
                    && !($item['match'] !== '/panel' && $path === '/panel');
                if ($item['match'] === '/panel' && $path === '/panel') {
                    $active = true;
                }
                ?>
                <a href="<?= e($item['href']) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
            <div style="margin-top:24px;border-top:1px solid #1e293b;padding-top:12px">
                <a href="/panel/profile">پروفایل من</a>
                <a href="#" data-action="logout">خروج</a>
            </div>
        </aside>
        <div class="panel-main">
            <header class="panel-topbar">
                <button type="button" class="btn" data-sidebar-toggle aria-label="منو" style="display:none"
                    id="menu-toggle">☰</button>
                <div style="display:flex;align-items:center;gap:12px">
                    <span class="panel-brand"><?= e($brand) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <span style="font-size:13px;color:var(--muted)"><?= e($fullName) ?></span>
                </div>
            </header>
            <main id="main" class="panel-content">
                <?php if ($ctx_user_impersonating ?? false): ?>
                    <div class="banner banner-impersonate">شما در حال جعل هویت هستید.</div>
                <?php endif; ?>
                <?php if (!empty($pending ?? false)): ?>
                    <div class="banner banner-pending">حساب شما هنوز توسط مدیر تایید نشده است.</div>
                <?php endif; ?>
                <?php if (($user['disable_state'] ?? 'none') === 'limited'): ?>
                    <div class="banner banner-limited">حساب شما محدود شده است. امکان ایجاد یا تغییر وجود ندارد.</div>
                <?php endif; ?>
                <?php if ($flashSuccess): ?>
                    <div class="banner" style="background:#dcfce7;color:#166534"><?= e($flashSuccess) ?></div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="banner" style="background:#fee2e2;color:#991b1b"><?= e($flashError) ?></div>
                <?php endif; ?>
                <?= $content ?>
            </main>
        </div>
    </div>
    <div class="toast-region" aria-live="polite"></div>
    <script src="/assets/js/vendor/alpine.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
    <script src="/assets/js/qr.js" defer></script>
    <script>
        // Show the sidebar toggle button on small screens.
        (function () {
            var btn = document.getElementById('menu-toggle');
            function sync() {
                if (!btn) return;
                btn.style.display = window.matchMedia('(max-width: 860px)').matches ? 'inline-flex' : 'none';
            }
            sync();
            window.addEventListener('resize', sync);
        })();
    </script>
</body>

</html>