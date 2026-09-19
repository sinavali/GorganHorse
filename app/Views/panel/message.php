<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/message.php
 *
 * Purpose: Message detail view.
 *
 * @var array  $view
 * @var array  $record
 * @var string $csrf
 */
$record = $record ?? [];
?>
<h1 style="margin:0 0 16px"><?= e($record['subject'] ?? '') ?></h1>

<div class="card" style="margin-block-end:16px">
    <div style="display:flex;gap:12px;align-items:center;margin-block-end:12px">
        <div style="width:40px;height:40px;border-radius:50%;background:var(--brand-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">
            <?= e(mb_substr($record['sender_name'] ?? '', 0, 1)) ?>
        </div>
        <div>
            <div style="font-weight:600"><?= e($record['sender_name'] ?? '') ?></div>
            <div style="color:var(--muted);font-size:13px"><?= e($view['date']($record['sent_at'] ?? $record['created_at'] ?? null)) ?></div>
        </div>
    </div>
    <p style="margin:0;line-height:1.8;white-space:pre-wrap"><?= e($record['body'] ?? '') ?></p>
    <?php if (!empty($record['recipients_summary'])): ?>
        <p style="color:var(--muted);font-size:13px;margin-block-start:12px">مخاطبان: <?= e($record['recipients_summary']) ?></p>
    <?php endif; ?>
</div>

<?php if (empty($record['is_read'])): ?>
<button class="btn btn-primary" data-action="mark-read" data-id="<?= (int) ($record['id'] ?? 0) ?>">علامت‌گذاری خوانده شده</button>
<?php endif; ?>

<script>
document.querySelectorAll('[data-action="mark-read"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        window.Panel.api('/panel/messages/' + btn.getAttribute('data-id') + '/read', { method: 'POST' })
            .then(function () { window.location.reload(); })
            .catch(function () {});
    });
});
</script>
