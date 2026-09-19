<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/horse-shares.php
 *
 * Purpose: Horse shares inbox - horses shared to the current rider.
 * (Blueprint §9.3, User Usage §9.3).
 *
 * @var array  $view
 * @var array  $rows
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">اشتراک‌های دریافتی</h1>

<?php if (empty($rows)): ?>
    <div class="empty-state">
        <p>هیچ اسبی به اشتراک گذاشته نشده.</p>
    </div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>نام اسب</th>
                <th>مالک</th>
                <th>کد اشتراک</th>
                <th>وضعیت</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td><a href="/panel/horses/<?= (int) ($s['horse_id'] ?? 0) ?>"><?= e($s['horse_name'] ?? '') ?></a></td>
                    <td><?= e($s['owner_name'] ?? '') ?></td>
                    <td><code><?= e($s['share_code'] ?? '') ?></code></td>
                    <td><span class="badge badge-green">فعال</span></td>
                    <td>
                        <button class="btn btn-primary" data-action="accept-share" data-share-id="<?= (int) ($s['id'] ?? 0) ?>">پذیرش</button>
                        <button class="btn btn-danger" data-action="reject-share" data-share-id="<?= (int) ($s['id'] ?? 0) ?>">رد</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-action="accept-share"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('آیا این اشتراک را پذیرفتید؟')) return;
            window.Panel.api('/panel/horse-shares/' + btn.getAttribute('data-share-id') + '/accept', { method: 'POST' })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="reject-share"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('آیا این اشتراک را رد کنید؟')) return;
            window.Panel.api('/panel/horse-shares/' + btn.getAttribute('data-share-id') + '/reject', { method: 'POST' })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
})();
</script>
