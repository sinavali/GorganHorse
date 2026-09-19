/* =============================================================================
 * File: public/assets/js/grid.js
 * Purpose: Thin server-driven report grid wrapper around AG Grid Community plus
 *          a resilient plain-table fallback (Technical §26.5). Persists column
 *          state per user per report in localStorage; page always resets to 1.
 * ========================================================================== */
(function () {
    'use strict';

    const STORAGE_PREFIX = 'gorgan.grid.';

    function stateKey(reportKey) {
        return STORAGE_PREFIX + reportKey;
    }

    function loadState(reportKey) {
        try { return JSON.parse(localStorage.getItem(stateKey(reportKey)) || '{}'); }
        catch (e) { return {}; }
    }

    function saveState(reportKey, state) {
        try { localStorage.setItem(stateKey(reportKey), JSON.stringify(state)); }
        catch (e) { /* ignore quota errors */ }
    }

    /** Load report data from the server. */
    async function load(config) {
        const payload = {
            report: config.report,
            page: config.page || 1,
            per_page: config.perPage || 50,
            sort: config.sort || [],
            filters: config.filters || [],
            columns: config.columns || []
        };
        return window.Panel.api('/panel/reports/data', { method: 'POST', body: payload });
    }

    /** Render a plain HTML table fallback (used when AG Grid is unavailable). */
    function renderTable(container, columns, rows) {
        const cols = columns.map(c => c.key);
        let html = '<table class="data"><thead><tr>';
        columns.forEach(c => { html += '<th>' + escapeHtml(c.label) + '</th>'; });
        html += '</tr></thead><tbody>';
        rows.forEach(row => {
            html += '<tr>';
            cols.forEach(k => {
                const v = row[k];
                html += '<td>' + escapeHtml(v === null || v === undefined ? '—' : String(v)) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function escapeHtml(v) {
        return String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    window.PanelGrid = { load, loadState, saveState, renderTable };
})();
