/*
 * tests/Frontend/inbox-contract.test.js
 *
 * Purpose:
 *   Actually RENDERS the notifications and messages pages — the real shipped
 *   pages-core.js, executed against the DOM stub — instead of grepping their
 *   source. Two bugs slipped past a static check:
 *
 *     1. Notifications threw "Cannot read properties of undefined (reading
 *        'forEach')". The `type` select is lazy, so its loader runs while
 *        filterBar() is still being built — before the `var allNotes=[]`
 *        assignment that appeared *below* it. Hoisted `var` means `allNotes`
 *        was still undefined at that moment.
 *     2. Messages never issued a request: render() defined load() but never
 *        called it, so the page stayed an empty shell.
 *
 *   Both are runtime scoping/ordering faults that `node --check` cannot see,
 *   so this suite runs the page and asserts it neither throws nor skips its
 *   GET.
 *
 * Usage: node tests/Frontend/inbox-contract.test.js   (exit code 0 = pass)
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');
var dom = require('./dom-stub.js');

var ROOT = path.join(__dirname, '..', '..');
var JS = process.env.GHF_JS_DIR || path.join(ROOT, 'public', 'views', 'assets', 'js');

var failures = [];
function check(ok, message) { if (!ok) { failures.push(message); } }

var SPA = ['i18n.js', 'api.js', 'ui.js', 'auth.js', 'pages-core.js', 'pages-horses.js',
  'pages-events.js', 'pages-finance.js', 'pages-system.js', 'app.js'];

function mkNode(tag) {
  var n = dom.makeNode(tag || 'div', {});
  n.getBoundingClientRect = function () { return { top: 0, bottom: 0, left: 0, right: 0 }; };
  n.scrollIntoView = function () {};
  n.closest = function () { return null; };
  n.insertAdjacentHTML = function (pos, html) {
    if (pos === 'afterbegin') { n.children = dom.parse(html).children.concat(n.children); }
  };
  return n;
}

var view = mkNode('div');
var body = mkNode('body');
body.children.push(view);

var sandbox = {
  console: console,
  setTimeout: function () { return 0; }, clearTimeout: function () {},
  setInterval: function () { return 0; }, clearInterval: function () {},
  addEventListener: function () {}, removeEventListener: function () {},
  innerWidth: 1400, innerHeight: 900, scrollX: 0, scrollY: 0,
  document: {
    documentElement: mkNode('html'), body: body, cookie: '',
    createElement: function (t) { return mkNode(t); },
    getElementById: function (id) { return id === 'view' ? view : mkNode('div'); },
    querySelector: function (sel) { return dom.findAll(view, sel)[0] || null; },
    querySelectorAll: function (sel) { return dom.findAll(view, sel); },
    addEventListener: function () {}, removeEventListener: function () {}
  },
  location: { pathname: '/notifications', search: '', hash: '', href: 'http://x/', reload: function () {} },
  history: { pushState: function () {}, replaceState: function () {}, back: function () {} },
  navigator: { userAgent: 'node' },
  localStorage: { getItem: function () { return null; }, setItem: function () {} },
  Blob: function () {}, FormData: function () {},
  Event: function (t) { this.type = t; }, MouseEvent: function () {}, DOMParser: function () {},
  MutationObserver: function () { return { observe: function () {}, disconnect: function () {} }; },
  Intl: Intl, Date: Date, Math: Math, JSON: JSON, Promise: Promise, Object: Object, Array: Array,
  String: String, Number: Number, parseInt: parseInt, parseFloat: parseFloat, isNaN: isNaN,
  encodeURIComponent: encodeURIComponent, decodeURIComponent: decodeURIComponent,
  screen: { width: 1400 }, getComputedStyle: function () { return {}; }, alert: function () {}
};
sandbox.window = sandbox; sandbox.self = sandbox; sandbox.globalThis = sandbox;
vm.createContext(sandbox);

SPA.forEach(function (f) {
  vm.runInContext(fs.readFileSync(path.join(JS, f), 'utf8'), sandbox, { filename: f });
});

/* Every request the pages make, with representative payloads. */
var calls = [];
sandbox.API.get = function (p) {
  calls.push(p);
  var data = {};
  if (p === '/panel/notifications') {
    data = { rows: [{ id: 1, type: 'horse.shared', title: 'درخواست', body: 'متن', is_read: 0, link: '/panel/horses/1', created_at: '2026-09-30T10:00:00Z' }], unread: 1 };
  }
  if (p === '/panel/messages') {
    data = { rows: [{ id: 12, subject: 'اطلاعیه', body: 'متن', scope: 'global', recipient_count: 3, created_at: '2026-09-30T10:00:00Z' }] };
  }
  return Promise.resolve(data);
};
sandbox.API.post = function () { return Promise.resolve({}); };
sandbox.App.user = { role: 'admin', id: 1, username: 'admin' };
sandbox.App.seed = function (f) { return f; };
sandbox.App.setQuery = function () {};
sandbox.App.go = function () {};

function render(page, expectedGet) {
  calls.length = 0;
  var root = mkNode('div');
  var err = null;
  try {
    sandbox.Pages[page].render(root, sandbox.App, null, null);
  } catch (e) {
    err = e;
  }
  check(!err, page + ': render() threw — ' + (err && err.message));
  if (!err) {
    check(calls.indexOf(expectedGet) > -1,
      page + ': render() never requested ' + expectedGet + ' (got: ' + JSON.stringify(calls) + ')');
  }
}

render('notifications', '/panel/notifications');
render('messages', '/panel/messages');

/* The narrow-screen rules are easy to delete by accident, and there is no CSS
   linter in this project. Pin the breakpoints and the rules that actually stop
   the layout overflowing a phone. */
var css = fs.readFileSync(path.join(ROOT, 'public', 'views', 'assets', 'css', 'app.css'), 'utf8');
['max-width:1100px', 'max-width:900px', 'max-width:720px', 'max-width:640px', 'max-width:420px'].forEach(function (bp) {
  check(css.indexOf('@media(' + bp) > -1, 'app.css: the ' + bp + ' breakpoint is missing');
});
[
  [/max-width:720px\)\{[^@]*?\.rl\{flex-wrap:wrap\}/, 'app.css: .rl must wrap below 720px or long rows push their button off-screen'],
  [/max-width:640px\)\{[^@]*?\.grid-dl\{grid-template-columns:1fr/, 'app.css: .grid-dl must stack below 640px or record pages squeeze their values'],
  [/max-width:640px\)\{[^@]*?\.toast\{min-width:0/, 'app.css: toasts must drop their 250px min-width below 640px'],
  [/max-width:420px\)\{[^@]*?\.fbar \.spk\{min-width:0!important/, 'app.css: filter-bar controls must be allowed to shrink below 420px']
].forEach(function (rule) {
  check(rule[0].test(css), rule[1]);
});

if (failures.length) {
  console.error('inbox contract FAILED (' + failures.length + '):');
  failures.forEach(function (f) { console.error('  - ' + f); });
  process.exit(1);
}
console.log('inbox contract OK (notifications and messages render and fetch their data)');
