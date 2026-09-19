<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/signups.php
 *
 * Purpose: Signup list page for staff. (Blueprint §7.15).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">ثبت‌نام‌ها</h1>

<form method="get" action="/panel/signups" class="filters">
    <div class="field" style="margin:0">
        <label for="f-competition">مسابقه</label>
        <select id="f-competition" name="competition_id">
            <option value="">همه</option>
            <?php foreach ($filters['competition_options'] ?? [] as $opt): ?>
                <option value="<?= (int) ($opt['id'] ?? 0) ?>" <?= ($filters['competition_id'] ?? 0) == ($opt['id'] ?? 0) ? 'selected' : '' ?>><?= e($opt['name'] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-rade">رده</label>
        <select id="f-rade" name="rade_id">
            <option value="">همه</option>
            <?php foreach ($filters['rade_options'] ?? [] as $opt): ?>
                <option value="<?= (int) ($opt['id'] ?? 0) ?>" <?= ($filters['rade_id'] ?? 0) == ($opt['id'] ?? 0) ? 'selected' : '' ?>><?= e($opt['name'] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'confirmed' => 'تایید شده', 'rejected' => 'رد شده', 'cancelled' => 'لغو شده', 'withdrawn' => 'انصراف'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
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
    <button class="btn" data-action="bulk-confirm">تایید انتخاب</button>
    <button class="btn btn-danger" data-action="bulk-reject">رد انتخاب</button>
    <button class="btn" data-action="export">خروجی CSV</button>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">ثبت‌نامی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>سوارکار</th>
                <th>اسب</th>
                <th>مسابقه</th>
                <th>رده</th>
                <th>باشگاه</th>
                <th>مبلغ</th>
                <th>وضعیت</th>
                <th>تأیید</th>
                <th>مقام</th>
                <th>برنده</th>
                <th>تاریخ</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($s['id'] ?? 0) ?>"></td>
                    <td><a href="/panel/users/<?= (int) ($s['rider_id'] ?? 0) ?>"><?= e($s['rider_name'] ?? '') ?></a></td>
                    <td><?= e($s['horse_name'] ?? '') ?></td>
                    <td><?= e($s['competition_title'] ?? '') ?></td>
                    <td><?= e($s['rade_name'] ?? '') ?></td>
                    <td><?= e($s['club_name'] ?? '') ?></td>
                    <td><?= e($view['money']((int) ($s['payment_amount_irt'] ?? 0))) ?></td>
                    <td><span class="badge <?= match ($s['status'] ?? '') { 'confirmed' => 'badge-green', 'paid' => 'badge-yellow', 'pending_payment' => 'badge-yellow', 'rejected' => 'badge-red', 'cancelled' => 'badge-red', 'withdrawn' => 'badge-gray', default => 'badge-gray' } ?>"><?= e($s['status'] ?? '') ?></span></td>
                    <td><?= ($s['is_confirmed'] ?? 0) ? 'بله' : 'خیر' ?></td>
                    <td><?= e($s['position'] ?? '') ?></td>
                    <td><?= ($s['is_winner'] ?? 0) ? 'بله' : 'خیر' ?></td>
                    <td><?= e($view['date']($s['created_at'] ?? null)) ?></td>
                    <td><a class="btn" href="/panel/signups/<?= (int) ($s['id'] ?? 0) ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
(function () {
    var selectAll = document.getElementById('select-all');
    if (selectAll) selectAll.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = selectAll.checked; });
    });
    document.querySelectorAll('[data-action="bulk-confirm"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!ids.length || !window.Panel.confirmAction('تایید ' + ids.length + ' ثبت‌نام؟')) return;
            window.Panel.api('/panel/signups/bulk', { method: 'POST', body: { action: 'confirm', ids: ids } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="bulk-reject"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!ids.length || !window.Panel.confirmAction('رد ' + ids.length + ' ثبت‌نام؟')) return;
            window.Panel.api('/panel/signups/bulk', { method: 'POST', body: { action: 'reject', ids: ids } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
