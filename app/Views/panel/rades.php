<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/rades.php
 *
 * Purpose: Rade list page. (Blueprint §7.8).
 *
 * @var array  $view
 * @var array  $rows
 * @var array  $filters
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">رده‌ها</h1>

<form method="get" action="/panel/rades" class="filters">
    <div class="field" style="margin:0">
        <label for="f-active">وضعیت</label>
        <select id="f-active" name="active">
            <?php foreach (['all' => 'همه', '1' => 'فعال', '0' => 'غیرفعال'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['active'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<div class="toolbar">
    <a href="/panel/rades/create" class="btn btn-primary">افزودن رده</a>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">رده‌ای یافت نشد. <a href="/panel/rades/create">افزودن رده</a></div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>نام</th>
                <th>دامنه سنی</th>
                <th>سن اجباری</th>
                <th>ترتیب</th>
                <th>فعال</th>
                <th>تاریخ ایجاد</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($r['id'] ?? 0) ?>" aria-label="انتخاب"></td>
                    <td><a href="/panel/rades/<?= (int) ($r['id'] ?? 0) ?>"><?= e($r['name'] ?? '') ?></a></td>
                    <td><?= e($r['age_min'] ?? '') ?> - <?= e($r['age_max'] ?? '') ?></td>
                    <td><?= ($r['age_enforced'] ?? 0) ? 'بله' : 'خیر' ?></td>
                    <td><?= (int) ($r['sort_order'] ?? 0) ?></td>
                    <td><span class="badge <?= ($r['active'] ?? 0) ? 'badge-green' : 'badge-gray' ?>"><?= ($r['active'] ?? 0) ? 'فعال' : 'غیرفعال' ?></span></td>
                    <td><?= e($view['date']($r['created_at'] ?? null)) ?></td>
                    <td><a class="btn" href="/panel/rades/<?= (int) ($r['id'] ?? 0) ?>/edit">ویرایش</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="bulk-bar" id="bulk-bar" style="display:none;margin-top:12px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
        <span id="bulk-count">0 مورد انتخاب شده</span>
        <button class="btn" data-bulk="activate">فعال‌سازی</button>
        <button class="btn" data-bulk="deactivate">غیرفعال‌سازی</button>
        <button class="btn" data-bulk="export">خروجی CSV</button>
    </div>
<?php endif; ?>

<script>
(function () {
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBulkBar();
        });
    }
    document.querySelectorAll('.row-check').forEach(function (cb) { cb.addEventListener('change', updateBulkBar); });
    function updateBulkBar() {
        var checked = document.querySelectorAll('.row-check:checked');
        var bar = document.getElementById('bulk-bar');
        var count = document.getElementById('bulk-count');
        if (checked.length > 0) { bar.style.display = 'block'; count.textContent = checked.length + ' مورد'; }
        else { bar.style.display = 'none'; }
    }
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-bulk');
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!window.Panel.confirmAction('اعمال ' + btn.textContent.trim() + ' بر ' + ids.length + ' مورد؟')) return;
            window.Panel.api('/panel/rades/bulk', { method: 'POST', body: { action: action, ids: ids } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
