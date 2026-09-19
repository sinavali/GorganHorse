<?php
declare(strict_types=1);

/**
 * File: app/Views/panel/competitions.php
 *
 * Purpose: Competition list page with filters. (Blueprint §7.12).
 *
 * @var array  $view
 * @var array  $rows
 * @var int    $total
 * @var array  $filters
 * @var string $csrf
 */
?>
<h1 style="margin:0 0 16px">مسابقات</h1>

<form method="get" action="/panel/competitions" class="filters">
    <div class="field" style="margin:0">
        <label for="f-status">وضعیت</label>
        <select id="f-status" name="status">
            <?php foreach (['all' => 'همه', 'draft' => 'پیش‌نویس', 'open' => 'باز', 'closed' => 'بسته', 'running' => 'در حال اجرا', 'finished' => 'تمام', 'cancelled' => 'لغو شده'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="margin:0">
        <label for="f-venue">محل برگزاری</label>
        <input id="f-venue" type="text" name="venue_club_id" value="<?= e($filters['venue_club_id'] ?? '') ?>">
    </div>
    <div class="field" style="margin:0;flex:1;min-width:200px">
        <label for="f-q">جست‌وجو</label>
        <input id="f-q" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>">
    </div>
    <button class="btn btn-primary" type="submit">فیلتر</button>
</form>

<div x-data="calendarWidget(<?= htmlspecialchars(json_encode(array_values(array_map(function ($c) {
    return [
        'id' => (int) ($c['id'] ?? 0),
        'title' => (string) ($c['title'] ?? ''),
        'end_registration_at' => (string) ($c['end_registration_at'] ?? ''),
    ];
}, $rows)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)">
<div class="toolbar">
    <a href="/panel/competitions/create" class="btn btn-primary">ایجاد مسابقه</a>
    <button class="btn" @click="toggleCalendar()">نمایش تقویم</button>
</div>

<div x-show="showCalendar" @keydown.escape.window="showCalendar = false"
     style="display:none;margin-block-end:16px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-block-end:12px">
        <h2 style="margin:0;font-size:16px" x-text="monthName + ' ' + viewYear"></h2>
        <div style="display:flex;gap:4px">
            <button class="btn" @click="prevMonth()" style="padding:4px 10px" aria-label="ماه قبل">◀</button>
            <button class="btn" @click="nextMonth()" style="padding:4px 10px" aria-label="ماه بعد">▶</button>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px">
        <template x-for="dn in ['شن','یک','دو','سه','چهار','پنج','جمعه']" style="text-align:center;font-size:12px;color:var(--muted);padding:4px" x-text="dn"></template>
        <template x-for="d in calendarDays" style="text-align:center">
            <div style="min-height:48px;padding:2px;cursor:pointer;border-radius:4px"
                 :class="d !== null && getDayComps(d).length > 0 ? 'badge badge-blue' : ''"
                 @click="d !== null && selectDate(d)">
                <template x-if="d !== null">
                    <span style="display:inline-block;width:22px;line-height:22px;border-radius:50%" :class="selectedDate === d ? 'badge badge-green' : ''" x-text="d"></span>
                </template>
                <template x-if="d !== null && getDayComps(d).length > 0">
                    <div style="margin-top:2px">
                        <span x-for="c in getDayComps(d).slice(0,2)" style="display:block;font-size:9px;color:#2563eb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" :title="c.title" x-text="c.title"></span>
                        <span x-if="getDayComps(d).length > 2" style="font-size:9px;color:var(--muted);display:block" x-text="'+' + (getDayComps(d).length - 2)"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>
    <template x-if="selectedDate !== null">
        <div style="margin-block-start:12px;padding:8px;background:#f8fafc;border-radius:6px">
            <strong x-text="'مسابقات روز ' + selectedDate"></strong>
            <ul style="margin:4px 0 0;padding-inline-start:16px;font-size:13px">
                <template x-for="c in getDayComps(selectedDate)" style="display:block">
                    <li>
                        <a :href="'/panel/competitions/' + c.id" x-text="c.title"></a>
                        <span style="color:var(--muted)" x-text="c.end_registration_at"></span>
                    </li>
                </template>
            </ul>
            <template x-if="getDayComps(selectedDate).length === 0">
                <span style="font-size:12px;color:var(--muted)">هیچ مسابقه‌ای</span>
            </template>
        </div>
    </template>
</div>
</div>

<script>
function calendarWidget(comps) {
    return {
        showCalendar: false,
        toggleCalendar: function () { this.showCalendar = !this.showCalendar; },
        viewMonth: new Date().getMonth(),
        viewYear: new Date().getFullYear(),
        selectedDate: null,
        monthNames: ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'],
        comps: comps || [],
        get daysInMonth() { return new Date(this.viewYear, this.viewMonth + 1, 0).getDate(); },
        get firstDay() { return new Date(this.viewYear, this.viewMonth, 1).getDay(); },
        get monthName() { return this.monthNames[this.viewMonth] || ''; },
        get calendarDays() {
            var days = [];
            for (var i = 0; i < this.firstDay; i++) days.push(null);
            for (var d = 1; d <= this.daysInMonth; d++) days.push(d);
            return days;
        },
        getDayComps: function (day) {
            if (!day) return [];
            var self = this;
            return this.comps.filter(function (c) {
                var dt = new Date(c.end_registration_at || '1970-01-01T00:00:00Z');
                return dt.getUTCFullYear() === self.viewYear && dt.getUTCMonth() === self.viewMonth && dt.getUTCDate() === day;
            });
        },
        prevMonth: function () {
            this.viewMonth--;
            if (this.viewMonth < 0) { this.viewMonth = 11; this.viewYear--; }
            this.selectedDate = null;
        },
        nextMonth: function () {
            this.viewMonth++;
            if (this.viewMonth > 11) { this.viewMonth = 0; this.viewYear++; }
            this.selectedDate = null;
        },
        selectDate: function (day) {
            this.selectedDate = this.selectedDate === day ? null : day;
        }
    };
}
</script>

<?php if (empty($rows)): ?>
    <div class="empty-state">مسابقه‌ای یافت نشد. <a href="/panel/competitions/create">ایجاد مسابقه</a></div>
<?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" aria-label="انتخاب همه"></th>
                <th>عنوان</th>
                <th>محل</th>
                <th>پنجره ثبت‌نام</th>
                <th>تاریخ شروع</th>
                <th>تعداد رده</th>
                <th>تعداد ثبت‌نام</th>
                <th>وضعیت</th>
                <th>نتایج</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= (int) ($c['id'] ?? 0) ?>"></td>
                    <td><a href="/panel/competitions/<?= (int) ($c['id'] ?? 0) ?>"><?= e($c['title'] ?? '') ?></a></td>
                    <td><?= e($c['venue_name'] ?? '') ?></td>
                    <td><?= e($view['date']($c['start_registration_at'] ?? null)) ?> - <?= e($view['date']($c['end_registration_at'] ?? null)) ?></td>
                    <td><?= e($view['date']($c['start_at'] ?? null)) ?></td>
                    <td><?= (int) ($c['rade_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['signup_count'] ?? 0) ?></td>
                    <td><span class="badge <?= match ($c['status'] ?? '') { 'open' => 'badge-green', 'draft' => 'badge-yellow', 'closed' => 'badge-gray', 'running' => 'badge-green', 'finished' => 'badge-gray', 'cancelled' => 'badge-red', default => 'badge-gray' } ?>"><?= e($c['status'] ?? '') ?></span></td>
                    <td><span class="badge <?= ($c['results_status'] ?? 'draft') === 'published' ? 'badge-green' : ($c['results_status'] === 'confirmed' ? 'badge-yellow' : 'badge-gray') ?>"><?= e($c['results_status'] ?? 'draft') ?></span></td>
                    <td><a class="btn" href="/panel/competitions/<?= (int) ($c['id'] ?? 0) ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="bulk-bar" id="bulk-bar" style="display:none;margin-top:12px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:8px">
        <span id="bulk-count">0 مورد انتخاب شده</span>
        <button class="btn" data-bulk="publish">انتشار</button>
        <button class="btn btn-danger" data-bulk="cancel">لغو</button>
        <button class="btn" data-bulk="export">خروجی CSV</button>
    </div>
<?php endif; ?>

<script>
(function () {
    var selectAll = document.getElementById('select-all');
    if (selectAll) selectAll.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = selectAll.checked; });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(function (cb) { cb.addEventListener('change', updateBulkBar); });
    function updateBulkBar() {
        var checked = document.querySelectorAll('.row-check:checked');
        var bar = document.getElementById('bulk-bar');
        if (checked.length > 0) { bar.style.display = 'block'; document.getElementById('bulk-count').textContent = checked.length + ' مورد'; }
        else { bar.style.display = 'none'; }
    }
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-bulk');
            var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
            if (!window.Panel.confirmAction('اعمال ' + btn.textContent.trim() + '؟')) return;
            window.Panel.api('/panel/competitions/bulk', { method: 'POST', body: { action: action, ids: ids } })
                .then(function () { window.location.reload(); }).catch(function () {});
        });
    });
})();
</script>
