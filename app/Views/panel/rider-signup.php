<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/rider-signup.php
 *
 * Purpose: Rider competition detail + signup modal/flow.
 * (Blueprint §9.5, §9.6).
 *
 * @var array  $view
 * @var array  $competition
 * @var array  $rades
 * @var array  $horses
 * @var array  $clubs
 * @var bool   $pending
 * @var string $csrf
 */
$comp = $competition ?? [];
?>
<h1 style="margin:0 0 16px"><?= e($comp['title'] ?? '') ?></h1>

<?php if ($pending): ?>
    <div class="banner banner-pending">برای ثبت‌نام، حساب شما باید توسط مدیر تایید شود.</div>
<?php endif; ?>

<div class="card" style="margin-block-end:16px">
    <p style="margin:0;color:var(--muted)"><?= e($comp['description'] ?? '') ?></p>
    <p style="margin:8px 0 0">
        <?= e($view['date']($comp['start_registration_at'] ?? null)) ?> — <?= e($view['date']($comp['end_registration_at'] ?? null)) ?>
    </p>
</div>

<?php if (empty($rades)): ?>
    <div class="empty-state">هیچ رده‌ای در این مسابقه یافت نشد.</div>
<?php else: ?>
    <?php foreach ($rades as $r): ?>
        <div class="card" style="margin-block-end:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div>
                    <h2 style="margin:0;font-size:16px"><?= e($r['name'] ?? '') ?></h2>
                    <p style="margin:4px 0 0;color:var(--muted)">
                        قیمت: <?= e($view['money']((int) ($r['price_irt'] ?? 0))) ?>
                        <?php if (!empty($r['capacity'])): ?> | ظرفیت: <?= (int) ($r['capacity'] ?? 0) ?><?php endif; ?>
                    </p>
                </div>
                <?php if (!$pending): ?>
                    <button class="btn btn-primary" data-action="signup" data-rade="<?= (int) ($r['comp_rade_id'] ?? $r['id'] ?? 0) ?>" data-competition="<?= (int) ($competition['id'] ?? 0) ?>">ثبت‌نام</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Signup Modal -->
<div id="signup-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:100">
    <div class="auth-card" style="max-width:500px;width:90%">
        <h2 style="margin:0 0 16px;font-size:18px">ثبت‌نام در رده</h2>
        <form id="signup-form-modal" method="post" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" id="signup-rade" name="competition_rade_id" value="0">
            <div class="field">
                <label for="signup-horse">اسب</label>
                <select id="signup-horse" name="horse_id">
                    <?php foreach ($horses as $h): ?>
                        <option value="<?= (int) ($h['id'] ?? 0) ?>"><?= e($h['name'] ?? '') ?> (میکروچیپ: <?= e($h['microchip_number'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="signup-club">باشگاه وابسته</label>
                <select id="signup-club" name="affiliation_club_id">
                    <option value="">بدون</option>
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= (int) ($c['id'] ?? 0) ?>"><?= e($c['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>قیمت (تومان)</label>
                <div id="signup-price" style="font-size:18px;font-weight:700;color:var(--brand-primary)">0 تومان</div>
            </div>
            <div class="toolbar">
                <button type="submit" class="btn btn-primary">پرداخت و ثبت‌نام</button>
                <button type="button" class="btn" data-action="close-modal">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-action="signup"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = document.getElementById('signup-modal');
            modal.style.display = 'flex';
            document.getElementById('signup-rade').value = btn.getAttribute('data-rade');
            document.getElementById('signup-price').textContent = btn.getAttribute('data-price') + ' تومان';
        });
    });
    document.querySelectorAll('[data-action="close-modal"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('signup-modal').style.display = 'none';
        });
    });
    var modalForm = document.getElementById('signup-form-modal');
    if (modalForm) {
        modalForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {};
            new FormData(modalForm).forEach(function (v, k) { body[k] = v; });
            window.Panel.api('/panel/rider/competitions/<?= (int) ($competition['id'] ?? 0) ?>/signup', { method: 'POST', body: body })
                .then(function (res) {
                    if (res.data.redirect) { window.location.href = res.data.redirect; }
                    else { window.Panel.toast('ثبت‌نام موفق. در انتظار پرداخت.', 'success'); }
                })
                .catch(function () {});
        });
    }
})();
</script>
