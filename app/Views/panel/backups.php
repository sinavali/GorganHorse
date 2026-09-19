<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/backups.php
 *
 * Purpose: Backup management page. Admin only. (Blueprint §7.23).
 *
 * @var array  $view
 * @var array  $rows
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">پشتیبان‌گیری</h1>

<div class="toolbar">
    <button class="btn btn-primary" data-action="create-backup">ایجاد پشتیبان</button>
    <button class="btn" data-action="seed-demo">بذر داده نمونه</button>
    <button class="btn" data-action="clear-demo">پاک‌سازی داده نمونه</button>
    <button class="btn btn-danger" data-action="reset">ریست کردن</button>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">هیچ پشتیبانی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>نام</th>
                <th>حجم</th>
                <th>تاریخ ایجاد</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $b): ?>
                <tr>
                    <td><strong><?= e($b['name'] ?? '') ?></strong></td>
                    <td><?= e($b['size_human'] ?? '') ?></td>
                    <td><?= e($view['date']($b['created_at'] ?? null)) ?></td>
                    <td>
                        <a class="btn" href="/panel/backups/<?= e($b['name'] ?? '') ?>/download">دانلود</a>
                        <button class="btn btn-primary" data-action="restore" data-name="<?= e($b['name'] ?? '') ?>">بازگردانی</button>
                        <button class="btn btn-danger" data-action="delete" data-name="<?= e($b['name'] ?? '') ?>">حذف</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-action="create-backup"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('ایجاد پشتیبان؟')) return;
            window.Panel.api('/panel/backups', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="restore"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.getAttribute('data-name');
            if (!window.Panel.confirmTyped(name)) return;
            if (!window.Panel.confirmAction('آیا مطمئن هستید که می‌خواهید بازگردانید؟')) return;
            window.Panel.api('/panel/backups/' + encodeURIComponent(name) + '/restore', { method: 'POST' })
                .then(function () { window.Panel.toast('بازگردانی در حال انجام است.', 'success'); })
                .catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="delete"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.getAttribute('data-name');
            if (!window.Panel.confirmAction('حذف پشتیبان: ' + name + '?')) return;
            window.Panel.api('/panel/backups/' + encodeURIComponent(name), { method: 'DELETE' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="seed-demo"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('ایجاد داده‌های نمونه؟')) return;
            window.Panel.api('/panel/demo/seed', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="clear-demo"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('پاک‌سازی داده‌های نمونه؟')) return;
            window.Panel.api('/panel/demo/clear', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="reset"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmTyped('RESET')) return;
            if (!window.Panel.confirmAction('⚠️ آیا مطمئن هستید که می‌خواهید کل سیستم را ریست کنید؟')) return;
            window.Panel.api('/panel/reset', { method: 'POST' })
                .then(function () { window.Panel.toast('ریست در حال انجام است.', 'success'); })
                .catch(function () {});
        });
    });
})();
</script>
