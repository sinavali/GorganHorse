<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/dashboard.php
 *
 * @var array  $view
 * @var string $role
 * @var array  $kpi
 * @var array  $user
 * @var string $csrf
 */
$kpi = is_array($kpi) ? $kpi : [];
$card = static function (string $label, mixed $value, string $fmt = 'num') use ($view): void {
    $display = match ($fmt) {
        'money' => $value === null ? '—' : $view['money']((int) $value),
        'date' => $value === null ? '—' : $view['date']((string) $value),
        default => $value === null ? '—' : $view['num']((int) $value),
    };
    echo '<div class="card"><div class="kpi-label">' . e($label) . '</div>'
        . '<div class="kpi-value">' . e($display) . '</div></div>';
};
?>
<h1 style="margin:0 0 16px">داشبورد</h1>

<?php if ($role === 'admin' || $role === 'manager'): ?>
    <div class="grid grid-3">
        <?php
        $card('کل سوارکاران', $kpi['total_riders'] ?? 0);
        $card('کل اسبان', $kpi['total_horses'] ?? 0);
        $card('کل باشگاه‌ها', $kpi['total_clubs'] ?? 0);
        $card('مسابقات فعال', $kpi['active_competitions'] ?? 0);
        $card('درآمد این ماه', $kpi['revenue_month'] ?? 0, 'money');
        $card('تاییدهای در انتظار', $kpi['pending_verifications'] ?? 0);
        ?>
    </div>
<?php elseif ($role === 'rider'): ?>
    <div class="grid grid-3">
        <?php
        $card('اسبان من', $kpi['my_horses'] ?? 0);
        $card('ثبت‌نام‌های در انتظار', $kpi['my_signups_pending'] ?? 0);
        $card('ثبت‌نام‌های تایید‌شده', $kpi['my_signups_confirmed'] ?? 0);
        $card('مقام‌های اول', $kpi['my_wins'] ?? 0);
        $card('مسابقات پیش‌رو', $kpi['upcoming_competitions'] ?? 0);
        $card('اشتراک‌های در انتظار', $kpi['pending_shares'] ?? 0);
        ?>
    </div>
    <?php if (!empty($kpi['my_horses_list'])): ?>
        <h2 style="font-size:16px;margin:24px 0 8px">اسبان من</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>نام</th>
                    <th>میکروچیپ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kpi['my_horses_list'] as $h): ?>
                    <tr>
                        <td><?= e($h['name'] ?? '') ?></td>
                        <td><?= e($h['microchip_number'] ?? '—') ?></td>
                        <td><a href="/panel/horses/<?= (int) ($h['id'] ?? 0) ?>" class="btn">مشاهده</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php elseif ($role === 'club'): ?>
    <div class="grid grid-3">
        <?php
        $card('مسابقات در محل باشگاه', $kpi['venue_competitions'] ?? 0);
        $card('سوارکاران وابسته', $kpi['affiliated_riders'] ?? 0);
        $card('درآمد این ماه', $kpi['revenue_month'] ?? 0, 'money');
        $card('تحریم‌های فعال', $kpi['active_bans'] ?? 0);
        ?>
    </div>
<?php endif; ?>