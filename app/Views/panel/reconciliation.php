<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/reconciliation.php
 *
 * Purpose: Payment reconciliation page — upload ZarinPal CSV report.
 *
 * @var array  $view
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">هم‌خوانی صورت‌حساب‌ها</h1>

<div class="card" style="max-width:600px">
    <h2 style="font-size:16px;margin:0 0 12px">آپلود گزارش زرین‌پال</h2>
    <p style="color:var(--muted);font-size:13px;margin:0 0 16px">
        آپلود فایل CSV خروجی زرین‌پال برای مقایسه با صورت‌حساب‌های محلی.
    </p>
    <form id="recon-form" method="post" action="/panel/payment-orders/reconciliation" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="recon-file" class="required">فایل CSV</label>
            <input id="recon-file" type="file" name="file" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">هم‌خوانی</button>
    </form>
</div>

<?php if (!empty($result ?? [])): ?>
<div class="card" style="margin-block-start:16px">
    <h2 style="font-size:16px;margin:0 0 12px">نتیجه هم‌خوانی</h2>
    <table class="data">
        <thead>
            <tr><th>ورودی</th><th>وضعیت</th><th>تفاوت</th></tr>
        </thead>
        <tbody>
            <?php foreach ($result['mismatches'] ?? [] as $m): ?>
                <tr>
                    <td><?= e($m['authority'] ?? '') ?></td>
                    <td><span class="badge badge-red">مشکل</span></td>
                    <td><?= e($m['detail'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($result['matches'] ?? [] as $m): ?>
                <tr>
                    <td><?= e($m['authority'] ?? '') ?></td>
                    <td><span class="badge badge-green">تطبیق</span></td>
                    <td>—</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
(function () {
    var form = document.getElementById('recon-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = new FormData(form);
            window.Panel.api('/panel/payment-orders/reconciliation', { method: 'POST', body: body })
                .then(function (res) { window.location.reload(); })
                .catch(function () {});
        });
    }
})();
</script>
