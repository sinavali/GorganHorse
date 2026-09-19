<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/audit.php
 *
 * Purpose: Audit log viewer. Admin only. (Blueprint §7.22).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">حسابرسی</h1>

<form method="get" action="/panel/audit" class="filters">
    <div class="field" style="margin:0">
        <label for="f-actor">بازیگر</label>
        <input id="f-actor" type="text" name="actor_id" value="<?= e($filters['actor_id'] ?? '') ?>">
    </div>
    <div class="field" style="margin:0">
        <label for="f-action">عمل</label>
        <input id="f-action" type="text" name="action" value="<?= e($filters['action'] ?? '') ?>" placeholder="user.create">
    </div>
    <div class="field" style="margin:0">
        <label for="f-from">از</label>
        <input id="f-from" type="text" name="from" value="<?= e($filters['from'] ?? '') ?>" placeholder="YYYY-MM-DD">
    </div>
    <div class="field" style="margin:0">
        <label for="f-to">تا</label>
        <input id="f-to" type="text" name="to" value="<?= e($filters['to'] ?? '') ?>" placeholder="YYYY-MM-DD">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<?php if (empty($rows)): ?>
    <div class="empty-state">هیچ ورود حسابرسی‌ای یافت نشد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>زمان</th>
                <th>بازیگر</th>
                <th>عمل</th>
                <th>هدف</th>
                <th>نتیجه</th>
                <th>جزئیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px"><?= e($view['date']($a['created_at'] ?? null)) ?></td>
                    <td><?= e($a['actor_name'] ?? '') ?></td>
                    <td><code><?= e($a['action'] ?? '') ?></code></td>
                    <td><?= e($a['target_type'] ?? '') ?> #<?= (int) ($a['target_id'] ?? 0) ?></td>
                    <td><span class="badge badge-green">ok</span></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer">مشاهده</summary>
                            <pre style="font-size:12px;overflow-x:auto"><?= e(json_encode($a['diff'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
