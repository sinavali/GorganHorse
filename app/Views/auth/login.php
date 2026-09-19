<?php
declare(strict_types=1);

/**
 * File: app/Views/auth/login.php
 *
 * @var array  $view
 * @var bool   $sms_enabled
 * @var bool   $captcha_on_login
 * @var bool   $allow_signup
 * @var bool   $expired
 * @var bool   $banned
 * @var string $csrf
 */
?>
<h1 style="font-size:20px;margin:0 0 16px;text-align:center">ورود به پنل</h1>

<?php if (!empty($expired)): ?>
    <div class="banner" style="background:#fee2e2;color:#991b1b">نشست شما منقضی شده است. لطفا دوباره وارد شوید.</div>
<?php endif; ?>
<?php if (!empty($banned)): ?>
    <div class="banner" style="background:#fee2e2;color:#991b1b">حساب شما مسدود شده است. لطفا با پشتیبانی تماس بگیرید.</div>
<?php endif; ?>

<form id="login-form" method="post" action="/auth/login" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="field">
        <label for="identifier" class="required">نام کاربری یا شماره موبایل</label>
        <input id="identifier" name="identifier" type="text" autocomplete="username" required>
    </div>
    <div class="field">
        <label for="password" class="required">رمز عبور</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <?php if (!empty($captcha_on_login)): ?>
        <div class="field" data-captcha>
            <label for="captcha_answer" class="required">کد امنیتی</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input id="captcha_answer" name="captcha_answer" type="text" required inputmode="text" autocomplete="off">
                <img data-captcha-image alt="کد امنیتی" style="border:1px solid var(--line);border-radius:8px">
            </div>
            <input type="hidden" name="captcha_token" data-captcha-token value="">
        </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">ورود</button>
</form>

<?php if (!empty($sms_enabled)): ?>
    <p style="text-align:center;margin-top:12px"><a href="#" id="otp-toggle">ورود با کد پیامکی</a></p>
<?php endif; ?>

<?php if (!empty($allow_signup)): ?>
    <p style="text-align:center;margin-top:8px"><a href="/auth/signup">ثبت‌نام سوارکار</a></p>
<?php endif; ?>

<script>
    (function () {
        // Issue captcha when the page loads (if enabled).
        var tokenEl = document.querySelector('[data-captcha-token]');
        if (tokenEl) {
            window.Panel.api('/captcha/issue', { method: 'POST' }).then(function (res) {
                var data = res.data || {};
                tokenEl.value = data.token || '';
                var img = document.querySelector('[data-captcha-image]');
                if (img && data.image_url) { img.src = data.image_url; }
            }).catch(function () { });
        }

        document.getElementById('login-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var form = e.target;
            var body = {};
            new FormData(form).forEach(function (v, k) { body[k] = v; });
            window.Panel.api('/auth/login', { method: 'POST', body: body })
                .then(function (res) {
                    window.location.href = (res.data && res.data.redirect) || '/panel';
                })
                .catch(function () { /* toast already shown */ });
        });
    })();
</script>