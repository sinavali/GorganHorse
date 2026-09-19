<?php
declare(strict_types=1);

/**
 * File: app/Views/print/club.php
 *
 * Purpose: A4 print view for a club profile.
 *
 * @var array  $view
 * @var array  $record
 */
$record = $record ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 8px;font-size:14pt"><?= e($record['name'] ?? '') ?></h2>
    <table class="print-table">
        <tr><td><strong>شهر</strong></td><td><?= e($record['city'] ?? '') ?></td></tr>
        <tr><td><strong>استان</strong></td><td><?= e($record['province'] ?? '') ?></td></tr>
        <tr><td><strong>آدرس</strong></td><td><?= e($record['address'] ?? '') ?></td></tr>
        <tr><td><strong>موبایل</strong></td><td><?= e($record['phone'] ?? '') ?></td></tr>
        <tr><td><strong>ایمیل</strong></td><td><?= e($record['email'] ?? '') ?></td></tr>
        <tr><td><strong>شخص تماس</strong></td><td><?= e($record['contact_person'] ?? '') ?></td></tr>
        <tr><td><strong>وضعیت</strong></td><td><span class="badge badge-green"><?= e($record['status'] ?? '') ?></span></td></tr>
    </table>
</div>

<?php if (!empty($record['description'] ?? '')): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">توضیحات</h2>
    <p style="margin:0;white-space:pre-wrap"><?= e($record['description'] ?? '') ?></p>
</div>
<?php endif; ?>
