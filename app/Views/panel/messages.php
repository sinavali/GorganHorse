<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/messages.php
 *
 * Purpose: Broadcast messages list (staff) and inbox (riders).
 * (Blueprint §14.4).
 *
 * @var array  $view
 * @var array  $rows
 * @var bool   $is_staff
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px"><?= $is_staff ? 'پیام‌ها' : 'صندوق ورودی' ?></h1>

<?php if ($is_staff): ?>
<div class="toolbar">
    <button class="btn btn-primary" data-action="compose">ارسال پیام</button>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="empty-state">هیچ پیامی وجود ندارد.</div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th>موضوع</th>
                <th>فرستنده</th>
                <th>مخاطب</th>
                <th>تاریخ ارسال</th>
                <th>نرخ تحویل</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $m): ?>
                <tr>
                    <td><a href="/panel/messages/<?= (int) ($m['id'] ?? 0) ?>"><?= e($m['subject'] ?? '') ?></a></td>
                    <td><?= e($m['sender_name'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($m['scope'])): ?>
                            <?= e($m['scope'] === 'global' ? 'همه سوارکاران' : ($m['scope'] === 'competition' ? 'یک مسابقه' : ($m['scope'] === 'rade' ? 'یک رده' : 'کاربران انتخابی'))) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($view['date']($m['sent_at'] ?? $m['created_at'] ?? null)) ?></td>
                    <td><?= e($m['delivery_rate'] ?? '') ?></td>
                    <td><a class="btn" href="/panel/messages/<?= (int) ($m['id'] ?? 0) ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- Compose Modal -->
<?php if ($is_staff): ?>
<div id="compose-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:100">
    <div class="auth-card" style="max-width:500px;width:90%">
        <h2 style="margin:0 0 16px;font-size:18px">ارسال پیام</h2>
        <form id="compose-form" method="post" action="/panel/messages" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="field">
                <label for="msg-subject" class="required">موضوع</label>
                <input id="msg-subject" name="subject" type="text" required>
            </div>
            <div class="field">
                <label for="msg-body" class="required">متن</label>
                <textarea id="msg-body" name="body" rows="5" required></textarea>
            </div>
            <div class="field">
                <label for="msg-scope">مخاطب</label>
                <select id="msg-scope" name="scope">
                    <option value="global">همه سوارکاران</option>
                    <option value="competition">یک مسابقه</option>
                    <option value="rade">یک رده</option>
                    <option value="selected">کاربران انتخابی</option>
                </select>
            </div>
            <div class="field">
                <label for="msg-competition">مسابقه</label>
                <select id="msg-competition" name="competition_id"><option value="">بدون</option></select>
            </div>
            <div class="toolbar">
                <button type="submit" class="btn btn-primary">ارسال</button>
                <button type="button" class="btn" data-action="close-compose">انصراف</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('[data-action="compose"]').forEach(function (btn) {
    btn.addEventListener('click', function () { document.getElementById('compose-modal').style.display = 'flex'; });
});
document.querySelectorAll('[data-action="close-compose"]').forEach(function (btn) {
    btn.addEventListener('click', function () { document.getElementById('compose-modal').style.display = 'none'; });
});
</script>
<?php endif; ?>
