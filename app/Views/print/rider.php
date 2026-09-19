<?php
declare(strict_types=1);

/**
 * File: app/Views/print/rider.php
 *
 * Purpose: A4 print view for a rider profile.
 *
 * @var array  $view
 * @var array  $record
 */
$record = $record ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 8px;font-size:14pt"><?= e(trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''))) ?></h2>
    <table class="print-table">
        <tr><td><strong>نام کاربری</strong></td><td><?= e($record['username'] ?? '') ?></td></tr>
        <tr><td><strong>موبایل</strong></td><td><?= e($record['phone'] ?? '') ?></td></tr>
        <tr><td><strong>ایمیل</strong></td><td><?= e($record['email'] ?? '') ?></td></tr>
        <tr><td><strong>کد ملی</strong></td><td><?= e($record['national_id'] ?? '') ?></td></tr>
        <tr><td><strong>نقش</strong></td><td><?= e($record['role'] ?? '') ?></td></tr>
        <tr><td><strong>وضعیت تایید</strong></td><td><?= e($record['verification_status'] ?? '') ?></td></tr>
        <tr><td><strong>تاریخ عضویت</strong></td><td><?= e($view['date']($record['created_at'] ?? null)) ?></td></tr>
    </table>
</div>

<?php if (!empty($record['address'] ?? '')): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">آدرس</h2>
    <p style="margin:0"><?= e($record['address'] ?? '') ?></p>
</div>
<?php endif; ?>

<?php if (!empty($record['bio'] ?? '')): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">بیوگرافی</h2>
    <p style="margin:0;white-space:pre-wrap"><?= e($record['bio'] ?? '') ?></p>
</div>
<?php endif; ?>
