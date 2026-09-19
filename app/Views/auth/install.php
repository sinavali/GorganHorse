<?php
declare(strict_types=1);

/**
 * File: app/Views/auth/install.php
 *
 * Installer page. Renders requirement checks, the admin-account form, and
 * per-field error messages returned by POST /install/run.
 *
 * @var array  $view
 * @var array  $requirements
 * @var string $csrf
 */
$allOk = true;
foreach (($requirements ?? []) as $r) {
    if (empty($r['ok'])) {
        $allOk = false;
        break;
    }
}
?>
<h1 style="font-size:20px;margin:0 0 16px;text-align:center">نصب سامانه</h1>

<h2 style="font-size:15px">بررسی پیش‌نیازها</h2>
<ul style="list-style:none;padding:0;margin:0 0 16px">
    <?php foreach (($requirements ?? []) as $r): ?>
        <li style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
            <span><?= e((string) $r['label']) ?></span>
            <span style="color:<?= !empty($r['ok']) ? '#166534' : '#991b1b' ?>">
                <?= e((string) ($r['detail'] ?? '')) ?>     <?= !empty($r['ok']) ? '✓' : '✗' ?>
            </span>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($allOk): ?>
    <form id="install-form" method="post" action="/install/run" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="admin_username" class="required">نام کاربری مدیر</label>
            <input id="admin_username" name="admin_username" type="text" required value="admin" data-field="admin_username">
        </div>
        <div class="field">
            <label for="admin_phone" class="required">شماره موبایل مدیر</label>
            <input id="admin_phone" name="admin_phone" type="tel" inputmode="tel" data-numeric required
                placeholder="09xxxxxxxxx" data-field="admin_phone">
        </div>
        <div class="field">
            <label for="admin_first_name">نام</label>
            <input id="admin_first_name" name="admin_first_name" type="text" value="مدیر" data-field="admin_first_name">
        </div>
        <div class="field">
            <label for="admin_last_name">نام خانوادگی</label>
            <input id="admin_last_name" name="admin_last_name" type="text" value="سامانه" data-field="admin_last_name">
        </div>
        <div class="field">
            <label for="admin_password" class="required">رمز عبور (حداقل ۸ نویسه)</label>
            <input id="admin_password" name="admin_password" type="password" minlength="8" required data-field="admin_password">
        </div>
        <div class="field">
            <label>
                <input type="checkbox" name="seed_demo" value="1">
                ایجاد داده‌های نمونه (توصیه می‌شود)
            </label>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">اجرای نصب</button>
    </form>

    <script>
        (function () {
            function clearFieldErrors() {
                document.querySelectorAll('[data-field-error]').forEach(function (el) { el.remove(); });
                document.querySelectorAll('[data-field].invalid').forEach(function (el) { el.classList.remove('invalid'); });
            }

            function renderFieldErrors(errors) {
                clearFieldErrors();
                if (!errors || !errors.length) { return; }
                var shown = 0;
                errors.forEach(function (err) {
                    if (!err || !err.field) { return; }
                    var field = document.querySelector('[data-field="' + err.field + '"]');
                    if (!field) { return; }
                    field.classList.add('invalid');
                    var el = document.createElement('div');
                    el.className = 'error-text';
                    el.setAttribute('data-field-error', err.field);
                    el.textContent = err.message || '';
                    if (field.parentNode) { field.parentNode.appendChild(el); }
                    shown++;
                });
                return shown;
            }

            var form = document.getElementById('install-form');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearFieldErrors();
                var body = {};
                new FormData(form).forEach(function (v, k) { body[k] = v; });

                fetch('/install/run', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': (document.querySelector('input[name="_csrf"]') || {}).value || ''
                    },
                    body: JSON.stringify(body)
                })
                .then(function (res) { return res.json().catch(function () { return { ok: false }; }); })
                .then(function (res) {
                    if (res && res.ok && res.data) {
                        window.location.href = res.data.redirect || '/auth/login';
                        return;
                    }
                    var errors = (res && res.errors) || [];
                    var rendered = renderFieldErrors(errors);
                    if (!rendered) {
                        var msg = (errors[0] && errors[0].message) || 'خطا در اجرای نصب.';
                        if (window.Panel && window.Panel.toast) {
                            window.Panel.toast(msg, 'error', 0);
                        } else {
                            alert(msg);
                        }
                    }
                })
                .catch(function () {
                    if (window.Panel && window.Panel.toast) {
                        window.Panel.toast('ارتباط با سرور برقرار نشد.', 'error', 0);
                    }
                });
            });
        })();
    </script>
<?php else: ?>
    <p style="color:#991b1b">لطفا پیش‌نیازهای بالا را برطرف کنید و دوباره تلاش کنید.</p>
<?php endif; ?>