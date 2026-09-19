<?php
declare(strict_types=1);

/**
 * File: app/Views/layouts/auth.php
 *
 * Purpose: Auth chrome (login, signup, forgot, install). RTL-first, Vazirmatn.
 *
 * @var string $content Rendered inner template HTML.
 * @var array  $view    Escape/format helper bundle.
 */
$dir = $view['dir'] ?? 'rtl';
$brand = (string) ($settings->get('app.name', 'هیئت سوارکاری استان گلستان'));
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
<body class="auth-body">
    <a href="#main" class="skip-link">پرش به محتوا</a>
    <main id="main" class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand"><?= e($brand) ?></div>
            <?= $content ?>
        </div>
        <p class="auth-footer"><?= e((string) ($settings->get('brand.copyright', ''))) ?></p>
    </main>
    <script src="/assets/js/vendor/alpine.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
</body>
</html>
