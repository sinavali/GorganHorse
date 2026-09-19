<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/rider-signups.php
 *
 * Purpose: Rider's own signup list. (Blueprint §9.7).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">ثبت‌نام‌های من</h1>

<?php if (empty($rows)): ?>
    <div class="empty-state">هنوز ثبت‌نامی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>مسابقه</th>
                <th>رده</th>
                <th>اسب</th>
                <th>باشگاه</th>
                <th>قیمت</th>
                <th>وضعیت</th>
                <th>مقام</th>
                <th>برنده</th>
                <th>تاریخ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td><?= e($s['competition_title'] ?? '') ?></td>
                    <td><?= e($s['rade_name'] ?? '') ?></td>
                    <td><?= e($s['horse_name'] ?? '') ?></td>
                    <td><?= e($s['club_name'] ?? '') ?></td>
                    <td><?= e($view['money']((int) ($s['price'] ?? 0))) ?></td>
                    <td><span class="badge <?= match ($s['status'] ?? '') { 'confirmed' => 'badge-green', 'paid' => 'badge-yellow', 'pending_payment' => 'badge-yellow', 'rejected' => 'badge-red', 'cancelled' => 'badge-red', 'withdrawn' => 'badge-gray', default => 'badge-gray' } ?>"><?= e($s['status'] ?? '') ?></span></td>
                    <td><?= e($s['position'] ?? '') ?></td>
                    <td><?= ($s['is_winner'] ?? 0) ? 'بله' : 'خیر' ?></td>
                    <td><?= e($view['date']($s['created_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
