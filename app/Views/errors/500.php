<?php
declare(strict_types=1);
/** @var array $view */
/** @var string $request_id */
?>
<div style="text-align:center;padding:48px">
    <h1 style="font-size:48px;margin:0 0 8px;color:#b91c1c">۵۰۰</h1>
    <p>خطای سرور رخ داده است. لطفا بعدا تلاش کنید.</p>
    <p style="font-size:12px;color:var(--muted)">شناسه درخواست: <?= e($request_id ?? '') ?></p>
    <p><a href="/panel" class="btn btn-primary">بازگشت به پنل</a></p>
</div>