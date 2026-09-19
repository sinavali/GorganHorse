<?php
declare(strict_types=1);

/**
 * File: app/Views/print/payment.php
 *
 * Purpose: A4 print view for a payment template or order.
 *
 * @var array  $view
 * @var array  $record
 */
$record = $record ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 8px;font-size:14pt"><?= e($record['name'] ?? '') ?></h2>
    <table class="print-table">
        <?php if (!empty($record['slug'] ?? '')): ?>
            <tr><td><strong>نامک</strong></td><td><?= e($record['slug'] ?? '') ?></td></tr>
        <?php endif; ?>
        <tr><td><strong>مبلغ (تومان)</strong></td><td><?= e($view['money']((int) ($record['amount_irt'] ?? 0))) ?></td></tr>
        <tr><td><strong>وضعیت</strong></td><td><span class="badge badge-green"><?= e($record['status'] ?? $record['active'] ?? 'active') ?></span></td></tr>
        <?php if (!empty($record['description'] ?? '')): ?>
            <tr><td><strong>توضیحات</strong></td><td><?= e($record['description'] ?? '') ?></td></tr>
        <?php endif; ?>
    </table>
</div>
<?php if (!empty($record['authority'] ?? '')): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">جزئیات پرداخت</h2>
    <table class="print-table">
        <tr><td><strong>Authority</strong></td><td><?= e($record['authority'] ?? '') ?></td></tr>
        <tr><td><strong>Ref ID</strong></td><td><?= e($record['ref_id'] ?? '') ?></td></tr>
        <tr><td><strong>شماره سوارکار</strong></td><td><?= e($record['rider_name'] ?? '') ?></td></tr>
        <tr><td><strong>تاریخ تایید</strong></td><td><?= e($view['date']($record['verified_at'] ?? null)) ?></td></tr>
    </table>
</div>
<?php endif; ?>
