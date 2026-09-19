<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/reports.php
 *
 * Purpose: Unified report engine page with presets sidebar and grid.
 * (Blueprint §13, User Usage §7.18).
 *
 * @var array  $view
 * @var array  $presets
 * @var string $report
 * @var int    $max_export_rows
 * @var string $csrf
 */
$report = $report ?? 'signups';
?>
<h1 style="margin:0 0 16px">گزارش‌ها</h1>

<div class="panel-shell" style="grid-template-columns:200px 1fr;gap:0">
    <aside style="background:#f8fafc;padding:16px;border-inline-end:1px solid var(--line)">
        <h2 style="font-size:13px;color:var(--muted);margin:0 0 8px">پیش‌تنظیم‌ها</h2>
        <?php foreach ($presets as $p): ?>
            <a href="/panel/reports?report=<?= e($p['key'] ?? '') ?>" class="btn" style="display:block;margin-block-end:4px;width:100%;text-align:start;justify-content:start;border:none;background:none;padding:8px 12px;font-size:13px"
                <?= ($report ?? '') === ($p['key'] ?? '') ? 'style="background:var(--brand-primary);color:#fff"' : '' ?>>
                <?= e($p['label'] ?? '') ?>
            </a>
        <?php endforeach; ?>
    </aside>
    <div class="panel-content">
        <div class="toolbar" style="margin-block-end:16px">
            <button class="btn" data-action="export-xlsx">XLSX</button>
            <button class="btn" data-action="export-csv">CSV</button>
            <button class="btn" data-action="share">اشتراک‌گذاری</button>
            <button class="btn" data-action="print">چاپ</button>
        </div>
        <div id="report-grid"></div>
        <p style="color:var(--muted);font-size:12px;margin-block-start:12px">
            حداکثر خروجی: <?= $max_export_rows ?> سطر. وضعیت ستون‌ها و ترتیب آن‌ها ذخیره می‌شود.
        </p>
    </div>
</div>

<!-- Share Modal -->
<div id="share-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:100">
    <div class="auth-card" style="max-width:400px;width:90%">
        <h2 style="margin:0 0 16px;font-size:18px">اشتراک‌گذاری گزارش</h2>
        <form id="share-form" method="post" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="field">
                <label for="share-to">گیرنده</label>
                <input id="share-to" type="text" name="shared_to_user_id" placeholder="جست‌وجوی کاربر">
            </div>
            <input type="hidden" name="report" value="<?= e($report) ?>">
            <div class="toolbar">
                <button type="submit" class="btn btn-primary">اشتراک‌گذاری</button>
                <button type="button" class="btn" data-action="close-share">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-action="export-xlsx"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/reports/export', { method: 'POST', body: { report: '<?= e($report) ?>', format: 'xlsx' } })
                .then(function (res) { window.location.href = res.data.url; }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="export-csv"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/reports/export', { method: 'POST', body: { report: '<?= e($report) ?>', format: 'csv' } })
                .then(function (res) { window.location.href = res.data.url; }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="share"]').forEach(function (btn) {
        btn.addEventListener('click', function () { document.getElementById('share-modal').style.display = 'flex'; });
    });
    document.querySelectorAll('[data-action="close-share"]').forEach(function (btn) {
        btn.addEventListener('click', function () { document.getElementById('share-modal').style.display = 'none'; });
    });
    document.querySelectorAll('[data-action="print"]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });
})();
</script>
