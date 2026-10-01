/*
 * tests/Frontend/filter-bar.test.js
 *
 * Purpose:
 *   Executes the panel's REAL UI.filterBar() and UI.initPicks() (extracted
 *   verbatim from public/views/assets/js/ui.js) against the DOM stub, and
 *   asserts the picker-markup contract that broke every grid page:
 *
 *     initPicks() reads host.querySelector('input[type=hidden]').value, so any
 *     [data-spk] block that omits the hidden input throws
 *     "Cannot read properties of null (reading 'value')" while the filter bar
 *     is being built — which blanks the page it belongs to.
 *
 *   The contract:
 *     - every [data-spk] block carries a hidden input AND a .spk-pop;
 *     - the search box is a plain text input and must NOT claim data-spk
 *       (it has no dropdown, so wiring it as a picker is meaningless).
 *
 *   Why extract instead of copy: the test must exercise the shipped code, so a
 *   future edit to ui.js is what gets verified, not a stale duplicate here.
 *
 * Usage: node tests/Frontend/filter-bar.test.js   (exit code 0 = pass)
 */
'use strict';

var fs = require('fs');
var path = require('path');
var dom = require('./dom-stub.js');

var UI_JS = path.join(__dirname, '..', '..', 'public', 'views', 'assets', 'js', 'ui.js');
var src = fs.readFileSync(UI_JS, 'utf8');

/* ------------------------------------------------------------------ *
 * Brace-matching function extractor (skips strings, regexes, comments)
 * ------------------------------------------------------------------ */

/**
 * Extract `function name(...){...}` from source by matching braces.
 *
 * @param {string} source Source text.
 * @param {string} name   Function name.
 * @return {string} The complete function source.
 */
function extractFn(source, name) {
  var start = source.indexOf('function ' + name + '(');
  if (start === -1) { throw new Error('ui.js: function ' + name + ' not found'); }
  var i = source.indexOf('{', start);
  var depth = 0;
  var prevSig = '';
  var SIG = '([{,;:=!&|?+-*%<>~^';

  for (; i < source.length; i++) {
    var ch = source[i], nx = source[i + 1];

    if (ch === '/' && nx === '/') { while (i < source.length && source[i] !== '\n') { i++; } continue; }
    if (ch === '/' && nx === '*') {
      i += 2;
      while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) { i++; }
      i++; continue;
    }
    // '/' after a signature character starts a regex literal, not division.
    if (ch === '/' && SIG.indexOf(prevSig) > -1) {
      i++; var inClass = false;
      while (i < source.length) {
        if (source[i] === '\\') { i += 2; continue; }
        if (source[i] === '[') { inClass = true; }
        else if (source[i] === ']') { inClass = false; }
        else if (source[i] === '/' && !inClass) { break; }
        i++;
      }
      prevSig = 'x';
      continue;
    }
    if (ch === "'" || ch === '"') {
      var q = ch; i++;
      while (i < source.length) {
        if (source[i] === '\\') { i += 2; continue; }
        if (source[i] === q) { break; }
        i++;
      }
      prevSig = 'x'; continue;
    }
    if (ch === '`') {
      i++; var tplDepth = 0;
      while (i < source.length) {
        if (source[i] === '\\') { i += 2; continue; }
        if (source[i] === '`') { break; }
        if (source[i] === '$' && source[i + 1] === '{') { tplDepth++; i++; continue; }
        if (source[i] === '}' && tplDepth > 0) { tplDepth--; i++; continue; }
        i++;
      }
      prevSig = 'x'; continue;
    }
    if (ch === '{') { depth++; prevSig = '{'; continue; }
    if (ch === '}') {
      depth--;
      if (depth === 0) { return source.slice(start, i + 1); }
      prevSig = '}'; continue;
    }
    if (!/\s/.test(ch)) { prevSig = ch; }
  }
  throw new Error('ui.js: unbalanced braces in ' + name);
}

/* ------------------------------------------------------------------ *
 * Globals the extracted helpers expect
 * ------------------------------------------------------------------ */

global.document = dom.documentStub;
global.window = {
  addEventListener: function () {},
  innerWidth: 1200,
  scrollX: 0,
  scrollY: 0,
  I18N: { num: function (v) { return String(v); }, money: function (v) { return String(v); } }
};
global.Event = function (t) { this.type = t; };
global.MouseEvent = function () {};
global.setTimeout = function () { return 0; };
global.localStorage = { getItem: function () { return null; }, setItem: function () {} };

/* ------------------------------------------------------------------ *
 * Link the real helpers together
 * ------------------------------------------------------------------ */

var NEEDED = ['el', 'esc', 'ic', 'faNum', 'debounce', 'rows', 'savedViews', 'hostJson', 'initPicks', 'filterBar'];
var code = NEEDED.map(function (n) { return extractFn(src, n); }).join('\n\n');
code += '\n\nvar pickRegistry = {};\n';

var api = new Function(code + '\nreturn { filterBar: filterBar, initPicks: initPicks };')();

/* ------------------------------------------------------------------ *
 * Assertions
 * ------------------------------------------------------------------ */

var failures = [];
function check(ok, message) { if (!ok) { failures.push(message); } }

/** Build a filter bar with the same shape the grid pages use. */
function buildBar() {
  var state = { search: '', role: '', status: '', page: 1 };
  return api.filterBar({
    filters: state,
    views: 'users',
    searchLabel: 'جستجوی نام، موبایل…',
    selects: [
      { key: 'role', label: 'نقش', options: [{ v: '', l: 'همه' }, { v: 'rider', l: 'سوارکار' }] },
      { key: 'status', label: 'وضعیت', options: [{ v: '', l: 'همه' }, { v: 'verified', l: 'تأییدشده' }] }
    ],
    onChange: function () {}
  });
}

var bar = buildBar();
var html = bar.innerHTML;

check(html.length > 0, 'filterBar produced no markup');

// 1. Structural contract: every [data-spk] block is fully wired.
var blocks = html.split('<div class="spk"').slice(1).map(function (s) { return '<div class="spk"' + s; });
check(blocks.length >= 3, 'expected a search box plus at least two selects, got ' + blocks.length + ' .spk blocks');

blocks.forEach(function (b, i) {
  var isSearch = b.indexOf('data-spk-q') > -1;
  var key = isSearch ? 'search#' + i : ((b.match(/data-spk="([^"]+)"/) || [])[1] || ('block#' + i));
  if (isSearch) {
    check(b.indexOf('data-spk=') === -1, 'search box must not declare data-spk (it is not a picker)');
    check(b.indexOf('class="inp spk-inp"') > -1, 'search box lost its input');
    return;
  }
  check(b.indexOf('input type="hidden"') > -1, key + ': missing hidden input (initPicks reads hidden.value)');
  check(b.indexOf('spk-pop') > -1, key + ': missing .spk-pop (initPicks renders the list into it)');
});

// 2. Behavioural contract: wiring the built bar must not throw.
try {
  api.initPicks(dom.parse(html), {
    __sel_role: { options: [{ v: '', l: 'همه' }, { v: 'rider', l: 'سوارکار' }] },
    __sel_status: { options: [{ v: '', l: 'همه' }, { v: 'verified', l: 'تأییدشده' }] }
  });
} catch (err) {
  failures.push('initPicks threw while wiring the built bar: ' + err.message);
}

// 3. A filter bar without any selects must also build (single-search case).
try {
  var bare = api.filterBar({
    filters: { search: '', page: 1 },
    searchLabel: 'جستجو…',
    selects: [],
    onChange: function () {}
  });
  check(bare.innerHTML.indexOf('data-spk-q') > -1, 'search-only filter bar lost its search box');
} catch (err) {
  failures.push('filterBar threw for a selects-less bar: ' + err.message);
}

if (failures.length) {
  console.error('filter-bar contract FAILED (' + failures.length + '):');
  failures.forEach(function (f) { console.error('  - ' + f); });
  process.exit(1);
}
console.log('filter-bar contract OK (' + blocks.length + ' .spk blocks, picker wiring clean)');