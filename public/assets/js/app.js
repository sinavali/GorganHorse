/* =============================================================================
 * File: public/assets/js/app.js
 * Purpose: Panel front-end bootstrap: CSRF injection, fetch helper, toast region,
 *          confirmation prompts, sidebar toggle, and Shamsi/Persian-digit input
 *          normalization (Technical §26.4, User Usage §16).
 *
 * No build step. Plain ES2020. Alpine.js is vendored separately and loaded first.
 * ========================================================================== */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    /** Toast region (created on demand). */
    function toastRegion() {
        let region = document.querySelector('.toast-region');
        if (!region) {
            region = document.createElement('div');
            region.className = 'toast-region';
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }
        return region;
    }

    /** Show a toast notification. */
    function toast(message, type = 'info', timeout = 5000) {
        const el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.setAttribute('role', 'status');
        el.textContent = message;
        toastRegion().appendChild(el);
        if (type !== 'error' || timeout > 0) {
            setTimeout(() => el.remove(), timeout || 5000);
        }
        return el;
    }

    /** Fetch wrapper that injects CSRF and parses the JSON envelope. */
    async function api(url, options = {}) {
        const opts = Object.assign({ method: 'GET', headers: {} }, options);
        opts.headers['Accept'] = 'application/json';
        opts.headers['X-CSRF-Token'] = CSRF;
        if (opts.body && !(opts.body instanceof FormData)) {
            opts.headers['Content-Type'] = 'application/json';
            if (typeof opts.body !== 'string') opts.body = JSON.stringify(opts.body);
        }
        const res = await fetch(url, opts);
        const data = await res.json().catch(() => ({ ok: false }));
        if (!res.ok || data.ok === false) {
            const err = data.errors && data.errors[0];
            const msg = err ? err.message : 'خطا در انجام عملیات';
            toast(msg, 'error', 0);
            throw Object.assign(new Error(msg), { envelope: data, status: res.status });
        }
        if (data.flash && data.flash.success) toast(data.flash.success, 'success');
        return data;
    }

    /** Request a typed confirmation string (G04). */
    function confirmTyped(expected) {
        const entered = window.prompt('برای تایید، عبارت زیر را وارد کنید:\n' + expected);
        return entered === expected;
    }

    /** Simple confirmation dialog. */
    function confirmAction(message) {
        return window.confirm(message || 'آیا مطمئن هستید؟');
    }

    /** Normalize Persian/Arabic digits to Latin. */
    function normalizeDigits(value) {
        return (value || '')
            .replace(/[\u06F0-\u06F9]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48))
            .replace(/[\u0660-\u0669]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48));
    }

    // Sidebar toggle (mobile).
    document.addEventListener('click', function (e) {
        const toggle = e.target.closest('[data-sidebar-toggle]');
        if (toggle) {
            document.querySelector('.panel-sidebar')?.classList.toggle('open');
        }
        const action = e.target.closest('[data-action]');
        if (action) {
            const kind = action.getAttribute('data-action');
            if (kind === 'logout') {
                e.preventDefault();
                api('/auth/logout', { method: 'POST' }).then(() => { window.location.href = '/auth/login'; });
            }
        }
    });

    // Digit normalization on inputs marked data-numeric.
    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-numeric]')) {
            const pos = e.target.selectionStart;
            e.target.value = normalizeDigits(e.target.value);
            e.target.setSelectionRange(pos, pos);
        }
    });

    // Expose a small global API for inline Alpine components.
    window.Panel = { api, toast, confirmTyped, confirmAction, normalizeDigits, csrf: CSRF };

    // Countdown timer Alpine component (registration deadline).
    if (typeof Alpine !== 'undefined') {
        Alpine.data('countdownTimer', function (isoDate) {
            return {
                closed: false,
                display: '--',
                label: 'تا ثبت‌نام',
                timer: null,
                init: function () {
                    var self = this;
                    if (!isoDate) { this.display = 'بدون مهلت'; return; }
                    var target = new Date(isoDate).getTime();
                    if (isNaN(target)) { this.display = 'تاریخ نامعتبر'; return; }
                    var update = function () {
                        var diff = target - Date.now();
                        if (diff <= 0) {
                            self.closed = true;
                            self.display = 'ثبت‌نام بسته شده';
                            self.label = 'ثبت‌نام';
                            clearInterval(self.timer);
                            return;
                        }
                        var d = Math.floor(diff / 86400000);
                        var h = Math.floor((diff % 86400000) / 3600000);
                        var m = Math.floor((diff % 3600000) / 60000);
                        var s = Math.floor((diff % 60000) / 1000);
                        var parts = [];
                        if (d > 0) parts.push(d + ' روز');
                        parts.push(String(h).padStart(2, '0') + ' ساعت');
                        parts.push(String(m).padStart(2, '0') + ' دقیقه');
                        parts.push(String(s).padStart(2, '0') + ' ثانیه');
                        self.display = parts.join(' ');
                    };
                    update();
                    self.timer = setInterval(update, 1000);
                },
                destroy: function () {
                    if (this.timer) clearInterval(this.timer);
                }
            };
        });
    }
})();
