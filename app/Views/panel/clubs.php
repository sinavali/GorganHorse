<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/clubs.php
 *
 * Purpose: Club list page with filters and bulk actions (Blueprint §7.4).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 * @var string $role
 */
?>
<h1 style="margin:0 0 16px">باشگاه‌ها</h1>

<form method="get" action="/panel/clubs" class="filters">
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'active' => 'فعال', 'inactive' => 'غیرفعال'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-city">شهر</label>
        <input id="f-city" type="text" name="city" value="<?= e($filters['city'] ?? '') ?>" placeholder="شهر">
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<div class="toolbar">
    <a href="/panel/clubs/create" class="btn btn-primary">ایجاد باشگاه</a>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">باشگاهی یافت نشد. <a href="/panel/clubs/create">ایجاد باشگاه</a></div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>نام</th>
                <th>شهر</th>
                <th>شخص تماس</th>
                <th>موبایل</th>
                <th>تعداد سوارکاران</th>
                <th>وضعیت</th>
                <th>تاریخ ایجاد</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($c['id'] ?? 0) ?>" aria-label="انتخاب"></td>
                    <td><a href="/panel/clubs/<?= (int) ($c['id'] ?? 0) ?>"><?= e($c['name'] ?? '') ?></a></td>
                    <td><?= e($c['city'] ?? '') ?></td>
                    <td><?= e($c['contact_person'] ?? '') ?></td>
                    <td><?= e($c['phone'] ?? '') ?></td>
                    <td><?= (int) ($c['affiliated_riders'] ?? 0) ?></td>
                    <td>
                        <?php $status = $c['status'] ?? 'active'; ?>
                        <span class="badge <?= $status === 'active' ? 'badge-green' : 'badge-gray' ?>">
                            <?= e($status === 'active' ? 'فعال' : 'غیرفعال') ?>
                        </span>
                    </td>
                    <td><?= e($view['date']($c['created_at'] ?? null)) ?></td>
                    <td>
                        <a class="btn" href="/panel/clubs/<?= (int) ($c['id'] ?? 0) ?>">مشاهده</a>
                        <?php if ($role === 'admin' || $role === 'manager'): ?>
                            <a class="btn" href="/panel/clubs/<?= (int) ($c['id'] ?? 0) ?>/edit">ویرایش</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($role === 'admin' || $role === 'manager'): ?>
        <div class="bulk-bar" id="bulk-bar" style="display:none;margin-top:12px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
            <span id="bulk-count">0 مورد انتخاب شده</span>
            <button class="btn" data-bulk="activate">فعال‌سازی</button>
            <button class="btn" data-bulk="deactivate">غیرفعال‌سازی</button>
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
            if (!window.Panel.confirmAction('آیا برای ' + ids.length + ' مورد انتخاب‌شده ' + btn.textContent.trim() + ' اعمال شود؟')) { return; }
            window.Panel.api('/panel/clubs/bulk', { method: 'POST', body: { action: action, ids: ids } })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
})();
</script>
