<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/payment-orders.php
 *
 * Purpose: Payment orders list page with filters.
 * (Blueprint §7.16).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">صورت‌حساب‌ها</h1>

<form method="get" action="/panel/payment-orders" class="filters">
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'pending' => 'در انتظار', 'paid' => 'پرداخت شده', 'failed' => 'ناموفق', 'pending_refund' => 'در انتظار بازپرداخت', 'refunded' => 'بازپرداخت شده'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-rider">سوارکار</label>
        <input id="f-rider" type="text" name="rider_user_id" value="<?= e($filters['rider_user_id'] ?? '') ?>">
    </div>
    <div class="field" style="margin:0">
        <label for="f-ref">Ref ID</label>
        <input id="f-ref" type="text" name="ref_id" value="<?= e($filters['ref_id'] ?? '') ?>">
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<div class="toolbar">
    <button class="btn btn-primary" data-action="reconciliation">هم‌خوانی</button>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">صورت‌حسابی یافت نشد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>شماره</th>
                <th>سوارکار</th>
                <th>مسابقه</th>
                <th>رده</th>
                <th>مبلغ</th>
                <th>وضعیت</th>
                <th>Authority</th>
                <th>Ref ID</th>
                <th>تاریخ</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $o): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($o['id'] ?? 0) ?>"></td>
                    <td><a href="/panel/payment-orders/<?= (int) ($o['id'] ?? 0) ?>">#<?= (int) ($o['id'] ?? 0) ?></a></td>
                    <td><?= e($o['rider_name'] ?? '') ?></td>
                    <td><?= e($o['competition_title'] ?? '') ?></td>
                    <td><?= e($o['rade_name'] ?? '') ?></td>
                    <td><?= e($view['money']((int) ($o['amount_irt'] ?? 0))) ?></td>
                    <td><span class="badge <?= match ($o['status'] ?? '') { 'paid' => 'badge-green', 'pending' => 'badge-yellow', 'failed' => 'badge-red', 'pending_refund' => 'badge-yellow', 'refunded' => 'badge-gray', default => 'badge-gray' } ?>"><?= e($o['status'] ?? '') ?></span></td>
                    <td><code><?= e(substr($o['authority'] ?? '', 0, 16)) ?></code></td>
                    <td><?= e($o['ref_id'] ?? '') ?></td>
                    <td><?= e($view['date']($o['verified_at'] ?? $o['created_at'] ?? null)) ?></td>
                    <td>
                        <a class="btn" href="/panel/payment-orders/<?= (int) ($o['id'] ?? 0) ?>">مشاهده</a>
                        <?php if (in_array($o['status'] ?? '', ['paid', 'pending_refund'])): ?>
                            <?php if ($o['status'] === 'paid'): ?>
                                <button class="btn btn-danger" data-action="mark-refund" data-id="<?= (int) ($o['id'] ?? 0) ?>">بازپرداخت</button>
                            <?php else: ?>
                                <button class="btn" data-action="unmark-refund" data-id="<?= (int) ($o['id'] ?? 0) ?>">لغو بازپرداخت</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="bulk-bar" id="bulk-bar" style="display:none;margin-top:12px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
        <span id="bulk-count">0 مورد انتخاب شده</span>
        <button class="btn" data-bulk="mark_pending_refund">علامت‌گذاری بازپرداخت</button>
        <button class="btn" data-bulk="mark_refunded">تأیید بازپرداخت</button>
        <button class="btn" data-bulk="unmark_refund">لغو بازپرداخت</button>
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
    document.querySelectorAll('[data-action="mark-refund"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('علامت‌گذاری به بازپرداخت؟')) return;
            window.Panel.api('/panel/payment-orders/' + btn.getAttribute('data-id') + '/mark-pending-refund', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="unmark-refund"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/payment-orders/' + btn.getAttribute('data-id') + '/unmark-refund', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="reconciliation"]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.location.href = '/panel/payment-orders/reconciliation'; });
    });
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-bulk');
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!window.Panel.confirmAction('اعمال ' + btn.textContent.trim() + '؟')) return;
            window.Panel.api('/panel/payment-orders/bulk', { method: 'POST', body: { action: action, ids: ids } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
