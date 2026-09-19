<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/competition-edit.php
 *
 * Purpose: Competition detail page with tabs (Profile, Rades, Signups,
 * Results, Payment Orders, Reports, Print Preview).
 *
 * @var array  $view
 * @var array  $record
 * @var array  $rades
 * @var array  $all_rades
 * @var array  $payments
 * @var string $csrf
 */
$record = $record ?? [];
$rades = $rades ?? [];
$all_rades = $all_rades ?? [];
$payments = $payments ?? [];
?>
<h1 style="margin:0 0 16px">مسابقه: <?= e($record['title'] ?? '') ?></h1>

<div class="tabs" style="display:flex;gap:4px;border-block-end:2px solid var(--line);margin-block-end:16px;flex-wrap:wrap">
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>" class="btn btn-primary">پروفایل</a>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/rades" class="btn">رده‌ها</a>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/signups" class="btn">ثبت‌نام‌ها</a>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/results" class="btn">نتایج</a>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/payment-orders" class="btn">صورت‌حساب‌ها</a>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/reports" class="btn">گزارش‌ها</a>
</div>

<div class="toolbar" style="margin-block-end:16px">
    <button class="btn" data-action="pause" data-id="<?= (int) ($record['id'] ?? 0) ?>">توقف ثبت‌نام</button>
    <button class="btn" data-action="resume" data-id="<?= (int) ($record['id'] ?? 0) ?>">ازدواج ثبت‌نام</button>
    <button class="btn btn-danger" data-action="cancel" data-id="<?= (int) ($record['id'] ?? 0) ?>">لغو</button>
    <button class="btn" data-action="clone" data-id="<?= (int) ($record['id'] ?? 0) ?>">کپی</button>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/print" class="btn">چاپ</a>
    <a href="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>/signup-sheet/print" class="btn">چاپ لیست ثبت‌نام</a>
</div>

<form id="competition-form" method="post" action="/panel/competitions/<?= (int) ($record['id'] ?? 0) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_method" value="PUT">
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
            <label for="title" class="required">عنوان</label>
            <input id="title" name="title" type="text" required value="<?= e($record['title'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="slug">نامک</label>
            <input id="slug" name="slug" type="text" value="<?= e($record['slug'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="venue_club_id">محل برگزاری</label>
            <select id="venue_club_id" name="venue_club_id">
                <option value="">بدون</option>
                <?php foreach ($all_rades as $cl): ?>
                    <option value="<?= (int) ($cl['id'] ?? 0) ?>" <?= ($record['venue_club_id'] ?? 0) == ($cl['id'] ?? 0) ? 'selected' : '' ?>><?= e($cl['name'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">وضعیت</label>
            <select id="status" name="status">
                <?php foreach (['draft' => 'پیش‌نویس', 'open' => 'باز', 'closed' => 'بسته', 'running' => 'در حال اجرا', 'finished' => 'تمام', 'cancelled' => 'لغو شده'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= ($record['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="start_registration_at">شروع ثبت‌نام</label>
            <input id="start_registration_at" name="start_registration_at" type="text" value="<?= e($record['start_registration_at'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="end_registration_at">پایان ثبت‌نام</label>
            <input id="end_registration_at" name="end_registration_at" type="text" value="<?= e($record['end_registration_at'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="start_at">تاریخ مسابقه</label>
            <input id="start_at" name="start_at" type="text" value="<?= e($record['start_at'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="rules">قوانین</label>
            <textarea id="rules" name="rules" rows="3"><?= e($record['rules'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="toolbar" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">ذخیره</button>
        <a href="/panel/competitions" class="btn">انصراف</a>
    </div>
</form>

<script>
(function () {
    document.querySelectorAll('[data-action="pause"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('توقف ثبت‌نام؟')) return;
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/pause', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="resume"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/resume', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="cancel"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('لغو مسابقه؟ تمامی ثبت‌نام‌ها به بازپرداخت خواهند رفت.')) return;
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/cancel', { method: 'POST' })
                .then(function () { window.location.href = '/panel/competitions'; }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="clone"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/clone', { method: 'POST' })
                .then(function (res) { window.location.href = '/panel/competitions/' + res.data.id + '/edit'; })
                .catch(function () {});
        });
    });
})();
</script>
