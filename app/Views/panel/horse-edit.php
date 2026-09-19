<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/horse-edit.php
 *
 * Purpose: Horse detail page with tabs (Profile, Images, Signup History,
 * Shares, Transfers, Performance, Reports). (Blueprint §7.7).
 *
 * @var array  $view
 * @var array  $record
 * @var array  $images
 * @var array  $shares
 * @var array  $transfers
 * @var array  $history
 * @var string $csrf
 * @var string $role
 */
$record = $record ?? [];
$images = $images ?? [];
$shares = $shares ?? [];
$transfers = $transfers ?? [];
$history = $history ?? [];
?>
<h1 style="margin:0 0 16px">اسب: <?= e($record['name'] ?? '') ?></h1>

<div class="tabs" style="display:flex;gap:4px;border-block-end:2px solid var(--line);margin-block-end:16px;flex-wrap:wrap">
    <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>" class="btn btn-primary">پروفایل</a>
    <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>?tab=images" class="btn">تصاویر</a>
    <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>?tab=shares" class="btn">اشتراک‌ها</a>
    <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>?tab=transfers" class="btn">انتقال‌ها</a>
    <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>?tab=history" class="btn">تاریخچه</a>
</div>

<div class="card" style="margin-block-end:16px">
    <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:8px">
        <div>
            <h2 style="margin:0;font-size:20px"><?= e($record['name'] ?? '') ?></h2>
            <p style="color:var(--muted);margin:4px 0 0">میکروچیپ: <?= e($record['microchip_number'] ?? '') ?> | مالک: <?= e($record['owner_name'] ?? '') ?></p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($role === 'admin' || $role === 'manager' || $role === 'rider'): ?>
                <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>/edit" class="btn">ویرایش</a>
                <a href="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>/print" class="btn">چاپ</a>
            <?php endif; ?>
            <?php if ($record['status'] === 'active'): ?>
                <button class="btn btn-danger" data-action="sold-to-non-rider" data-id="<?= (int) ($record['id'] ?? 0) ?>">فروش به غیرسوارکار</button>
                <button class="btn btn-danger" data-action="soft-delete" data-id="<?= (int) ($record['id'] ?? 0) ?>">حذف نرم</button>
            <?php else: ?>
                <button class="btn" data-action="restore" data-id="<?= (int) ($record['id'] ?? 0) ?>">بازگردانی</button>
            <?php endif; ?>
            <button class="btn" data-action="initiate-transfer" data-id="<?= (int) ($record['id'] ?? 0) ?>">شروع انتقال</button>
            <button class="btn" data-action="share" data-id="<?= (int) ($record['id'] ?? 0) ?>">اشتراک‌گذاری</button>
        </div>
    </div>
    <div class="grid grid-4" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-block-start:16px;gap:12px">
        <div class="card"><div class="kpi-label">جنسیت</div><div class="kpi-value" style="font-size:16px"><?= e($record['gender'] ?? '') ?></div></div>
        <div class="card"><div class="kpi-label">نژاد</div><div class="kpi-value" style="font-size:16px"><?= e($record['race'] ?? '') ?></div></div>
        <div class="card"><div class="kpi-label">رنگ</div><div class="kpi-value" style="font-size:16px"><?= e($record['color'] ?? '') ?></div></div>
        <div class="card"><div class="kpi-label">وضعیت</div><div class="kpi-value" style="font-size:16px">
            <span class="badge <?= ($record['status'] ?? 'active') === 'active' ? 'badge-green' : 'badge-gray' ?>"><?= e($record['status'] ?? '') ?></span>
        </div></div>
    </div>
</div>

<?php
$tab = $_GET['tab'] ?? 'profile';
?>

<?php if ($tab === 'images'): ?>
<h2 style="font-size:16px;margin:0 0 12px">تصاویر</h2>
<div class="grid grid-4" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px">
    <?php foreach ($images as $img): ?>
        <div class="card" style="padding:8px;text-align:center">
            <img src="/assets/img/horses/<?= e($img['filename'] ?? '') ?>" style="width:100%;height:100px;object-fit:cover;border-radius:8px" alt="تصویر اسب">
            <div style="margin-top:8px">
                <?php if ($role !== 'club'): ?>
                    <button class="btn btn-danger" data-action="remove-image" data-media-id="<?= (int) ($img['id'] ?? 0) ?>" data-horse-id="<?= (int) ($record['id'] ?? 0) ?>" style="font-size:12px;padding:4px 8px">حذف</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<form id="image-upload" method="post" action="/panel/horses/<?= (int) ($record['id'] ?? 0) ?>/images" enctype="multipart/form-data" style="margin-block-start:16px">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="file" name="file" accept="image/*" required>
    <button type="submit" class="btn btn-primary">افزودن تصویر</button>
</form>
<?php elseif ($tab === 'shares'): ?>
<h2 style="font-size:16px;margin:0 0 12px">اشتراک‌ها</h2>
<?php if (empty($shares)): ?>
    <div class="empty-state">هیچ اشتراک فعالی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead><tr><th>سوارکار</th><th>کد اشتراک</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($shares as $s): ?>
                <tr>
                    <td><?= e($s['recipient_name'] ?? '') ?></td>
                    <td><?= e($s['share_code'] ?? '') ?></td>
                    <td><span class="badge badge-green">فعال</span></td>
                    <td><button class="btn btn-danger" data-action="revoke-share" data-share-id="<?= (int) ($s['id'] ?? 0) ?>" data-horse-id="<?= (int) ($record['id'] ?? 0) ?>">حذف</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php elseif ($tab === 'transfers'): ?>
<h2 style="font-size:16px;margin:0 0 12px">انتقال‌ها</h2>
<?php if (empty($transfers)): ?>
    <div class="empty-state">هیچ درخواست انتقالی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead><tr><th>مشتری</th><th>کد</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
            <?php foreach ($transfers as $t): ?>
                <tr>
                    <td><?= e($t['buyer_name'] ?? '') ?></td>
                    <td><?= e($t['transfer_code'] ?? '') ?></td>
                    <td><span class="badge <?= ($t['status'] ?? '') === 'completed' ? 'badge-green' : 'badge-yellow' ?>"><?= e($t['status'] ?? '') ?></span></td>
                    <td><?= e($view['date']($t['created_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php elseif ($tab === 'history'): ?>
<h2 style="font-size:16px;margin:0 0 12px">تاریخچه عملکرد</h2>
<?php if (empty($history)): ?>
    <div class="empty-state">هنوز ثبت‌نامی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead><tr><th>مسابقه</th><th>رده</th><th>مقام</th><th>برنده</th></tr></thead>
        <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e($h['competition_title'] ?? '') ?></td>
                    <td><?= e($h['rade_name'] ?? '') ?></td>
                    <td><?= e($h['position'] ?? '') ?></td>
                    <td><?= ($h['is_winner'] ?? 0) ? 'بله' : 'خیر' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
    var soldBtn = document.querySelector('[data-action="sold-to-non-rider"]');
    if (soldBtn) soldBtn.addEventListener('click', function () {
        if (!window.Panel.confirmAction('این اسب به غیرسوارکار فروخته خواهد شد. ادامه؟')) return;
        window.Panel.api('/panel/horses/' + soldBtn.getAttribute('data-id') + '/sold-to-non-rider', { method: 'POST' })
            .then(function () { window.location.reload(); }).catch(function () {});
    });
    document.querySelectorAll('[data-action="soft-delete"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('این اسب به صورت نرم حذف خواهد شد. ادامه؟')) return;
            window.Panel.api('/panel/horses/' + btn.getAttribute('data-id'), { method: 'DELETE' })
                .then(function () { window.location.href = '/panel/horses'; }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="restore"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/horses/bulk', { method: 'POST', body: { action: 'restore', ids: [btn.getAttribute('data-id')] } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="initiate-transfer"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('آیا می‌خواهید انتقال این اسب را شروع کنید؟')) return;
            window.Panel.api('/panel/horses/' + btn.getAttribute('data-id') + '/transfer/initiate', { method: 'POST' })
                .then(function (res) { alert('کد انتقال: ' + (res.data?.transfer_code || '—') + '\nاین کد را به خریدار بدهید.'); })
                .catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="share"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = prompt('کد اشتراک 6 رقمی را وارد کنید:');
            if (!code) return;
            window.Panel.api('/panel/horses/' + btn.getAttribute('data-id') + '/share', { method: 'POST', body: { share_code: code } })
                .then(function () { window.Panel.toast('اشتراک ایجاد شد.', 'success'); })
                .catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="remove-image"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('حذف تصویر؟')) return;
            window.Panel.api('/panel/horses/' + btn.getAttribute('data-horse-id') + '/images/' + btn.getAttribute('data-media-id'), { method: 'DELETE' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="revoke-share"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('حذف اشتراک؟')) return;
            window.Panel.api('/panel/horses/' + btn.getAttribute('data-horse-id') + '/share/' + btn.getAttribute('data-share-id'), { method: 'DELETE' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
