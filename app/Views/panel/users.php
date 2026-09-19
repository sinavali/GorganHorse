<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/users.php
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 * @var string $role
 */
?>
<h1 style="margin:0 0 16px">کاربران</h1>

<form method="get" action="/panel/users" class="filters">
    <div class="field" style="margin:0">
        <label for="f-role">نقش</label>
        <select id="f-role" name="role">
            <?php foreach (['all' => 'همه', 'admin' => 'مدیر', 'manager' => 'مدیر عملیاتی', 'rider' => 'سوارکار', 'club' => 'باشگاه'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['role'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'pending' => 'در انتظار', 'verified' => 'تایید‌شده', 'rejected' => 'رد‌شده', 'disabled-limited' => 'محدود', 'disabled-full' => 'مسدود'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<?php if (empty($rows)): ?>
    <div class="empty-state">کاربری یافت نشد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>نام کاربری</th>
                <th>نقش</th>
                <th>نام</th>
                <th>موبایل</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td><?= e($u['username'] ?? '') ?></td>
                    <td><?= e($u['role'] ?? '') ?></td>
                    <td><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td>
                    <td><?= e($u['phone'] ?? '—') ?></td>
                    <td><?= e($u['verification_status'] ?? '') ?></td>
                    <td><?= e($view['date']($u['created_at'] ?? null)) ?></td>
                    <td><a class="btn" href="/panel/users/<?= (int) $u['id'] ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>