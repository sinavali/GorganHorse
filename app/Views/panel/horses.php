<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/horses.php
 *
 * Purpose: Horse list page with filters, bulk actions, and import/export.
 * (Blueprint §7.6, User Usage §9.2).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 * @var string $role
 */
?>
<h1 style="margin:0 0 16px">اسبان</h1>

<form method="get" action="/panel/horses" class="filters">
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'active' => 'فعال', 'sold_to_non_rider' => 'فروخته به غیرسوارکار', 'soft_deleted' => 'حذف‌شده'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-gender">جنسیت</label>
        <select id="f-gender" name="gender">
            <option value="">همه</option>
            <?php foreach (['مادیان' => 'مادیان', 'نریان' => 'نریان', 'اخته' => 'اخته'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['gender'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-race">نژاد</label>
        <input id="f-race" type="text" name="race" value="<?= e($filters['race'] ?? '') ?>" placeholder="نژاد">
    </div>
    <div class="field" style="margin:0">
        <label for="f-microchip">میکروچیپ</label>
        <input id="f-microchip" type="text" name="microchip" value="<?= e($filters['microchip'] ?? '') ?>" data-numeric>
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<div class="toolbar">
    <a href="/panel/horses/create" class="btn btn-primary">افزودن اسب</a>
    <a href="/panel/horses/export-template" class="btn">الگوی واردات</a>
    <button class="btn" data-action="import-csv" data-csrf="<?= e($csrf) ?>">واردات CSV</button>
    <button class="btn" data-action="export-csv" data-csrf="<?= e($csrf) ?>">خروجی CSV</button>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">هنوز اسبی ثبت نکرده‌اید. <a href="/panel/horses/create">افزودن اسب</a></div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>نام</th>
                <th>میکروچیپ</th>
                <th>مالک</th>
                <th>جنسیت</th>
                <th>نژاد</th>
                <th>رنگ</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $h): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($h['id'] ?? 0) ?>" aria-label="انتخاب"></td>
                    <td><a href="/panel/horses/<?= (int) ($h['id'] ?? 0) ?>"><?= e($h['name'] ?? '') ?></a></td>
                    <td><?= e($h['microchip_number'] ?? '') ?></td>
                    <td><?= e($h['owner_name'] ?? '') ?></td>
                    <td><?= e($h['gender'] ?? '') ?></td>
                    <td><?= e($h['race'] ?? '') ?></td>
                    <td><?= e($h['color'] ?? '') ?></td>
                    <td>
                        <?php $status = $h['status'] ?? 'active'; ?>
                        <span class="badge <?= $status === 'active' ? 'badge-green' : ($status === 'sold_to_non_rider' ? 'badge-gray' : 'badge-red') ?>">
                            <?= e(match ($status) { 'active' => 'فعال', 'sold_to_non_rider' => 'فروخته', 'soft_deleted' => 'حذف‌شده', default => $status }) ?>
                        </span>
                    </td>
                    <td><?= e($view['date']($h['created_at'] ?? null)) ?></td>
                    <td>
                        <a class="btn" href="/panel/horses/<?= (int) ($h['id'] ?? 0) ?>">مشاهده</a>
                        <?php if ($role !== 'club'): ?>
                            <a class="btn" href="/panel/horses/<?= (int) ($h['id'] ?? 0) ?>/edit">ویرایش</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($role !== 'club'): ?>
        <div class="bulk-bar" id="bulk-bar" style="display:none;margin-top:12px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
            <span id="bulk-count">0 مورد انتخاب شده</span>
            <button class="btn" data-bulk="soft_delete">حذف نرم</button>
            <button class="btn" data-bulk="restore">بازگردانی</button>
            <button class="btn" data-bulk="export">خروجی CSV</button>
        </div>
    <?php endif; ?>
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
    document.querySelectorAll('.row-check').forEach(function (cb) {
        cb.addEventListener('change', updateBulkBar);
    });
    function updateBulkBar() {
        var checked = document.querySelectorAll('.row-check:checked');
        var bar = document.getElementById('bulk-bar');
        var count = document.getElementById('bulk-count');
        if (checked.length > 0) {
            bar.style.display = 'block';
            count.textContent = checked.length + ' مورد انتخاب شده';
        } else {
            bar.style.display = 'none';
        }
    }
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-bulk');
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!window.Panel.confirmAction('آیا برای ' + ids.length + ' مورد ' + btn.textContent.trim() + ' اعمال شود؟')) { return; }
            window.Panel.api('/panel/horses/bulk', { method: 'POST', body: { action: action, ids: ids } })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
    var importBtn = document.querySelector('[data-action="import-csv"]');
    if (importBtn) {
        importBtn.addEventListener('click', function () {
            var input = document.createElement('input');
            input.type = 'file'; input.accept = '.csv';
            input.onchange = function () {
                var form = new FormData(); form.append('file', input.files[0]);
                window.Panel.api('/panel/horses/import', { method: 'POST', body: form })
                    .then(function () { window.Panel.toast('واردات به اتمام رسید.', 'success'); })
                    .catch(function () {});
            };
            input.click();
        });
    }
    var exportBtn = document.querySelector('[data-action="export-csv"]');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            window.Panel.api('/panel/horses/export', { method: 'POST' })
                .then(function (res) { window.location.href = res.data.url; })
                .catch(function () {});
        });
    }
})();
</script>
