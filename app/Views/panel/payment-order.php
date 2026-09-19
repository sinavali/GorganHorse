<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/payment-order.php
 *
 * Purpose: Payment order detail page.
 *
 * @var array  $view
 * @var array  $record
 * @var string $csrf
 */
$record = $record ?? [];
?>
<h1 style="margin:0 0 16px">صورت‌حساب #<?= (int) ($record['id'] ?? 0) ?></h1>

<div class="card">
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <div class="kpi-label">سوارکار</div>
            <div class="kpi-value" style="font-size:16px"><?= e($record['rider_name'] ?? '') ?></div>
        </div>
        <div>
            <div class="kpi-label">مبلغ (تومان)</div>
            <div class="kpi-value" style="font-size:16px"><?= e($view['money']((int) ($record['amount_irt'] ?? 0))) ?></div>
        </div>
        <div>
            <div class="kpi-label">وضعیت</div>
            <div><span class="badge <?= ($record['status'] ?? '') === 'paid' ? 'badge-green' : 'badge-yellow' ?>"><?= e($record['status'] ?? '') ?></span></div>
        </div>
        <div>
            <div class="kpi-label">Authority</div>
            <div class="kpi-value" style="font-size:14px"><?= e($record['authority'] ?? '') ?></div>
        </div>
        <div>
            <div class="kpi-label">Ref ID</div>
            <div class="kpi-value" style="font-size:14px"><?= e($record['ref_id'] ?? '') ?></div>
        </div>
        <div>
            <div class="kpi-label">تاریخ تأسیس</div>
            <div><?= e($view['date']($record['created_at'] ?? null)) ?></div>
        </div>
        <div>
            <div class="kpi-label">تاریخ تایید</div>
            <div><?= e($view['date']($record['verified_at'] ?? null)) ?></div>
        </div>
    </div>
    <div class="toolbar" style="margin-block-start:16px">
        <a href="/panel/payment-orders/<?= (int) ($record['id'] ?? 0) ?>/print" class="btn">چاپ</a>
        <?php if (($record['status'] ?? '') === 'paid'): ?>
            <button class="btn btn-danger" data-action="mark-refund" data-id="<?= (int) ($record['id'] ?? 0) ?>">بازپرداخت</button>
        <?php elseif (($record['status'] ?? '') === 'pending_refund'): ?>
            <button class="btn" data-action="unmark-refund" data-id="<?= (int) ($record['id'] ?? 0) ?>">لغو بازپرداخت</button>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-action="mark-refund"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('بازپرداخت؟')) return;
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
})();
</script>
