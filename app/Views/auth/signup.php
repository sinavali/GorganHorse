<?php
declare(strict_types=1);

/**
 * File: app/Views/auth/signup.php
 *
 * @var array  $view
 * @var bool   $captcha_on_signup
 * @var int    $password_min_length
 * @var string $csrf
 */
$min = (int) ($password_min_length ?? 8);
?>
<h1 style="font-size:20px;margin:0 0 16px;text-align:center">ثبت‌نام سوارکار</h1>

<form id="signup-form" method="post" action="/auth/signup" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="field">
        <label for="first_name" class="required">نام</label>
        <input id="first_name" name="first_name" type="text" required>
    </div>
    <div class="field">
        <label for="last_name" class="required">نام خانوادگی</label>
        <input id="last_name" name="last_name" type="text" required>
    </div>
    <div class="field">
        <label for="phone" class="required">شماره موبایل</label>
        <input id="phone" name="phone" type="tel" inputmode="tel" data-numeric required placeholder="09xxxxxxxxx">
    </div>
    <div class="field">
        <label for="national_id" class="required">کد ملی</label>
        <input id="national_id" name="national_id" type="text" inputmode="numeric" data-numeric required maxlength="10">
    </div>
    <div class="field">
        <label for="password" class="required">رمز عبور (حداقل <?= e((string) $min) ?> نویسه)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required
            minlength="<?= e((string) $min) ?>">
    </div>
    <div class="field">
        <label for="password_confirm" class="required">تکرار رمز عبور</label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
    </div>
    <?php if (!empty($captcha_on_signup)): ?>
        <div class="field" data-captcha>
            <label for="captcha_answer" class="required">کد امنیتی</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input id="captcha_answer" name="captcha_answer" type="text" required autocomplete="off">
                <img data-captcha-image alt="کد امنیتی" style="border:1px solid var(--line);border-radius:8px">
            </div>
            <input type="hidden" name="captcha_token" data-captcha-token value="">
        </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">ایجاد حساب</button>
</form>

<p style="text-align:center;margin-top:12px"><a href="/auth/login">بازگشت به ورود</a></p>

<script>
    (function () {
        var tokenEl = document.querySelector('[data-captcha-token]');
        if (tokenEl) {
            window.Panel.api('/captcha/issue', { method: 'POST' }).then(function (res) {
                var data = res.data || {};
                tokenEl.value = data.token || '';
                var img = document.querySelector('[data-captcha-image]');
                if (img && data.image_url) { img.src = data.image_url; }
            }).catch(function () { });
        }

        document.getElementById('signup-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var form = e.target;
            var body = {};
            new FormData(form).forEach(function (v, k) { body[k] = v; });
            window.Panel.api('/auth/signup', { method: 'POST', body: body })
                .then(function (res) {
                    window.Panel.toast('حساب شما ایجاد شد. اکنون وارد شوید.', 'success');
                    window.location.href = (res.data && res.data.redirect) || '/auth/login';
                })
                .catch(function () { });
        });
    })();
</script>