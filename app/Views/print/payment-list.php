<?php
declare(strict_types=1);

/**
 * File: app/Views/print/payment-list.php
 *
 * Purpose: A4 print view for a list of payment templates.
 *
 * @var array  $view
 * @var array  $rows
 */
$rows = $rows ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 12px;font-size:14pt">فهرست الگوهای پرداخت</h2>
    <table class="print-table">
        <thead>
            <tr><th>ردیف</th><th>نام</th><th>نامک</th><th>مبلغ</th><th>وضعیت</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($p['name'] ?? '') ?></td>
                    <td><?= e($p['slug'] ?? '') ?></td>
                    <td><?= e($view['money']((int) ($p['amount_irt'] ?? 0))) ?></td>
                    <td><span class="badge badge-green"><?= e(($p['active'] ?? 1) ? 'فعال' : 'غیرفعال') ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
