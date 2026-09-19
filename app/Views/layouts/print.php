<?php
declare(strict_types=1);

/**
 * File: app/Views/layouts/print.php
 *
 * Purpose: A4 print chrome. Server-rendered, no JS needed at print time
 *          (Blueprint §17.2, Technical §27.1).
 *
 * @var string $content Rendered inner template HTML.
 * @var array  $view    Escape/format helper bundle.
 */
$dir = $view['dir'] ?? 'rtl';
$brand = (string) ($settings->get('app.name', 'هیئت سوارکاری استان گلستان'));
$headerText = (string) ($settings->get('reports.print_header_text', '')) ?: $brand;
$footerText = (string) ($settings->get('reports.print_footer_text', '')) ?: $brand;
$qrData = (string) (($qr ?? '') !== '' ? $qr : ($_SERVER['REQUEST_URI'] ?? '/panel'));
?>
<!doctype html>
<html lang="<?= e($culture->code()) ?>" dir="<?= e($dir) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $brand) ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/print.css">
</head>

<body class="print-body">
    <header class="print-header">
        <div class="print-brand"><?= e($headerText) ?></div>
        <div class="print-title"><?= e($title ?? '') ?></div>
        <div class="print-date"><?= e($view['date'](now_utc(), false)) ?></div>
        <div class="print-qr" id="print-qr" data-qr="<?= e($qrData) ?>" style="width:64px;height:64px"></div>
    </header>
    <main class="print-content">
        <?= $content ?>
    </main>
    <footer class="print-footer">
        <span><?= e($footerText) ?></span>
    </footer>
    <script src="/assets/js/vendor/qrcode.min.js"></script>
    <script src="/assets/js/qr.js"></script>
    <script>
        (function () {
            var el = document.getElementById('print-qr');
            if (!el || !window.PanelQr) { return; }
            window.PanelQr.render(el, el.getAttribute('data-qr') || window.location.href);
        })();
    </script>
</body>

</html>