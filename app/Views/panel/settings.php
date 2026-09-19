<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/settings.php
 *
 * Purpose: Settings page with grouped tabs. Admin only.
 * (Blueprint §15, User Usage §7.21).
 *
 * @var array  $view
 * @var array  $groups
 * @var string $active
 * @var string $csrf
 */
$active = $active ?? 'general';
$groups = $groups ?? [];
?>
<h1 style="margin:0 0 16px">تنظیمات</h1>

<div class="tabs" style="display:flex;gap:4px;border-block-end:2px solid var(--line);margin-block-end:16px;flex-wrap:wrap">
    <?php foreach (['general' => 'عمومی', 'whitelabel' => 'برند', 'auth' => 'احراز هویت', 'uploads' => 'آپلود', 'clubs' => 'باشگاه‌ها', 'horses' => 'اسبان', 'competitions' => 'مسابقات', 'payments' => 'پرداخت‌ها', 'sms' => 'پیامک', 'reports' => 'گزارش‌ها', 'cache' => 'کش', 'logs' => 'لاگ', 'backup' => 'پشتیبان‌گیری', 'security' => 'امنیت', 'ui' => 'رابط کاربری'] as $k => $v): ?>
        <a href="/panel/settings?tab=<?= e($k) ?>" class="btn <?= $active === $k ? 'btn-primary' : '' ?>"><?= e($v) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!empty($groups[$active] ?? [])): ?>
<form id="settings-form" method="post" action="/panel/settings?tab=<?= e($active) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <?php foreach ($groups[$active] as $key => $val): ?>
            <div class="field">
                <label for="setting-<?= e($key) ?>"><?= e($key) ?></label>
                <?php if (is_bool($val)): ?>
                    <input id="setting-<?= e($key) ?>" type="checkbox" name="settings[<?= e($key) ?>]" value="1" <?= $val ? 'checked' : '' ?>>
                <?php else: ?>
                    <input id="setting-<?= e($key) ?>" type="text" name="settings[<?= e($key) ?>]" value="<?= e((string) ($val ?? '')) ?>">
                <?php endif; ?>
                <span class="help"><?= e($view['setting_help']($key) ?? '') ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="toolbar" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </div>
</form>
<?php else: ?>
    <div class="empty-state">تنظیمات این بخش خالی است.</div>
<?php endif; ?>

<?php if ($active === 'cache'): ?>
<div class="toolbar" style="margin-block-start:16px">
    <button class="btn btn-danger" data-action="clear-cache">پاک‌سازی کش</button>
</div>
<script>
document.querySelectorAll('[data-action="clear-cache"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!window.Panel.confirmAction('آیا کش همه باشگاه‌ها باید پاک شود؟')) return;
        window.Panel.api('/panel/cache/clear', { method: 'POST' })
            .then(function () { window.Panel.toast('کش پاک شد.', 'success'); })
            .catch(function () {});
    });
});
</script>
<?php endif; ?>

<script>
(function () {
    var form = document.getElementById('settings-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {};
            new FormData(form).forEach(function (v, k) {
                if (k === 'settings') {
                    body[k] = {};
                    var m = v.match(/^settings\[(.+)\]$/);
                    if (m) { body[k][m[1]] = v; }
                } else { body[k] = v; }
            });
            window.Panel.api(form.getAttribute('action'), { method: 'POST', body: body })
                .then(function () { window.Panel.toast('تنظیمات ذخیره شد.', 'success'); })
                .catch(function () {});
        });
    }
})();
</script>
