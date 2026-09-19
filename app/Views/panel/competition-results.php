<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/competition-results.php
 *
 * Purpose: Results entry grid for a competition. Draft → Confirmed → Published.
 *
 * @var array  $view
 * @var array  $grid
 * @var string $csrf
 */
$grid = $grid ?? [];
?>
<h1 style="margin:0 0 16px">نتایج مسابقه</h1>

<div class="card" style="margin-block-end:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
            <h2 style="margin:0;font-size:16px">وضعیت نتایج</h2>
            <span class="badge <?= ($grid['results_status'] ?? 'draft') === 'published' ? 'badge-green' : ($grid['results_status'] === 'confirmed' ? 'badge-yellow' : 'badge-gray') ?>">
                <?= e($grid['results_status'] ?? 'draft') ?>
            </span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn" data-action="save-draft" data-id="<?= (int) ($grid['competition_id'] ?? 0) ?>">ذخیره پیش‌نویس</button>
            <button class="btn btn-primary" data-action="confirm" data-id="<?= (int) ($grid['competition_id'] ?? 0) ?>">تایید نتایج</button>
            <button class="btn btn-primary" data-action="publish" data-id="<?= (int) ($grid['competition_id'] ?? 0) ?>">انتشار نتایج</button>
            <?php if ($grid['results_status'] === 'published'): ?>
                <button class="btn" data-action="reopen" data-id="<?= (int) ($grid['competition_id'] ?? 0) ?>">بازگشایی</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($grid['rades'] ?? [])): ?>
    <?php foreach ($grid['rades'] as $r): ?>
        <div class="card" style="margin-block-end:16px">
            <h3 style="margin:0 0 8px;font-size:16px"><?= e($r['name'] ?? '') ?>
                <span style="font-weight:400;font-size:13px">
                    <?php if (!empty($r['had_barrage'])): ?>
                        باراژ: <?= e($r['barrage_notes'] ?? '') ?>
                    <?php endif; ?>
                </span>
            </h3>
            <div class="toolbar" style="margin-block-end:12px">
                <button class="btn btn-primary" data-action="toggle-barrage" data-comp-rade="<?= (int) ($r['comp_rade_id'] ?? 0) ?>">
                    <?= (!empty($r['had_barrage'])) ? 'غیرفعال باراژ' : 'فعال‌سازی باراژ' ?>
                </button>
                <input type="text" placeholder="یادداشت باراژ" value="<?= e($r['barrage_notes'] ?? '') ?>" style="flex:1;min-width:200px;padding:8px 12px;border:1px solid var(--line);border-radius:8px">
            </div>
            <table class="data">
                <thead>
                    <tr>
                        <th>سوارکار</th>
                        <th>اسب</th>
                        <th>باشگاه</th>
                        <th>مقام</th>
                        <th>برنده</th>
                        <th>یادداشت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['signups'] ?? [] as $s): ?>
                        <tr>
                            <td><?= e($s['rider_name'] ?? '') ?></td>
                            <td><?= e($s['horse_name'] ?? '') ?></td>
                            <td><?= e($s['club_name'] ?? '') ?></td>
                            <td><input type="number" min="0" value="<?= e($s['position'] ?? '') ?>" style="width:60px;padding:4px;border:1px solid var(--line);border-radius:4px" data-position="<?= (int) ($s['signup_id'] ?? 0) ?>"></td>
                            <td><input type="checkbox" <?= ($s['is_winner'] ?? 0) ? 'checked' : '' ?> data-winner="<?= (int) ($s['signup_id'] ?? 0) ?>"></td>
                            <td><input type="text" value="<?= e($s['result_notes'] ?? '') ?>" style="width:120px;padding:4px;border:1px solid var(--line);border-radius:4px" data-notes="<?= (int) ($s['signup_id'] ?? 0) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">هیچ رده‌ای برای این مسابقه یافت نشد.</div>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-action="save-draft"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/results', { method: 'POST', body: {} })
                .then(function () { window.Panel.toast('ذخیره شد.', 'success'); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="confirm"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/results/confirm', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="publish"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.Panel.confirmAction('انتشار نتایج؟ سوارکاران مطلع خواهند شد.')) return;
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/results/publish', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="reopen"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/competitions/' + btn.getAttribute('data-id') + '/results/reopen', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('[data-action="toggle-barrage"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-comp-rade');
            window.Panel.api('/panel/competitions/' + document.querySelector('[data-action="save-draft"]')?.getAttribute('data-id') + '/rades/' + id + '/barrage', { method: 'POST', body: { had_barrage: !btn.textContent.includes('فعال') } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
