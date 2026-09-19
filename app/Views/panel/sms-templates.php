<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/sms-templates.php
 *
 * Purpose: SMS template CRUD admin panel (Blueprint §14.2).
 *
 * @var array  $view
 * @var array  $rows
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">قالب‌های پیامک</h1>

<div style="display:flex;gap:8px;align-items:center;margin-block-end:16px;flex-wrap:wrap">
    <button class="btn btn-primary" data-action="new-template">افزودن قالب</button>
</div>

<div id="template-form-wrap" style="display:none;margin-block-end:16px;padding:16px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
    <input type="hidden" id="tpl-id" value="0">
    <div style="display:grid;grid-template-columns:1fr;gap:12px">
        <div class="field">
            <label for="tpl-name">نام قالب</label>
            <input id="tpl-name" type="text" style="width:100%">
        </div>
        <div class="field">
            <label for="tpl-body">بدنه پیام</label>
            <textarea id="tpl-body" rows="3" style="width:100%;direction:ltr;text-align:left;font-size:14px"></textarea>
            <span class="help">متغیرها را با فرمت {var_name} قرار دهید</span>
        </div>
        <div class="field">
            <label for="tpl-vars">متغیرها (با کاما)</label>
            <input id="tpl-vars" type="text" placeholder="phone,name,date" style="width:100%">
        </div>
    </div>
    <div class="toolbar" style="margin-top:12px">
        <button class="btn btn-primary" data-action="save-template">ذخیره</button>
        <button class="btn" data-action="cancel-template">انصراف</button>
    </div>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">قالب پیامکی یافت نشد. <button class="btn" data-action="new-template">افزودن</button></div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>شناسه</th>
                <th>نام</th>
                <th>بدنه</th>
                <th>متغیرها</th>
                <th>وضعیت</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $t): ?>
                <tr id="tpl-row-<?= (int) ($t['id'] ?? 0) ?>">
                    <td><?= (int) ($t['id'] ?? 0) ?></td>
                    <td><?= e($t['name'] ?? '') ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($t['body'] ?? '') ?>"><?= e(mb_substr($t['body'] ?? '', 0, 60)) ?></td>
                    <td><code style="font-size:11px"><?= e($t['variables'] ?? '') ?></code></td>
                    <td><span class="badge <?= (bool) ($t['is_active'] ?? false) ? 'badge-green' : 'badge-gray' ?>"><?= (bool) ($t['is_active'] ?? false) ? 'فعال' : 'غیرفعال' ?></span></td>
                    <td>
                        <button class="btn" data-action="edit-template" data-id="<?= (int) ($t['id'] ?? 0) ?>" style="padding:4px 8px;font-size:12px">ویرایش</button>
                        <button class="btn btn-danger" data-action="delete-template" data-id="<?= (int) ($t['id'] ?? 0) ?>" style="padding:4px 8px;font-size:12px">حذف</button>
                        <button class="btn" data-action="toggle-template" data-id="<?= (int) ($t['id'] ?? 0) ?>" style="padding:4px 8px;font-size:12px">
                            <?= (bool) ($t['is_active'] ?? false) ? 'غیرفعال' : 'فعال' ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-action="new-template"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('tpl-id').value = '0';
            document.getElementById('tpl-name').value = '';
            document.getElementById('tpl-body').value = '';
            document.getElementById('tpl-vars').value = '';
            document.getElementById('template-form-wrap').style.display = 'block';
        });
    });
    document.querySelectorAll('[data-action="cancel-template"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('template-form-wrap').style.display = 'none';
        });
    });
    document.querySelectorAll('[data-action="save-template"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = (int) document.getElementById('tpl-id').value;
            var name = document.getElementById('tpl-name').value.trim();
            var body = document.getElementById('tpl-body').value.trim();
            var varsStr = document.getElementById('tpl-vars').value.trim();
            var vars = varsStr ? varsStr.split(',').map(function(v){return v.trim();}).filter(Boolean) : [];
            var url = id > 0 ? '/panel/sms/templates/' + id : '/panel/sms/templates';
            var method = id > 0 ? 'POST' : 'POST';
            window.Panel.api(url, {
                method: method,
                body: { name: name, body: body, variables: vars, _csrf: '<?= e($csrf) ?>' }
            }).then(function () {
                window.Panel.toast('قالب ذخیره شد.', 'success');
                window.location.reload();
            }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="edit-template"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            window.Panel.api('/panel/sms/templates/' + id, {method: 'GET'}).then(function (res) {
                if (res.data) {
                    document.getElementById('tpl-id').value = res.data.id;
                    document.getElementById('tpl-name').value = res.data.name;
                    document.getElementById('tpl-body').value = res.data.body;
                    document.getElementById('tpl-vars').value = (res.data.variables || []).join(',');
                    document.getElementById('template-form-wrap').style.display = 'block';
                }
            }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="delete-template"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            if (!window.Panel.confirmAction('آیا این قالب حذف شود؟')) return;
            window.Panel.api('/panel/sms/templates/' + id + '/delete', {method: 'POST', body: {_csrf: '<?= e($csrf) ?>'}})
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="toggle-template"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            window.Panel.api('/panel/sms/templates/' + id + '/toggle', {method: 'POST', body: {_csrf: '<?= e($csrf) ?>'}})
                .then(function () { window.location.reload(); })
                .catch(function () {});
        });
    });
})();
</script>
