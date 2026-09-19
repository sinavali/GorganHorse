<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/rade-edit.php
 *
 * Purpose: Rade create/edit form.
 *
 * @var array  $view
 * @var array  $record
 * @var string $csrf
 */
$record = $record ?? [];
$isEdit = !empty($record['id']);
?>
<h1 style="margin:0 0 16px"><?= $isEdit ? 'ویرایش رده' : 'ایجاد رده' ?></h1>

<form id="rade-form" method="post" action="<?= $isEdit ? '/panel/rades/' . (int) $record['id'] : '/panel/rades' ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
            <label for="name" class="required">نام رده</label>
            <input id="name" name="name" type="text" required value="<?= e($record['name'] ?? '') ?>">
            <span class="help">?</span>
        </div>
        <div class="field">
            <label for="slug">نامک</label>
            <input id="slug" name="slug" type="text" value="<?= e($record['slug'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="3"><?= e($record['description'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="age_min">حداقل سن</label>
            <input id="age_min" name="age_min" type="number" min="0" value="<?= e($record['age_min'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="age_max">حداکثر سن</label>
            <input id="age_max" name="age_max" type="number" min="0" value="<?= e($record['age_max'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="age_enforced">سن اجباری</label>
            <input id="age_enforced" name="age_enforced" type="checkbox" value="1" <?= ($record['age_enforced'] ?? 0) ? 'checked' : '' ?>>
        </div>
        <div class="field">
            <label for="sort_order">ترتیب</label>
            <input id="sort_order" name="sort_order" type="number" value="<?= e($record['sort_order'] ?? 0) ?>">
        </div>
        <div class="field">
            <label for="active">فعال</label>
            <input id="active" name="active" type="checkbox" value="1" <?= ($record['active'] ?? 1) ? 'checked' : '' ?>>
        </div>
    </div>
    <div class="toolbar" style="margin-top:16px">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'ذخیره' : 'ایجاد' ?></button>
        <a href="/panel/rades" class="btn">انصراف</a>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('rade-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {};
            new FormData(form).forEach(function (v, k) {
                if (k === 'age_enforced' || k === 'active') { body[k] = document.querySelector('[' + 'name=' + JSON.stringify(k) + ']').checked ? 1 : 0; }
                else { body[k] = v; }
            });
            window.Panel.api(form.getAttribute('action'), { method: form.method, body: body })
                .then(function (res) { window.location.href = '/panel/rades/' + res.data.id; })
                .catch(function () {});
        });
    }
})();
</script>
