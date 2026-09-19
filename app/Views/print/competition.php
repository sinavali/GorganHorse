<?php
declare(strict_types=1);

/**
 * File: app/Views/print/competition.php
 *
 * Purpose: A4 print view for a competition paper.
 * (Blueprint §17.1, §27).
 *
 * @var array  $view
 * @var array  $record
 * @var array  $rades
 * @var string $title
 */
$record = $record ?? [];
$rades = $rades ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 8px;font-size:14pt"><?= e($record['title'] ?? '') ?></h2>
    <table class="print-table">
        <tr><td><strong>شروع ثبت‌نام</strong></td><td><?= e($view['date']($record['start_registration_at'] ?? null)) ?></td></tr>
        <tr><td><strong>پایان ثبت‌نام</strong></td><td><?= e($view['date']($record['end_registration_at'] ?? null)) ?></td></tr>
        <tr><td><strong>تاریخ مسابقه</strong></td><td><?= e($view['date']($record['start_at'] ?? null)) ?></td></tr>
        <tr><td><strong>محل</strong></td><td><?= e($record['venue_name'] ?? '') ?></td></tr>
        <tr><td><strong>وضعیت</strong></td><td><span class="badge badge-green"><?= e($record['status'] ?? '') ?></span></td></tr>
        <tr><td><strong>نتایج</strong></td><td><?= e($record['results_status'] ?? '') ?></td></tr>
    </table>
</div>

<?php if (!empty($rades)): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">رده‌ها</h2>
    <table class="print-table">
        <thead>
            <tr><th>رده</th><th>قیمت</th><th>ظرفیت</th><th>تأیید خودکار</th><th>باراژ</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rades as $r): ?>
                <tr>
                    <td><?= e($r['name'] ?? '') ?></td>
                    <td><?= e($view['money']((int) ($r['price_irt'] ?? 0))) ?></td>
                    <td><?= (int) ($r['capacity'] ?? 0) ?></td>
                    <td><?= (!empty($r['auto_confirm'])) ? 'بله' : 'خیر' ?></td>
                    <td><?= (!empty($r['had_barrage'])) ? 'بله' : 'خیر' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($record['rules'] ?? '')): ?>
<div class="print-section" style="margin-block-start:16px">
    <h2 style="margin:0 0 8px;font-size:12pt">قوانین</h2>
    <p style="margin:0"><?= e($record['rules'] ?? '') ?></p>
</div>
<?php endif; ?>
