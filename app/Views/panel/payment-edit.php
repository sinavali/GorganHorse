<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/payment-edit.php
 *
 * Purpose: Payment template create/edit form.
 *
 * @var array  $view
 * @var array  $record
 * @var string $csrf
 */
$record = $record ?? [];
$isEdit = !empty($record['id']);
?>
<h1 style="margin:0 0 16px"><?= $isEdit ? 'ویرایش الگو' : 'ایجاد الگو' ?></h1>

<form id="payment-form" method="post" action="<?= $isEdit ? '/panel/payments/' . (int) $record['id'] : '/panel/payments' ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>
    <div class="grid grid-2" style="grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
            <label for="name" class="required">نام</label>
            <input id="name" name="name" type="text" required value="<?= e($record['name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="slug">نامک</label>
            <input id="slug" name="slug" type="text" value="<?= e($record['slug'] ?? '') ?>">
        </div>
        <div class="field" style="grid-column:1/-1">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="3"><?= e($record['description'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="amount_irt" class="required">مبلغ (تومان)</label>
            <input id="amount_irt" name="amount_irt" type="text" inputmode="numeric" data-numeric required value="<?= e((string) ($record['amount_irt'] ?? 0)) ?>">
            <span class="help">مبلغ به تومان (عدد صحیح). مثال: 5000000</span>
        </div>
        <div class="field">
            <label for="active">فعال</label>
            <input id="active" name="active" type="checkbox" value="1" <?= ($record['active'] ?? 1) ? 'checked' : '' ?>>
        </div>
    </div>
    <div class="toolbar" style="margin-top:16px">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'ذخیره' : 'ایجاد' ?></button>
        <a href="/panel/payments" class="btn">انصراف</a>
        <?php if ($isEdit): ?>
            <a href="/panel/payments/<?= (int) $record['id'] ?>/print" class="btn">چاپ</a>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('payment-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {};
            new FormData(form).forEach(function (v, k) {
                if (k === 'active') { body[k] = document.querySelector('[name="active"]').checked ? 1 : 0; }
                else { body[k] = v; }
            });
            window.Panel.api(form.getAttribute('action'), { method: form.method, body: body })
                .then(function (res) { window.location.href = '/panel/payments/' + res.data.id; })
                .catch(function () {});
        });
    }
})();
</script>
