<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/profile.php
 *
 * Purpose: User profile page (tabs: Profile, Avatar, Password, Sessions).
 * Rider/Club self-service; Admin/Manager read-only overview.
 *
 * @var array  $view
 * @var array  $record
 * @var array  $sessions
 * @var string $current_session
 * @var string $tab
 * @var string $csrf
 * @var string $role
 */
$tab = $tab ?? 'profile';
$record = $record ?? [];
$sessions = $sessions ?? [];
$role = $role ?? 'rider';
?>
<h1 style="margin:0 0 16px">پروفایل من</h1>

<div class="tabs" style="display:flex;gap:4px;border-block-end:2px solid var(--line);margin-block-end:16px">
    <a href="/panel/profile?tab=profile" class="btn <?= $tab === 'profile' ? 'btn-primary' : '' ?>">پروفایل</a>
    <a href="/panel/profile?tab=avatar" class="btn <?= $tab === 'avatar' ? 'btn-primary' : '' ?>">آواتار</a>
    <a href="/panel/profile?tab=password" class="btn <?= $tab === 'password' ? 'btn-primary' : '' ?>">رمز عبور</a>
    <a href="/panel/profile?tab=sessions" class="btn <?= $tab === 'sessions' ? 'btn-primary' : '' ?>">جلسات</a>
</div>

<?php if ($tab === 'profile'): ?>
<form id="profile-form" method="post" action="/panel/profile" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
            <label for="first_name">نام</label>
            <input id="first_name" name="first_name" type="text" value="<?= e($record['first_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="last_name">نام خانوادگی</label>
            <input id="last_name" name="last_name" type="text" value="<?= e($record['last_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="phone">شماره موبایل</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="<?= e($record['phone'] ?? '') ?>" data-numeric>
        </div>
        <div class="field">
            <label for="email">ایمیل</label>
            <input id="email" name="email" type="email" value="<?= e($record['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="national_id">کد ملی</label>
            <input id="national_id" name="national_id" type="text" inputmode="numeric" value="<?= e($record['national_id'] ?? '') ?>" data-numeric maxlength="10">
        </div>
        <div class="field">
            <label for="birth_date">تاریخ تولد</label>
            <input id="birth_date" name="birth_date" type="text" value="<?= e($record['birth_date'] ?? '') ?>">
        </div>
    </div>
    <div class="field">
        <label for="address">آدرس</label>
        <textarea id="address" name="address" rows="3"><?= e($record['address'] ?? '') ?></textarea>
    </div>
    <div class="field">
        <label for="bio">بیوگرافی</label>
        <textarea id="bio" name="bio" rows="3"><?= e($record['bio'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">ذخیره</button>
</form>
<?php elseif ($tab === 'avatar'): ?>
<div class="card" style="max-width:400px">
    <h2 style="font-size:16px;margin:0 0 12px">آواتار</h2>
    <?php if (!empty($record['avatar_text'] ?? '')): ?>
        <div style="width:80px;height:80px;border-radius:50%;background:var(--brand-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;margin-block-end:12px">
            <?= e($record['avatar_text']) ?>
        </div>
    <?php elseif (!empty($record['avatar_media_id'] ?? '')): ?>
        <img src="/assets/img/avatars/<?= e($record['avatar_media_id']) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-block-end:12px" alt="آواتار">
    <?php else: ?>
        <div style="width:80px;height:80px;border-radius:50%;background:var(--line);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;margin-block-end:12px">?</div>
    <?php endif; ?>
    <form id="avatar-form" method="post" action="/panel/profile/avatar" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <input type="file" name="file" accept="image/*" required>
        </div>
        <button type="submit" class="btn btn-primary">آپلود</button>
    </form>
</div>
<?php elseif ($tab === 'password'): ?>
<form id="password-form" method="post" action="/panel/profile/password" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="field">
        <label for="current_password">رمز عبور فعلی</label>
        <input id="current_password" name="current_password" type="password" required>
    </div>
    <div class="field">
        <label for="password">رمز عبور جدید</label>
        <input id="password" name="password" type="password" required minlength="8">
    </div>
    <div class="field">
        <label for="password_confirm">تکرار رمز عبور</label>
        <input id="password_confirm" name="password_confirm" type="password" required>
    </div>
    <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
</form>
<?php elseif ($tab === 'sessions'): ?>
<h2 style="font-size:16px;margin:0 0 12px">جلسات فعال</h2>
<?php if (empty($sessions)): ?>
    <div class="empty-state">جلسه‌ای فعال نیست.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr><th>IP</th><th>UA</th><th>آخرین فعالیت</th><th>موقتی</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><?= e($s['ip'] ?? '—') ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= e(substr($s['ua_hash'] ?? '', 0, 16)) ?></td>
                    <td><?= e(($view['date']($s['last_activity_at'] ?? null))) ?></td>
                    <td><?= ($s['id'] === $current_session) ? 'فعال' : '—' ?></td>
                    <td>
                        <?php if ($s['id'] !== $current_session): ?>
                            <button class="btn btn-danger" data-action="revoke-session" data-session-id="<?= e($s['id'] ?? '') ?>">انهاء</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:12px">
        <button class="btn btn-danger" data-action="revoke-all-sessions">انهاء همه جلسات</button>
    </p>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-action="revoke-session"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('آیا می‌خواهید این جلسه را انهاء کنید؟')) { return; }
            var id = btn.getAttribute('data-session-id');
            window.Panel.api('/panel/profile/sessions/' + id + '/revoke', { method: 'POST' })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
    var revokeAllBtn = document.querySelector('[data-action="revoke-all-sessions"]');
    if (revokeAllBtn) {
        revokeAllBtn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('آیا می‌خواهید همه جلسات خود را انهاء کنید؟')) { return; }
            window.Panel.api('/panel/profile/sessions/revoke-all', { method: 'POST' })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    }
})();
</script>
