<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/user-edit.php
 *
 * Purpose: User detail / edit page with tabs (Profile, Rider Profile,
 * Sessions, Activity, Horses, Signups, Audit). Admin/Manager access.
 *
 * @var array  $view
 * @var array  $record
 * @var string $csrf
 * @var string $role
 */
?>
<h1 style="margin:0 0 16px">ویرایش کاربر</h1>

<div class="tabs" style="display:flex;gap:4px;border-block-end:2px solid var(--line);margin-block-end:16px;flex-wrap:wrap">
    <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>" class="btn <?= !isset($_GET['tab']) ? 'btn-primary' : '' ?>">پروفایل</a>
    <?php if (($record['role'] ?? '') === 'rider'): ?>
        <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>?tab=rider" class="btn <?= ($_GET['tab'] ?? '') === 'rider' ? 'btn-primary' : '' ?>">پروفایل سوارکار</a>
        <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>?tab=horses" class="btn <?= ($_GET['tab'] ?? '') === 'horses' ? 'btn-primary' : '' ?>">اسبان</a>
        <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>?tab=signups" class="btn <?= ($_GET['tab'] ?? '') === 'signups' ? 'btn-primary' : '' ?>">ثبت‌نام‌ها</a>
    <?php endif; ?>
    <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>?tab=sessions" class="btn <?= ($_GET['tab'] ?? '') === 'sessions' ? 'btn-primary' : '' ?>">جلسات</a>
    <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>?tab=activity" class="btn <?= ($_GET['tab'] ?? '') === 'activity' ? 'btn-primary' : '' ?>">فعالیت</a>
    <?php if ($role === 'admin'): ?>
        <a href="/panel/users/<?= (int) ($record['id'] ?? 0) ?>?tab=audit" class="btn <?= ($_GET['tab'] ?? '') === 'audit' ? 'btn-primary' : '' ?>">حسابرسی</a>
    <?php endif; ?>
</div>

<?php
$tab = $_GET['tab'] ?? 'profile';
?>

<?php if ($tab === 'profile' || $tab === ''): ?>
<form id="user-form" method="post" action="/panel/users/<?= (int) ($record['id'] ?? 0) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
            <label for="username">نام کاربری</label>
            <input id="username" name="username" type="text" value="<?= e($record['username'] ?? '') ?>" <?= isset($record['id']) ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label for="phone">موبایل</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="<?= e($record['phone'] ?? '') ?>" data-numeric>
        </div>
        <div class="field">
            <label for="first_name">نام</label>
            <input id="first_name" name="first_name" type="text" value="<?= e($record['first_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="last_name">نام خانوادگی</label>
            <input id="last_name" name="last_name" type="text" value="<?= e($record['last_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="email">ایمیل</label>
            <input id="email" name="email" type="email" value="<?= e($record['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="role">نقش</label>
            <select id="role" name="role">
                <?php foreach (['admin' => 'مدیر', 'manager' => 'مدیر عملیاتی', 'rider' => 'سوارکار', 'club' => 'باشگاه'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= ($record['role'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="verification_status">وضعیت تایید</label>
            <select id="verification_status" name="verification_status">
                <?php foreach (['pending' => 'در انتظار', 'verified' => 'تایید‌شده', 'rejected' => 'رد‌شده'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= ($record['verification_status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="disable_state">حالت محدودیت</label>
            <select id="disable_state" name="disable_state">
                <?php foreach (['none' => 'بدون محدودیت', 'limited' => 'محدود', 'full' => 'مسدود'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= ($record['disable_state'] ?? 'none') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="national_id">کد ملی</label>
            <input id="national_id" name="national_id" type="text" inputmode="numeric" data-numeric value="<?= e($record['national_id'] ?? '') ?>" maxlength="10">
        </div>
    </div>
    <div class="toolbar" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">ذخیره</button>
        <?php if ($role === 'admin'): ?>
            <button type="button" class="btn" data-action="impersonate" data-user-id="<?= (int) ($record['id'] ?? 0) ?>">جعل هویت</button>
            <button type="button" class="btn" data-action="reset-password" data-user-id="<?= (int) ($record['id'] ?? 0) ?>">تنظیم مجدد رمز عبور</button>
            <button type="button" class="btn btn-danger" data-action="delete-user" data-user-id="<?= (int) ($record['id'] ?? 0) ?>">حذف</button>
        <?php endif; ?>
    </div>
</form>
<?php elseif ($tab === 'rider' && ($record['role'] ?? '') === 'rider'): ?>
<div class="card">
    <h2 style="font-size:16px;margin:0 0 12px">پروفایل سوارکار</h2>
    <p style="color:var(--muted)">اطلاعات تخصصی سوارکار در اینجا نمایش داده می‌شود.</p>
</div>
<?php elseif ($tab === 'horses' && ($record['role'] ?? '') === 'rider'): ?>
<h2 style="font-size:16px;margin:0 0 12px">اسبان این سوارکار</h2>
<p style="color:var(--muted)">لیست اسبان متعلق به این سوارکار.</p>
<?php elseif ($tab === 'signups' && ($record['role'] ?? '') === 'rider'): ?>
<h2 style="font-size:16px;margin:0 0 12px">ثبت‌نام‌های این سوارکار</h2>
<p style="color:var(--muted)">لیست ثبت‌نام‌های این سوارکار.</p>
<?php elseif ($tab === 'sessions'): ?>
<h2 style="font-size:16px;margin:0 0 12px">جلسات</h2>
<p style="color:var(--muted)">مدیریت جلسات کاربر.</p>
<?php elseif ($tab === 'activity'): ?>
<h2 style="font-size:16px;margin:0 0 12px">فعالیت اخیر</h2>
<p style="color:var(--muted)">لاگ فعالیت‌های اخیر کاربر.</p>
<?php elseif ($tab === 'audit' && $role === 'admin'): ?>
<h2 style="font-size:16px;margin:0 0 12px">حسابرسی کاربر</h2>
<p style="color:var(--muted)">لاگ تغییرات این کاربر.</p>
<?php endif; ?>

<script>
(function () {
    var impBtn = document.querySelector('[data-action="impersonate"]');
    if (impBtn) {
        impBtn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('آیا مطمئن هستید که می‌خواهید به صورت جعل هویت وارد شوید؟')) { return; }
            window.Panel.api('/panel/users/' + impBtn.getAttribute('data-user-id') + '/impersonate', { method: 'POST' })
                .then(function (res) { window.location.href = res.data.redirect || '/panel'; })
                .catch(function () {});
        });
    }
    var resetBtn = document.querySelector('[data-action="reset-password"]');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            var pw = window.prompt('رمز عبور جدید را وارد کنید:');
            if (!pw) { return; }
            window.Panel.api('/panel/users/' + resetBtn.getAttribute('data-user-id') + '/reset-password', { method: 'POST', body: { password: pw } })
                .then(function () { window.Panel.toast('رمز عبور با موفقیت تنظیم شد.', 'success'); })
                .catch(function () {});
        });
    }
    var delBtn = document.querySelector('[data-action="delete-user"]');
    if (delBtn) {
        delBtn.addEventListener('click', function () {
            var id = delBtn.getAttribute('data-user-id');
            var expected = 'DELETE';
            if (!window.Panel.confirmTyped(expected)) { return; }
            if (!window.Panel.confirmAction('آیا مطمئن هستید که می‌خواهید این کاربر را حذف کنید؟')) { return; }
            window.Panel.api('/panel/users/' + id, { method: 'DELETE' })
                .then(function () { window.location.href = '/panel/users'; })
                .catch(function () {});
        });
    }
})();
</script>
