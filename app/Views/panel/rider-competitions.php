<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/rider-competitions.php
 *
 * Purpose: Rider's competition browser page.
 * (Blueprint §9.4).
 *
 * @var array  $view
 * @var array  $rows
 * @var bool   $pending
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">مسابقات</h1>

<?php if ($pending): ?>
    <div class="banner banner-pending">برای ثبت‌نام، حساب شما باید توسط مدیر تایید شود.</div>
<?php endif; ?>

<form method="get" action="/panel/rider/competitions" class="filters">
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
</form>

<?php if (empty($rows)): ?>
    <div class="empty-state">هیچ مسابقه‌ای یافت نشد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>عنوان</th>
                <th>محل</th>
                <th>پنجره ثبت‌نام</th>
                <th>تاریخ شروع</th>
                <th>تعداد رده</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><?= e($c['title'] ?? '') ?></td>
                    <td><?= e($c['venue_name'] ?? '') ?></td>
                    <td><?= e($view['date']($c['start_registration_at'] ?? null)) ?> - <?= e($view['date']($c['end_registration_at'] ?? null)) ?></td>
                    <td><?= e($view['date']($c['start_at'] ?? null)) ?></td>
                    <td><?= (int) ($c['rade_count'] ?? 0) ?></td>
                    <td><a class="btn btn-primary" href="/panel/rider/competitions/<?= (int) ($c['id'] ?? 0) ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
