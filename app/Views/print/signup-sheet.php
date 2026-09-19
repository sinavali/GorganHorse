<?php
declare(strict_types=1);

/**
 * File: app/Views/print/signup-sheet.php
 *
 * Purpose: A4 print view for a competition signup sheet.
 *
 * @var array  $view
 * @var array  $competition
 * @var array  $rows
 */
$competition = $competition ?? [];
$rows = $rows ?? [];
?>
<div class="print-section">
    <h2 style="margin:0 0 8px;font-size:14pt">لیست ثبت‌نام — <?= e($competition['title'] ?? '') ?></h2>
    <p style="margin:0 0 12px;color:var(--muted)">
        <?= e($view['date']($competition['start_registration_at'] ?? null)) ?> — <?= e($view['date']($competition['end_registration_at'] ?? null)) ?>
    </p>

    <?php if (empty($rows)): ?>
        <p>هنوز ثبت‌نامی وجود ندارد.</p>
    <?php else: ?>
        <table class="print-table">
            <thead>
                <tr><th>ردیف</th><th>سوارکار</th><th>اسب</th><th>رده</th><th>باشگاه</th><th>وضعیت</th></tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($rows as $s): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= e($s['rider_name'] ?? '') ?></td>
                        <td><?= e($s['horse_name'] ?? '') ?></td>
                        <td><?= e($s['rade_name'] ?? '') ?></td>
                        <td><?= e($s['club_name'] ?? '') ?></td>
                        <td><span class="badge badge-green"><?= e($s['status'] ?? '') ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
