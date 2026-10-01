/*
 * tests/Frontend/spa-contract.test.js
 *
 * Purpose:
 *   Static contract checks for the installer flow, which previously answered
 *   GET /install with raw JSON instead of a page.
 *
 *   Asserted:
 *     1. public/index.php serves the SPA shell at /install (it is in the SPA
 *        path list) and does NOT treat /install as an API prefix.
 *     2. app.js mirrors that decision: '/install' is absent from
 *        API_PREFIXES, so internal links to /install are not swallowed by the
 *        History-API router.
 *     3. The installer screen reads its checklist from /panel/requirements,
 *        and the old GET /install call is gone from every SPA script.
 *     4. POST /install/run is still declared as a JSON route.
 *
 * Usage: node tests/Frontend/spa-contract.test.js   (exit code 0 = pass)
 */
'use strict';

var fs = require('fs');
var path = require('path');

var ROOT = path.join(__dirname, '..', '..');
var JS_DIR = path.join(ROOT, 'public', 'views', 'assets', 'js');

var failures = [];
function check(ok, message) { if (!ok) { failures.push(message); } }

function read(p) { return fs.readFileSync(path.join(ROOT, p), 'utf8'); }

/* 1 + 2: the front controller and the router must agree on /install. */
var front = read('public/index.php');
check(
  /\$spaPaths\s*=\s*\[[^\]]*'\/install'/.test(front),
  'public/index.php: /install is missing from $spaPaths, so it would not serve the SPA shell'
);
check(
  !/\$apiPrefixes\s*=\s*\[[^\]]*'\/install'/.test(front),
  "public/index.php: '/install' is still an API prefix, so GET /install returns JSON instead of the page"
);

var app = read('public/views/assets/js/app.js');
var prefixes = (app.match(/var API_PREFIXES=\[(.*?)\]/s) || [])[1] || '';
check(
  prefixes.indexOf("'/install'") === -1,
  "app.js: API_PREFIXES still contains '/install', so links to the installer would be routed by the SPA router"
);
check(
  /API\.get\('\/panel\/requirements'\)/.test(app),
  "app.js: install state must be read from GET /panel/requirements"
);
check(
  /location\.pathname==='\/install'/.test(app),
  'app.js: the /install page must render the installer instead of the normal bootstrap'
);

/* 3: no SPA script may still call the removed endpoint. */
fs.readdirSync(JS_DIR).filter(function (f) { return /\.js$/.test(f); }).forEach(function (f) {
  var body = fs.readFileSync(path.join(JS_DIR, f), 'utf8');
  check(
    body.indexOf("API.get('/install'") === -1,
    f + ": still calls GET /install, which no longer exists as a JSON route"
  );
});

/* 4: the run endpoint must remain a JSON route, and GET /install must not. */
var routes = read('app/Http/routes.php');
check(
  !/\['GET',\s*'\/install'/.test(routes),
  "routes.php: GET /install must not be declared; it is an SPA page, not a JSON endpoint"
);
check(
  /\['POST',\s*'\/install\/run'/.test(routes),
  'routes.php: POST /install/run is missing'
);
check(
  /\['GET',\s*'\/panel\/requirements'/.test(routes),
  'routes.php: GET /panel/requirements is missing'
);

if (failures.length) {
  console.error('spa contract FAILED (' + failures.length + '):');
  failures.forEach(function (f) { console.error('  - ' + f); });
  process.exit(1);
}
console.log('spa contract OK (/install serves the page, checklist via /panel/requirements)');