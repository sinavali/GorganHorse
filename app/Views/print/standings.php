<?php
declare(strict_types=1);

/**
 * File: app/Views/print/standings.php
 *
 * Purpose: A4 print view for standings.
 *
 * @var array  $view
 * @var array  $rows
 * @var string $title
 */
$rows = $rows ?? [];
$title = $title ?? 'رده‌بندی';
?>
<div class="print-section">
    <h2 style="margin:0 0 12px;font-size:14pt"><?= e($title) ?></h2>
    <?php if (empty($rows)): ?>
        <p>هنوز رده‌بندی‌ای یافت نشد.</p>
    <?php else: ?>
        <table class="print-table">
            <thead>
                <tr><th>رتبه</th><th>نام</th><th>مسابقه</th><th>رده</th><th>مقام</th><th>برنده</th></tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= e($r['name'] ?? $r['rider_name'] ?? '') ?></td>
                        <td><?= e($r['competition_title'] ?? '') ?></td>
                        <td><?= e($r['rade_name'] ?? '') ?></td>
                        <td><?= e($r['position'] ?? '') ?></td>
                        <td><?= ($r['is_winner'] ?? 0) ? 'بله' : 'خیر' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
