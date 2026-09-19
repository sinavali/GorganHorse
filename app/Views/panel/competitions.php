<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/competitions.php
 *
 * Purpose: Competition list page with filters. (Blueprint §7.12).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">مسابقات</h1>

<form method="get" action="/panel/competitions" class="filters">
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'draft' => 'پیش‌نویس', 'open' => 'باز', 'closed' => 'بسته', 'running' => 'در حال اجرا', 'finished' => 'تمام', 'cancelled' => 'لغو شده'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-venue">محل برگزاری</label>
        <input id="f-venue" type="text" name="venue_club_id" value="<?= e($filters['venue_club_id'] ?? '') ?>">
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<div class="toolbar">
    <a href="/panel/competitions/create" class="btn btn-primary">ایجاد مسابقه</a>
    <button class="btn" data-action="calendar">نمایش تقویم</button>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">مسابقه‌ای یافت نشد. <a href="/panel/competitions/create">ایجاد مسابقه</a></div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>عنوان</th>
                <th>محل</th>
                <th>پنجره ثبت‌نام</th>
                <th>تاریخ شروع</th>
                <th>تعداد رده</th>
                <th>تعداد ثبت‌نام</th>
                <th>وضعیت</th>
                <th>نتایج</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($c['id'] ?? 0) ?>"></td>
                    <td><a href="/panel/competitions/<?= (int) ($c['id'] ?? 0) ?>"><?= e($c['title'] ?? '') ?></a></td>
                    <td><?= e($c['venue_name'] ?? '') ?></td>
                    <td><?= e($view['date']($c['start_registration_at'] ?? null)) ?> - <?= e($view['date']($c['end_registration_at'] ?? null)) ?></td>
                    <td><?= e($view['date']($c['start_at'] ?? null)) ?></td>
                    <td><?= (int) ($c['rade_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['signup_count'] ?? 0) ?></td>
                    <td><span class="badge <?= match ($c['status'] ?? '') { 'open' => 'badge-green', 'draft' => 'badge-yellow', 'closed' => 'badge-gray', 'running' => 'badge-green', 'finished' => 'badge-gray', 'cancelled' => 'badge-red', default => 'badge-gray' } ?>"><?= e($c['status'] ?? '') ?></span></td>
                    <td><span class="badge <?= ($c['results_status'] ?? 'draft') === 'published' ? 'badge-green' : ($c['results_status'] === 'confirmed' ? 'badge-yellow' : 'badge-gray') ?>"><?= e($c['results_status'] ?? 'draft') ?></span></td>
                    <td><a class="btn" href="/panel/competitions/<?= (int) ($c['id'] ?? 0) ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="bulk-bar" id="bulk-bar" style="display:none;margin-top:12px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
        <span id="bulk-count">0 مورد انتخاب شده</span>
        <button class="btn" data-bulk="publish">انتشار</button>
        <button class="btn btn-danger" data-bulk="cancel">لغو</button>
        <button class="btn" data-bulk="export">خروجی CSV</button>
    </div>
<?php endif; ?>

<script>
(function () {
    var selectAll = document.getElementById('select-all');
    if (selectAll) selectAll.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = selectAll.checked; });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(function (cb) { cb.addEventListener('change', updateBulkBar); });
    function updateBulkBar() {
        var checked = document.querySelectorAll('.row-check:checked');
        var bar = document.getElementById('bulk-bar');
        if (checked.length > 0) { bar.style.display = 'block'; document.getElementById('bulk-count').textContent = checked.length + ' مورد'; }
        else { bar.style.display = 'none'; }
    }
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-bulk');
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!window.Panel.confirmAction('اعمال ' + btn.textContent.trim() + '؟')) return;
            window.Panel.api('/panel/competitions/bulk', { method: 'POST', body: { action: action, ids: ids } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    var calBtn = document.querySelector('[data-action="calendar"]');
    if (calBtn) calBtn.addEventListener('click', function () { window.Panel.toast('نمایش تقویم به‌زودی در دسترس خواهد بود.', 'info'); });
})();
</script>
