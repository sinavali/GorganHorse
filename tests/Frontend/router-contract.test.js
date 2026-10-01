/*
 * tests/Frontend/router-contract.test.js
 *
 * Purpose:
 *   Static contracts for three regressions that each broke whole pages:
 *
 *     1. Record routes were unreachable. route() tested the detail mapping
 *        with `if(id && !pageKey)`, but pageKey is always set for a known
 *        collection (/users, /clubs, /horses, /competitions, /payment-orders,
 *        /messages), so clicking a row re-rendered the grid with the id left
 *        in the URL instead of opening the record.
 *     2. The sidebar navigated with '/' + pageKey, producing /paymentOrders,
 *        /smsLog and /mySignups — none of which the pageMap knows — so those
 *        pages rendered the client-side 404. Every data-nav click must go
 *        through routePathFor().
 *     3. Printing never ran: printHtml() called a bare num(), which does not
 *        exist in ui.js (the helper is faNum), so the ReferenceError aborted
 *        preparePrint() before window.print().
 *
 * Usage: node tests/Frontend/router-contract.test.js   (exit code 0 = pass)
 */
'use strict';

var fs = require('fs');
var path = require('path');

var ROOT = path.join(__dirname, '..', '..');
var failures = [];
function check(ok, message) { if (!ok) { failures.push(message); } }

/* GHF_APP_JS / GHF_UI_JS / GHF_<FILE> overrides let a mutation test point the
   suite at copies of the shipped files, so a regression can be proven to fail
   without touching the repository. */
function read(rel) {
  var key = 'GHF_' + path.basename(rel).replace(/\./g, '_').toUpperCase();
  var override = process.env[key];
  return fs.readFileSync(override || path.join(ROOT, rel), 'utf8');
}

var app = read('public/views/assets/js/app.js');

/* 1. A path with an id must resolve to the record page, not the collection. */
check(
  /if\(id&&dmap\[key\]&&App\.pages\[dmap\[key\]\]\)/.test(app),
  "app.js: route() must send /users/12 to userDetail — the old `if(id&&!pageKey)` guard was dead code, so record links just re-rendered the grid"
);

var detailKeys = ['users', 'clubs', 'horses', 'competitions', 'payment-orders', 'messages'];
var dmap = (app.match(/var dmap=\{([\s\S]*?)\};/) || [])[1] || '';
detailKeys.forEach(function (k) {
  /* Object keys are only quoted when they need it (e.g. 'payment-orders'). */
  var has = new RegExp("(\\b|')" + k.replace(/-/g, '\\-') + "'?\\s*:").test(dmap);
  check(has, "app.js: the record map is missing '" + k + "', so /" + k + "/<id> falls back to the grid");
});

/* Every mapped detail page must actually exist and accept (root, ctx, id). */
var jsDir = path.join(ROOT, 'public', 'views', 'assets', 'js');
var DETAILS = ['userDetail', 'clubDetail', 'horseDetail', 'competitionDetail', 'paymentOrderDetail', 'messageDetail'];
var found = {};
var overrideDir = process.env.GHF_JS_DIR;
var scanDir = overrideDir || jsDir;
fs.readdirSync(scanDir).filter(function (f) { return /\.js$/.test(f); }).forEach(function (f) {
  var body = fs.readFileSync(path.join(scanDir, f), 'utf8');
  DETAILS.forEach(function (p) {
    if (new RegExp('Pages\\.' + p + '=\\{[\\s\\S]*?render:function\\(root,ctx,id\\)').test(body)) { found[p] = f; }
  });
});
DETAILS.forEach(function (p) {
  check(!!found[p], 'no SPA script defines Pages.' + p + ' with render:function(root,ctx,id), so its route renders nothing');
});

/* 2. Navigation must go through routePathFor(). */
check(
  /App\.go\(routePathFor\(b\.dataset\.nav\)\)/.test(app),
  "app.js: sidebar clicks must use routePathFor(); '/'+key produced /paymentOrders, /smsLog and /mySignups, which 404'd"
);

/* 2b. routePathFor() must be declared exactly once, at module scope.
   It used to be a local function nested inside openPalette(), so every call
   site outside that function — including the sidebar — raised
   "routePathFor is not defined" the moment a user clicked a nav entry. A
   nested copy is invisible to sibling code even though `node --check` passes. */
var rpDecls = app.match(/^[ \t]*function routePathFor\s*\(/gm) || [];
check(
  rpDecls.length === 1,
  'app.js: routePathFor() must be declared exactly once (found ' + rpDecls.length + ') — duplicate copies drift apart'
);
check(
  /^function routePathFor\s*\(/m.test(app),
  'app.js: routePathFor() must be declared at module scope (column 0); an indented copy is local to its enclosing function and is undefined to the sidebar'
);
check(
  !/\n[ \t]+function routePathFor\s*\(/.test(app),
  'app.js: an indented routePathFor() declaration exists — it is trapped inside its enclosing function and the sidebar cannot reach it'
);
check(
  /App\.pathFor\s*=\s*routePathFor/.test(app),
  'app.js: App.pathFor must stay bound to routePathFor() so existing callers keep working'
);
check(
  app.indexOf("App.go('/'+b.dataset.nav)") === -1,
  "app.js: the '/'+key navigation is still present and will 404 for camelCase page keys"
);

/* Every multi-word page key needs a routePathFor entry, or the fallback
   kebab-case rule must produce a key the pageMap actually contains. */
var pageMap = (app.match(/var pageMap=\{([\s\S]*?)\};/) || [])[1] || '';
var routeFor = (app.match(/function routePathFor\(key\)\{[\s\S]*?var m=\{([\s\S]*?)\};/) || [])[1] || '';
['paymentOrders', 'smsLog', 'horseShares', 'mySignups', 'riderCompetitions'].forEach(function (key) {
  check(
    new RegExp(key + ":'/").test(routeFor),
    'app.js: routePathFor() has no entry for ' + key + ", so its URL will not match the pageMap"
  );
});
check(
  pageMap.indexOf("'payment-orders':") > -1,
  "app.js: the pageMap must know '/payment-orders'"
);

/* 3. Printing must not reference a helper that does not exist. */
var ui = read('public/views/assets/js/ui.js');
/* printHtml() used to call a bare num(); ui.js only defines faNum(), so the
   ReferenceError aborted preparePrint() before window.print() ever ran. */
check(
  /function faNum\s*\(/.test(ui),
  'ui.js: faNum() must exist — it is what printing and the filter-bar count depend on'
);
var withoutFaNum = ui.replace(/function faNum[\s\S]*?\n\}/, '');
check(
  !/(^|[^A-Za-z0-9._])num\(/.test(withoutFaNum),
  'ui.js: a bare num() call remains — use faNum(), which wraps I18N.num()'
);
check(
  /window\.print\(\)/.test(ui),
  'ui.js: the print flow must still reach window.print()'
);

/* 4. Culture-aware exports must not be bypassed by any CSV writer. */
var engine = read('app/Services/Report/ReportEngine.php');
check(
  /exportCell/.test(engine),
  'ReportEngine::toCsv() must run cells through CultureService::exportCell() so export dates match the selected culture'
);
['app/Http/Controllers/AdminController.php', 'app/Http/Controllers/SmsTemplateController.php'].forEach(function (f) {
  check(/exportCell/.test(read(f)), f + ': its CSV export must format timestamps with CultureService::exportCell()');
});

if (failures.length) {
  console.error('router contract FAILED (' + failures.length + '):');
  failures.forEach(function (f) { console.error('  - ' + f); });
  process.exit(1);
}
console.log('router contract OK (record routes, nav URLs, print helper, culture-aware exports)');
