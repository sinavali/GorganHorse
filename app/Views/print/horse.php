<?php
declare(strict_types=1);

/**
 * File: app/Views/print/horse.php
 *
 * Purpose: A4 print view for a horse profile.
 *
 * @var array  $view
 * @var array  $record
 * @var array  $images
 */
$record = $record ?? [];
$images = $images ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 8px;font-size:14pt"><?= e($record['name'] ?? '') ?></h2>
    <table class="print-table">
        <tr><td><strong>میکروچیپ</strong></td><td><?= e($record['microchip_number'] ?? '') ?></td></tr>
        <tr><td><strong>مالک</strong></td><td><?= e($record['owner_name'] ?? '') ?></td></tr>
        <tr><td><strong>جنسیت</strong></td><td><?= e($record['gender'] ?? '') ?></td></tr>
        <tr><td><strong>نژاد</strong></td><td><?= e($record['race'] ?? '') ?></td></tr>
        <tr><td><strong>رنگ</strong></td><td><?= e($record['color'] ?? '') ?></td></tr>
        <tr><td><strong>وضعیت</strong></td><td><?= e($record['status'] ?? '') ?></td></tr>
        <tr><td><strong>تاریخ تولد</strong></td><td><?= e($view['date']($record['birth_date'] ?? null)) ?></td></tr>
        <tr><td><strong>UELN</strong></td><td><?= e($record['ueln'] ?? '') ?></td></tr>
    </table>
</div>

<?php if (!empty($images)): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">تصاویر</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <?php foreach ($images as $img): ?>
            <img src="/assets/img/horses/<?= e($img['filename'] ?? '') ?>" style="width:100%;max-height:200px;object-fit:cover;border:1px solid #ccc">
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
