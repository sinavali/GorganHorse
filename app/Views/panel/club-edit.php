<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/club-edit.php
 *
 * Purpose: Club create/edit detail page. Rich profile form with
 * help tooltips (G07, P28).
 *
 * @var array  $view
 * @var array  $record
 * @var string $csrf
 */
$record = $record ?? [];
$isEdit = !empty($record['id']);
?>
<h1 style="margin:0 0 16px"><?= $isEdit ? 'ویرایش باشگاه' : 'ایجاد باشگاه' ?></h1>

<form id="club-form" method="post" action="<?= $isEdit ? '/panel/clubs/' . (int) $record['id'] : '/panel/clubs' ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
            <label for="name" class="required">نام باشگاه</label>
            <input id="name" name="name" type="text" required value="<?= e($record['name'] ?? '') ?>">
            <span class="help" data-tooltip="نام رسمی باشگاه به فارسی. مثال: باشگاه هیرکان">?</span>
        </div>
        <div class="field">
            <label for="slug">نامک</label>
            <input id="slug" name="slug" type="text" value="<?= e($record['slug'] ?? '') ?>">
            <span class="help" data-tooltip="نامک برای آدرس وب. فقط حروف انگلیسی، عدد و خط تیره.">?</span>
        </div>
        <div class="field">
            <label for="city">شهر</label>
            <input id="city" name="city" type="text" value="<?= e($record['city'] ?? '') ?>" placeholder="گرگان">
        </div>
        <div class="field">
            <label for="province">استان</label>
            <input id="province" name="province" type="text" value="<?= e($record['province'] ?? '') ?>" placeholder="گلستان">
        </div>
        <div class="field">
            <label for="address">آدرس کامل</label>
            <input id="address" name="address" type="text" value="<?= e($record['address'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="phone">موبایل</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="<?= e($record['phone'] ?? '') ?>" data-numeric>
        </div>
        <div class="field">
            <label for="email">ایمیل</label>
            <input id="email" name="email" type="email" value="<?= e($record['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="contact_person">شخص تماس</label>
            <input id="contact_person" name="contact_person" type="text" value="<?= e($record['contact_person'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="status">وضعیت</label>
            <select id="status" name="status">
                <?php foreach (['active' => 'فعال', 'inactive' => 'غیرفعال'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= ($record['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="4"><?= e($record['description'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="toolbar" style="margin-top:16px">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'ذخیره' : 'ایجاد' ?></button>
        <a href="/panel/clubs" class="btn">انصراف</a>
        <?php if ($isEdit): ?>
            <a href="/panel/clubs/<?= (int) $record['id'] ?>/print" class="btn">چاپ</a>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('club-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {};
            new FormData(form).forEach(function (v, k) { body[k] = v; });
            window.Panel.api(form.getAttribute('action'), { method: form.method === 'POST' ? 'POST' : 'PUT', body: body })
                .then(function (res) { window.location.href = '/panel/clubs/' + res.data.id; })
                .catch(function () {});
        });
    }
})();
</script>
