<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/notifications.php
 *
 * Purpose: Notification center page. (Blueprint §14).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $unread
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">اعلان‌ها <span class="badge badge-red"><?= (int) $unread ?></span></h1>

<div class="toolbar">
    <button class="btn" data-action="read-all">علامت‌گذاری همه خوانده شده</button>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">هیچ اعلانی وجود ندارد.</div>
<?php else: ?>
    <?php $currentDay = ''; ?>
    <?php foreach ($rows as $n): ?>
        <?php $dayLabel = $view['date']($n['created_at'] ?? null, true); ?>
        <?php if ($dayLabel !== $currentDay): $currentDay = $dayLabel; ?>
            <h2 style="font-size:14px;color:var(--muted);margin:16px 0 8px;border-block-end:1px solid var(--line)"><?= e($currentDay) ?></h2>
        <?php endif; ?>
        <div class="card" style="margin-block-end:8px;display:flex;gap:12px;align-items:start;<?= empty($n['is_read'] ?? false) ? 'border-inline-start:4px solid var(--brand-primary)' : '' ?>">
            <div style="flex:1">
                <div style="font-weight:600"><?= e($n['title'] ?? '') ?></div>
                <div style="color:var(--muted);font-size:13px;margin-top:4px"><?= e($n['body'] ?? '') ?></div>
                <div style="color:var(--muted);font-size:12px;margin-top:4px"><?= e($view['date']($n['created_at'] ?? null)) ?></div>
            </div>
            <div style="text-align:center">
                <?php if (empty($n['is_read'])): ?>
                    <div style="width:8px;height:8px;border-radius:50%;background:var(--brand-primary);margin-inline-start:auto"></div>
                <?php endif; ?>
                <?php if (!empty($n['entity_type']) && !empty($n['entity_id'])): ?>
                    <a href="/<?= e($n['entity_type']) ?>/<?= (int) ($n['entity_id'] ?? 0) ?>" class="btn" style="font-size:12px;margin-block-start:8px">مشاهده</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="toolbar" style="margin-block-start:16px">
        <button class="btn" data-action="read-all">همه را خوانده شده</button>
    </div>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-action="read-all"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/notifications/read-all', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
    document.querySelectorAll('.card [data-action="read"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.Panel.api('/panel/notifications/' + btn.getAttribute('data-id') + '/read', { method: 'POST' })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
