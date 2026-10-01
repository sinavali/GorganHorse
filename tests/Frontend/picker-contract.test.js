/*
 * tests/Frontend/picker-contract.test.js
 *
 * Purpose:
 *   Executes the panel's REAL UI.filterBar(), UI.pickField() and UI.initPicks()
 *   against the DOM stub, and pins the four contracts whose breakage each
 *   blanked or froze a whole page:
 *
 *     1. UI.filterBar() must RETURN the element that carries setCount /
 *        hasFilters / onClear / clearAction / redraw. Attaching the helpers to
 *        a separate wrapper made `fbar.setCount(...)` a TypeError thrown inside
 *        load() — which is why grids kept their loading skeleton even though the
 *        request had returned 200 with records.
 *     2. chips() may call liveLabel(), so liveLabel() must be in scope wherever
 *        chips() is called from, not nested inside draw().
 *     3. pickField() must emit well-formed markup: the data-spk-opts attribute
 *        has to be CLOSED. Without the closing ">" the attribute swallowed the
 *        rest of the block, initPicks() hit its !hidden guard and bailed, and
 *        the picker rendered with no options at all.
 *     4. initPicks() must resolve a picker's option list, so a picker built
 *        from static options shows those options when focused.
 *
 * Usage: node tests/Frontend/picker-contract.test.js   (exit code 0 = pass)
 */
'use strict';

var fs = require('fs');
var path = require('path');
var dom = require('./dom-stub.js');
var testSrc = fs.readFileSync(path.join(__dirname, 'filter-bar.test.js'), 'utf8');

/* GHF_UI_JS lets a mutation test point the suite at a copy of ui.js, so a
   regression can be proven to fail without touching the shipped file. */
var UI_JS = process.env.GHF_UI_JS ||
  path.join(__dirname, '..', '..', 'public', 'views', 'assets', 'js', 'ui.js');
var src = fs.readFileSync(UI_JS, 'utf8');

/* Reuse the brace-matching extractor from the sibling suite so both tests
   exercise the shipped source rather than a copy. */
var extractFn = new Function(
  'return ' + testSrc.match(/function extractFn[\s\S]*?\n}\n/)[0].replace('function extractFn', 'function extractFn')
)();

global.document = dom.documentStub;
global.window = {
  addEventListener: function () {},
  innerWidth: 1200, scrollX: 0, scrollY: 0,
  I18N: { num: function (v) { return String(v); }, money: function (v) { return String(v); } }
};
global.Event = function (t) { this.type = t; };
global.MouseEvent = function () {};
global.setTimeout = function () { return 0; };
global.localStorage = { getItem: function () { return null; }, setItem: function () {} };

var NEEDED = ['el', 'esc', 'ic', 'faNum', 'field', 'debounce', 'rows', 'savedViews', 'hostJson', 'initPicks', 'pickField', 'filterBar'];
var code = NEEDED.map(function (n) { return extractFn(src, n); }).join('\n\n');
code += '\n\nvar pickRegistry = {};\nvar UI = { esc: esc };\n';

var api = new Function(code + '\nreturn { filterBar: filterBar, pickField: pickField, initPicks: initPicks };')();

var failures = [];
function check(ok, message) { if (!ok) { failures.push(message); } }

/* ---------- 1. filterBar() returns the helper-carrying element ---------- */

var bar = api.filterBar({
  filters: { search: '', role: '', status: '', page: 1 },
  searchLabel: 'جستجو…',
  selects: [
    { key: 'role', label: 'نقش', options: [{ v: '', l: 'همه' }, { v: 'admin', l: 'مدیر' }] },
    { key: 'status', label: 'وضعیت', options: [{ v: '', l: 'همه' }, { v: 'ok', l: 'فعال' }] }
  ],
  onChange: function () {}
});

['setCount', 'hasFilters', 'onClear', 'redraw'].forEach(function (fn) {
  check(typeof bar[fn] === 'function', 'filterBar() return value is missing ' + fn + '() — pages call fbar.' + fn + '()');
});
check(typeof bar.clearAction === 'string' && bar.clearAction.indexOf('data-clear-filters') > -1,
  'filterBar() return value is missing the clearAction markup');

/* setCount must actually write the live count into the rendered bar. */
if (typeof bar.setCount === 'function') {
  try {
    bar.setCount('۱۲ کاربر');
    var countEl = bar.querySelector('.fbar-count');
    check(!!countEl, 'filterBar() rendered no .fbar-count node for setCount() to fill');
    check(countEl && countEl.textContent === '۱۲ کاربر',
      'setCount() did not write to .fbar-count (got: ' + (countEl && countEl.textContent) + ')');
  } catch (err) {
    failures.push('fbar.setCount() threw: ' + err.message);
  }
}

if (typeof bar.hasFilters === 'function') {
  check(bar.hasFilters() === false, 'hasFilters() should be false for an untouched filter set');
}

/* ---------- 2. liveLabel() is reachable from chips() ---------- */

/* A select that already holds a value makes chips() call liveLabel(key). When
   liveLabel lived inside draw() this was a ReferenceError, which aborted the
   whole bar — the ranking page's "limit" select hits this on first render. */
try {
  var valued = api.filterBar({
    filters: { limit: '100', page: 1 },
    search: false,
    selects: [{ key: 'limit', label: 'N', options: [{ v: '25', l: 'top-25' }, { v: '100', l: 'top-100' }] }],
    onChange: function () {}
  });
  if (typeof valued.hasFilters === 'function') {
    check(valued.hasFilters() === true, 'hasFilters() should be true when a select has a value');
  }
  var chips = valued.querySelector('.fbar-chips');
  check(!!chips && chips.innerHTML.indexOf('top-100') > -1,
    'a select holding a value produced no filter chip (liveLabel path did not run)');
} catch (err) {
  failures.push('filterBar() threw while rendering chips for a valued select: ' + err.message);
}

/* onClear() must reset the same state the bar reports through hasFilters(). */
if (typeof bar.onClear === 'function' && typeof bar.hasFilters === 'function') {
  try {
    bar.onClear();
    check(bar.hasFilters() === false, 'onClear() left filters active');
  } catch (err) {
    failures.push('fbar.onClear() threw: ' + err.message);
  }
}

/* ---------- 3. pickField() emits well-formed markup ---------- */

var list = [{ code: 'fa-IR', name: 'فارسی' }, { code: 'en-US', name: 'English' }];
var host = dom.makeNode('div', {});
host.innerHTML = api.pickField({
  name: 'culture',
  label: 'زبان',
  value: 'fa-IR',
  options: list.map(function (c) { return { v: c.code, l: c.name + ' (' + c.code + ')' }; })
});

var block = host.querySelector('[data-spk]');
check(!!block, 'pickField() produced no [data-spk] block');
if (block) {
  /* The unterminated-attribute bug hid here: the JSON ran into the hidden
     input, so the block lost its hidden input and .spk-pop nesting. */
  check(!!block.querySelector('input[type=hidden]'),
    'pickField() block has no hidden input — the data-spk-opts attribute is most likely unterminated');
  check(!!block.querySelector('.spk-pop'),
    'pickField() block has no .spk-pop — initPicks() has nowhere to render options');
  check(!!block.querySelector('.spk-inp'),
    'pickField() block has no .spk-inp');

  var raw = block.getAttribute('data-spk-opts');
  try {
    var parsed = JSON.parse(raw);
    check(Array.isArray(parsed) && parsed.length === list.length,
      'data-spk-opts did not round-trip the option list (got: ' + raw + ')');
  } catch (err) {
    failures.push('data-spk-opts is not valid JSON: ' + err.message + ' (raw: ' + raw + ')');
  }
}

/* ---------- 4. initPicks() actually wires a static picker ---------- */

try {
  api.initPicks(host, {});
} catch (err) {
  failures.push('initPicks() threw on a pickField() block: ' + err.message);
}

if (block) {
  var input = block.querySelector('.spk-inp');
  var pop = block.querySelector('.spk-pop');
  check(!!input && !!input._listeners.focus,
    'initPicks() did not attach a focus handler — the dropdown cannot open');
  if (input && input._listeners.focus) {
    try {
      input._listeners.focus.forEach(function (fn) { fn.call(input); });
      check(!!pop && pop.hidden === false, 'focusing the picker left the option list hidden');
      check(!!pop && pop.children.length === list.length,
        'focusing the picker rendered ' + (pop ? pop.children.length : 0) + ' options, expected ' + list.length);
    } catch (err) {
      failures.push('opening the picker threw: ' + err.message);
    }
  }
}

if (failures.length) {
  console.error('picker contract FAILED (' + failures.length + '):');
  failures.forEach(function (f) { console.error('  - ' + f); });
  process.exit(1);
}
console.log('picker contract OK (filterBar helpers on the returned element, chips/liveLabel wired, pickField options render)');
