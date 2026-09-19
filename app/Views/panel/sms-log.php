<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/sms-log.php
 *
 * Purpose: SMS delivery log viewer with status filter (Technical §20, §23).
 *
 * @var array  $view
 * @var array  $rows
 * @var string $status
 * @var array  $stats
 * @var string $csrf
 */
$status = $status ?? '';
$stats = $stats ?? [];
$rows = $rows ?? [];
?>
<h1 style="margin:0 0 16px">لاگ ارسال پیامک</h1>

<div style="display:flex;gap:8px;align-items:center;margin-block-end:16px;flex-wrap:wrap">
    <?php foreach (['' => 'همه', 'sent' => 'ارسال شده', 'failed' => 'ناموفق', 'skipped' => 'رد شده'] as $k => $v): ?>
        <a href="/panel/sms/log<?= $k !== '' ? '?status=' . e($k) : '' ?>" class="btn <?= $status === $k ? 'btn-primary' : '' ?>"><?= e($v) ?></a>
    <?php endforeach; ?>
</div>

<div style="display:flex;gap:16px;margin-block-end:16px;flex-wrap:wrap">
    <?php foreach ($stats as $s): ?>
        <div style="padding:8px 16px;background:#f8fafc;border-radius:8px;border:1px solid var(--line)">
            <span style="font-size:20px;font-weight:700"><?= (int) ($s['cnt'] ?? 0) ?></span>
            <span style="color:var(--muted)"> <?= e($s['status'] ?? '') ?></span>
        </div>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="empty-state">هیچ رکورد پیامکی یافت نشد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>شناسه</th>
                <th>گیرنده</th>
                <th>الگو</th>
                <th>بدنه</th>
                <th>وضعیت</th>
                <th>خطا</th>
                <th>تاریخ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) ($r['id'] ?? 0) ?></td>
                    <td><?= e($r['recipient'] ?? '') ?></td>
                    <td><?= e($r['template'] ?? '-') ?></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['body'] ?? '') ?>"><?= e(mb_substr($r['body'] ?? '', 0, 50)) ?></td>
                    <td><span class="badge <?= match ($r['status'] ?? '') { 'sent' => 'badge-green', 'failed' => 'badge-red', 'skipped' => 'badge-gray', default => 'badge-gray' } ?>"><?= e($r['status'] ?? '') ?></span></td>
                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)"><?= e(mb_substr($r['error'] ?? '', 0, 40)) ?></td>
                    <td style="font-size:12px"><?= e($r['created_at'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
