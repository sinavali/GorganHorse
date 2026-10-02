/*
 * tests/Frontend/events-contract.test.js
 *
 * Purpose:
 *   RENDERS the competition-family pages (signups, standings, competition
 *   detail, horse detail) — the real shipped pages, executed against the DOM
 *   stub — and asserts the behaviours that static greps kept missing:
 *
 *     1. `/signups` returned 200 but rendered nothing: Pages.signups.load()
 *        referenced `SCOLS`, which was declared inside Pages.competitions.render()
 *        and therefore out of scope, so the .then() threw and the skeleton
 *        stayed forever.
 *     2. `/standings` sent the whole result set in one unpaginated, unsorted
 *        response. Both grids now order and page **in SQL**, so a sort click
 *        must produce a NEW request carrying `sort`/`dir` — not a client-side
 *        reorder of the rows already on screen.
 *     3. The competition page used to redirect to /signups; it now renders an
 *        in-page signups section grouped per "rade" with a flat-list toggle.
 *     4. The horse gallery is capped at 5 images and marks the first as cover.
 *
 * Usage: node tests/Frontend/events-contract.test.js   (exit code 0 = pass)
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
/* Every root handed to a page render(). Pages reach for their own subtrees by
   id (`document.getElementById('fS')`, '#healthHost', …), so a stub that
   always invents a detached node hides exactly the wiring under test. */
var roots = [view];

var sandbox = {
  console: console,
  setTimeout: function () { return 0; }, clearTimeout: function () {},
  setInterval: function () { return 0; }, clearInterval: function () {},
  addEventListener: function () {}, removeEventListener: function () {},
  innerWidth: 1400, innerHeight: 900, scrollX: 0, scrollY: 0,
  document: {
    documentElement: mkNode('html'), body: body, cookie: '',
    createElement: function (t) { return mkNode(t); },
    getElementById: function (id) {
      if (id === 'view') { return view; }
      for (var i = 0; i < roots.length; i++) {
        var hit = dom.findAll(roots[i], '[id="' + id + '"]')[0];
        if (hit) { return hit; }
      }
      return mkNode('div');
    },
    querySelector: function (sel) {
      for (var i = 0; i < roots.length; i++) {
        var hit = dom.findAll(roots[i], sel)[0];
        if (hit) { return hit; }
      }
      return dom.findAll(view, sel)[0] || null;
    },
    querySelectorAll: function (sel) {
      var out = [];
      roots.forEach(function (r) { out = out.concat(dom.findAll(r, sel)); });
      return out.length ? out : dom.findAll(view, sel);
    },
    addEventListener: function () {}, removeEventListener: function () {}
  },
  location: { pathname: '/signups', search: '', hash: '', href: 'http://x/', reload: function () {} },
  history: { pushState: function () {}, replaceState: function () {}, back: function () {} },
  navigator: { userAgent: 'node' },
  localStorage: { getItem: function () { return null; }, setItem: function () {} },
  Blob: function () {}, FormData: function () {},
  Event: function (t) { this.type = t; }, MouseEvent: function () {},
  /* No DOMParser: richHtml() takes its escaping fallback instead of trying to
     parse a document the stub cannot build. */
  DOMParser: undefined,
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

/* ---- fixtures ------------------------------------------------------- */

var SIGNUP_ROWS = [
  { id: 31, rider_user_id: 4, rider_name: 'زهرا کریمی', horse_id: 9, horse_name: 'طوفان',
    competition_id: 5, competition_title: 'جام هیرکان', rade_id: 2, rade_name: 'نسل اول',
    status: 'confirmed', position: 1, is_winner: 1, payment_amount_irt_snapshot: 2500000,
    created_at: '2026-09-01T10:00:00Z' },
  { id: 30, rider_user_id: 5, rider_name: 'امیر رضایی', horse_id: 10, horse_name: 'بادگیر',
    competition_id: 5, competition_title: 'جام هیرکان', rade_id: 3, rade_name: 'نسل دوم',
    status: 'paid', position: null, is_winner: 0, payment_amount_irt_snapshot: 3000000,
    created_at: '2026-08-28T10:00:00Z' }
];

var STANDINGS_ROWS = SIGNUP_ROWS.map(function (s) {
  return { id: s.id, rider_user_id: s.rider_user_id, rider_name: s.rider_name,
    horse_id: s.horse_id, horse_name: s.horse_name, competition_id: s.competition_id,
    competition_title: s.competition_title, rade_name: s.rade_name, start_at: '2026-05-04T08:00:00Z',
    position: s.position, is_winner: s.is_winner };
});

var COMPETITION = {
  id: 5, title: 'جام هیرکان', slug: 'jam-hirkan-1234', city: 'گرگان', venue_club_id: 2,
  venue_name: 'باشگاه سوارکاری گرگان', status: 'open', description: '<p>شرح</p>',
  banner: null,
  start_registration_at: '2026-09-01T08:00:00Z', end_registration_at: '2026-09-20T08:00:00Z',
  start_at: '2026-09-25T08:00:00Z',
  rades: [
    { id: 21, rade_id: 2, rade_name: 'نسل اول', sort_order: 1, capacity: 30, amount_irt: 2500000 },
    { id: 22, rade_id: 3, rade_name: 'نسل دوم', sort_order: 2, capacity: 2, amount_irt: 3000000 }
  ]
};

var HORSE = {
  id: 9, name: 'طوفان', race: 'ترکمن', status: 'active', owner_user_id: 4,
  owner_name: 'زهرا کریمی', share_code: '482913',
  microchip_number: '100000000000001'
};

/* Every GET the pages issue, with its params. */
var calls = [];
function lastCall(path) {
  for (var i = calls.length - 1; i >= 0; i--) { if (calls[i].path === path) { return calls[i]; } }
  return null;
}


sandbox.API.get = function (p, params) {
  calls.push({ path: p, params: params || {} });
  if (p === '/panel/signups') {
    return Promise.resolve({ rows: SIGNUP_ROWS, total: 412, page: (params && params.page) || 1, per_page: 50 });
  }
  if (p === '/panel/standings') {
    return Promise.resolve({ rows: STANDINGS_ROWS, total: 980, page: (params && params.page) || 1, per_page: 50 });
  }
  if (p === '/panel/competitions/5') { return Promise.resolve(COMPETITION); }
  if (p === '/panel/competitions') { return Promise.resolve({ rows: [COMPETITION], total: 1 }); }
  if (p === '/panel/rades') { return Promise.resolve({ rows: [{ id: 2, name: 'نسل اول' }, { id: 3, name: 'نسل دوم' }] }); }
  if (p === '/panel/lookups/genders') { return Promise.resolve([{ id: 1, name: 'نریان' }]); }
  if (p === '/panel/lookups/races') { return Promise.resolve([{ id: 1, name: 'ترکمن' }]); }
  if (p === '/panel/lookups/colors') { return Promise.resolve([{ id: 1, name: 'قهوه‌ای' }]); }
  if (p === '/panel/users') { return Promise.resolve({ rows: [{ id: 4, first_name: 'زهرا', last_name: 'کریمی' }] }); }
  if (p === '/panel/horses') { return Promise.resolve({ rows: [HORSE], total: 1 }); }
  if (p === '/panel/horses/9/health') {
    return Promise.resolve([{ id: 1, record_type: 'vaccination', title: 'آنفولانزا', performed_at: '2026-03-01T00:00:00Z', next_due_at: '2027-03-01T00:00:00Z', cost_irt: 4000000 }]);
  }
  if (p === '/panel/payments') { return Promise.resolve({ rows: [] }); }
  if (p === '/panel/horses/9') {
    return Promise.resolve({ horse: HORSE, images: [1, 2, 3, 4, 5].map(function (n) { return { id: n, media_id: n }; }),
      shares: [], transfers: [], history: [] });
  }
  if (p === '/panel/horses') { return Promise.resolve({ rows: [] }); }
  return Promise.resolve({ rows: [], total: 0 });
};
sandbox.API.post = function () { return Promise.resolve({}); };
sandbox.API.put = function () { return Promise.resolve({}); };
sandbox.API.del = function () { return Promise.resolve({}); };
sandbox.API.upload = function () { return Promise.resolve({}); };
sandbox.App.user = { role: 'admin', id: 1, username: 'admin' };
sandbox.App.seed = function (f) { return f; };
sandbox.App.setQuery = function () {};
sandbox.App.replaceQuery = function () { return ''; };
sandbox.App.go = function () {};

/* The pages chain several .then()s; let the microtask queue drain. */
function settle() {
  return new Promise(function (r) { setImmediate(function () { setImmediate(function () { setImmediate(r); }); }); });
}

function render(page, id) {
  calls.length = 0;
  var root = mkNode('div');
  roots.push(root);
  var err = null;
  try {
    sandbox.Pages[page].render(root, sandbox.App, id);
  } catch (e) {
    err = e;
  }
  check(!err, page + ': render() threw synchronously — ' + (err && err.stack || '').split('\n').slice(0, 3).join(' | '));
  return { root: root, err: err };
}

function fire(node, type, ev) {
  var ls = (node && node._listeners && node._listeners[type]) || [];
  var e = ev || { target: node, stopPropagation: function () {}, preventDefault: function () {} };
  ls.forEach(function (fn) { fn(e); });
}

function html(node) {
  var out = node && node._html || '';
  (function walk(n) {
    (n.children || []).forEach(function (c) {
      if (c._html) { out += c._html; }
      walk(c);
    });
  })(node);
  return out;
}

/* ---- 1. /signups ---------------------------------------------------- */

(function () {
  var r = render('signups');
  return settle().then(function () {
    check(!r.err, 'signups: must render without throwing (SCOLS must be in module scope)');
    var c = lastCall('/panel/signups');
    check(!!c, 'signups: no request was issued to /panel/signups');
    if (!c) { return; }
    var h = html(r.root);
    check(h.indexOf('زهرا کریمی') > -1, 'signups: the grid rendered no rows — the payload was never turned into a table');

    /* Rider / horse / competition cells must be real links, not plain text. */
    check(/href="\/users\/4"/.test(h), 'signups: the rider cell is not linked to /users/{id}');
    check(/href="\/horses\/9"/.test(h), 'signups: the horse cell is not linked to /horses/{id}');
    check(/href="\/competitions\/5"/.test(h), 'signups: the competition cell is not linked to /competitions/{id}');

    /* Ordering happens in SQL: a header click must re-request with sort/dir. */
    var ths = dom.findAll(r.root, 'th[data-sort]');
    check(ths.length > 1, 'signups: no sortable column headers were rendered');
    var riderTh = ths.filter(function (t) { return t.dataset.sort === 'rider_name'; })[0];
    check(!!riderTh, 'signups: the rider column is not sortable');
    if (riderTh) {
      calls.length = 0;
      fire(riderTh, 'click');
      return settle().then(function () {
        var after = lastCall('/panel/signups');
        check(!!after, 'signups: clicking a sortable header did not re-request the grid');
        if (after) {
          check(after.params.sort === 'rider_name',
            'signups: ordering is not sent to the server (sort=' + JSON.stringify(after.params.sort) + ')');
          check(after.params.dir === 'asc' || after.params.dir === 'desc',
            'signups: no ASC/DESC direction sent (dir=' + JSON.stringify(after.params.dir) + ')');
          check(String(after.params.sort) !== '[object Object]',
            'signups: the nested sort object leaked into the query string');
        }
      });
    }
  });
})()
/* ---- 2. /standings -------------------------------------------------- */
.then(function () {
  var r = render('standings');
  return settle().then(function () {
    check(!r.err, 'standings: must render without throwing');
    var h = html(r.root);
    check(h.indexOf('زهرا کریمی') > -1, 'standings: no rows rendered');
    check(h.indexOf('pager') > -1, 'standings: no pager — the grid is still unpaginated');
    check(/data-page="2"/.test(h), 'standings: the pager does not offer the next page');
    check(/href="\/users\/4"/.test(h), 'standings: the rider cell is not linked');
    check(/href="\/horses\/9"/.test(h), 'standings: the horse cell is not linked');
    check(/href="\/competitions\/5"/.test(h), 'standings: the competition cell is not linked');

    var ths = dom.findAll(r.root, 'th[data-sort]');
    check(ths.length > 1, 'standings: no sortable columns');
    var posTh = ths.filter(function (t) { return t.dataset.sort === 'position'; })[0];
    if (!posTh) {
      check(false, 'standings: the position column is not sortable');
      return null;
    }
    calls.length = 0;
    fire(posTh, 'click');
    return settle().then(function () {
      var after = lastCall('/panel/standings');
      check(!!after, 'standings: clicking a sortable header did not re-request the grid');
      if (after) {
        check(after.params.sort === 'position', 'standings: ordering is not sent to the server');
        check(after.params.dir === 'asc' || after.params.dir === 'desc', 'standings: no direction sent');
        check(after.params.page === undefined || after.params.page === 1,
          'standings: sorting must reset to page 1');
      }
      /* Pagination must re-request too. */
      var pager = dom.findAll(r.root, '[data-page]')[0];
      check(!!pager, 'standings: the pager buttons are not bound');
      if (pager) {
        calls.length = 0;
        fire(pager, 'click');
        return settle().then(function () {
          var p = lastCall('/panel/standings');
          
          check(!!p && Number(p.params.page) === Number(pager.dataset.page),
            'standings: clicking a page number did not request that page');
        });
      }
    });
  });
})
/* ---- 3. competition page: signups per rade --------------------------- */
.then(function () {
  sandbox.location.pathname = '/competitions/5';
  var r = render('competitionDetail', '5');
  return settle().then(function () {
    check(!r.err, 'competitionDetail: must render without throwing');
    var su = lastCall('/panel/signups');
    check(!!su, 'competitionDetail: the in-page signups section never loaded');
    if (su) {
      check(String(su.params.competition_id) === '5',
        'competitionDetail: the signups section is not scoped to this competition');
    }
    var accs = dom.findAll(r.root, 'details').filter(function (d) {
      return String(d.attrs.class || '').indexOf('rade-acc') > -1;
    });
    check(accs.length === 2,
      'competitionDetail: signups must be grouped per rade (2 rades -> 2 groups, got ' + accs.length + ')');
    var toggles = dom.findAll(r.root, '[data-su-view]');
    check(toggles.length === 2,
      'competitionDetail: the per-rade / flat-list toggle is missing');
    var flatBtn = toggles.filter(function (b) { return b.dataset.suView === 'flat'; })[0];
    check(!!flatBtn, 'competitionDetail: no "flat list" toggle');
    /* The header must no longer bounce staff out to /signups. */
    var head = dom.findAll(r.root, 'a').filter(function (a) {
      return String(a.attrs.href || '').indexOf('/signups?competition_id=5') === 0;
    });
    check(head.length === 1,
      'competitionDetail: expected exactly one "full signups page" link, found ' + head.length);
    if (flatBtn) {
      calls.length = 0;
      fire(flatBtn, 'click');
      return settle().then(function () {
        check(!!lastCall('/panel/signups'), 'competitionDetail: the flat-list toggle did not reload the grid');
      });
    }
  });
})
/* ---- 4. horse gallery ----------------------------------------------- */
.then(function () {
  sandbox.location.pathname = '/horses/9';
  var r = render('horseDetail', '9');
  return settle().then(function () {
    check(!r.err, 'horseDetail: must render without throwing');
    var h = html(r.root);
    var cells = dom.findAll(r.root, '[data-zoom]');
    check(cells.length === 5, 'horseDetail: expected 5 gallery tiles, got ' + cells.length);
    check(h.indexOf('gcover') > -1, 'horseDetail: the first gallery image is not marked as the cover');
    /* At the cap the "add" tile must disappear (horses.max_images = 5). */
    check(dom.findAll(r.root, '.gadd').length === 0,
      'horseDetail: the add tile is still shown at the 5-image cap');
    check(h.indexOf('۵') > -1, 'horseDetail: the gallery does not report its "n of 5" counter');

    /* Health records: healthSection() was never called from this page and its
       reload() did `paintUI.rows(d)`, so the section was always empty. */
    check(!!lastCall('/panel/horses/9/health'),
      'horseDetail: the health section never requested /panel/horses/{id}/health');
    check(h.indexOf('آنفولانزا') > -1,
      'horseDetail: health records were fetched but never rendered (paintUI typo?)');

    /* The owner must be reachable from the horse page. */
    check(/href="\/users\/4"/.test(h), 'horseDetail: no link to the owner page');

    /* Share codes must be masked, never dumped into the printed DOM. */
    check(h.indexOf('class="secret"') > -1,
      'horseDetail: the share code is not rendered through UI.secret()');
    check(/class="secret-v"[^>]*blur/.test(h),
      'horseDetail: the share code is not masked by default');
  });
})
/* ---- 5. rider signup: the chosen rade must reach payment ------------- */
.then(function () {
  sandbox.location.pathname = '/rider/competitions/5';
  var RADES = COMPETITION.rades;
  var HORSES = [{ id: 9, name: 'طوفان', microchip_number: '100000000000001' }];
  sandbox.API.get = function (p, params) {
    calls.push({ path: p, params: params || {} });
    if (p === '/panel/rider/competitions/5') {
      return Promise.resolve({ competition: COMPETITION, rades: RADES, horses: HORSES,
        clubs: [{ id: 2, name: 'باشگاه سوارکاری گرگان' }] });
    }
    if (p === '/panel/horses') { return Promise.resolve({ rows: HORSES }); }
    if (p === '/panel/clubs') { return Promise.resolve({ rows: [{ id: 2, name: 'باشگاه' }] }); }
    return Promise.resolve({ rows: [], total: 0 });
  };
  var posted = null;
  sandbox.API.post = function (p, body) { posted = { path: p, body: body }; return Promise.resolve({}); };

  var r = render('riderSignup', '5');
  return settle().then(function () {
    check(!r.err, 'riderSignup: must render without throwing');
    var radios = dom.findAll(r.root, 'input[name=comp_rade_id]');
    check(radios.length === RADES.length,
      'riderSignup: expected one rade radio per rade, got ' + radios.length);
    radios.forEach(function (x) {
      check(String(x.attrs.form) === 'fS',
        'riderSignup: rade radio ' + x.attrs.value + ' is not associated with the form (form="fS")');
    });
    var second = radios[1];
    if (!second) { return null; }
    /* Selecting a radio in a browser sets `checked` before firing change. */
    second.attrs.checked = '';
    fire(second, 'change');
    var submit = dom.findAll(r.root, 'button')[0];
    check(!!submit, 'riderSignup: no submit button');
    var form = dom.findAll(r.root, 'form').filter(function (f) { return f.attrs.id === 'fS'; })[0];
    check(!!form, 'riderSignup: the signup form #fS is missing');
    /* Select the horse + club the way a rider would, then submit. */
    form.querySelector('input[name=horse_id]').value = '9';
    form.querySelector('input[name=affiliation_club_id]').value = '2';
    var f = form._listeners.submit || [];
    f.forEach(function (fn) { fn({ preventDefault: function () {} }); });
    return settle().then(function () {
      check(!!posted, 'riderSignup: submitting a fully-filled form sent nothing — the rade was never read');
      if (posted) {
        check(String(posted.body.competition_rade_id) === String(RADES[1].id),
          'riderSignup: the selected rade did not reach the API (got ' + posted.body.competition_rade_id + ')');
        check(posted.path === '/panel/rider/competitions/5/signup',
          'riderSignup: unexpected signup endpoint ' + posted.path);
      }
    });
  });
})
/* ---- 6. horses grid filters + print columns -------------------------- */
.then(function () {
  sandbox.location.pathname = '/horses';
  sandbox.API.get = function (p, params) {
    calls.push({ path: p, params: params || {} });
    if (p === '/panel/horses') { return Promise.resolve({ rows: [HORSE], total: 1 }); }
    return Promise.resolve({ rows: [], total: 0 });
  };
  var r = render('horses');
  return settle().then(function () {
    check(!r.err, 'horses: must render without throwing');
    var h = html(r.root);
    ['gender', 'race', 'color', 'owner_user_id', 'per_page'].forEach(function (k) {
      check(h.indexOf('data-spk="__sel_' + k + '"') > -1,
        'horses: the grid has no "' + k + '" filter');
    });
    /* Selection + action columns must opt out of printing. */
    var ths = dom.findAll(r.root, 'th');
    var np = ths.filter(function (t) { return String(t.attrs.class || '').indexOf('data-no-print') > -1; });
    check(ths.length >= 2, 'horses: expected a checkbox and an actions column');
    var marked = ths.filter(function (t) { return t.hasAttribute && t.hasAttribute('data-no-print'); });
    check(marked.length === 2,
      'horses: exactly the checkbox and actions columns must be marked data-no-print, got ' + marked.length);
    void np;
    check(/href="\/users\/4"/.test(h), 'horses: the owner cell is not linked to the owner page');
  });
})
/* ---- 7. share-code masking + sidebar collapse ------------------------ */
.then(function () {
  var ui = sandbox.UI;

  /* spkFromApi() was handed a string field name by six call sites and called
     it as a function; the TypeError was swallowed by its own .catch, so the
     competition and rade dropdowns showed "not found" against a 200. */
  var mapped = [];
  sandbox.API.get = function (p) {
    if (p === '/panel/rades') { return Promise.resolve({ rows: [{ id: 2, name: 'نسل اول' }] }); }
    return Promise.resolve({ rows: [] });
  };
  var loader = ui.spkFromApi('/panel/rades', 'name');
  return new Promise(function (res) {
    loader('', function (list) { mapped = list; res(); });
  }).then(function () {
    check(mapped.length === 1 && mapped[0].l === 'نسل اول',
      'spkFromApi: a string label field must produce options (got ' + JSON.stringify(mapped) + ')');
  });
})
.then(function () {
  var css = fs.readFileSync(path.join(ROOT, 'public', 'views', 'assets', 'css', 'app.css'), 'utf8');
  /* An author `display` rule beats the UA [hidden] rule, which is why every
     sidebar group stayed expanded. */
  check(/\.nsec\[aria-expanded="false"\]\s*\+\s*\.nsec-items/.test(css),
    'app.css: collapsed sidebar groups need .nsec[aria-expanded="false"] + .nsec-items{display:none}');
  check(/\.nsec-items\[hidden\]\{display:none\}/.test(css),
    'app.css: .nsec-items[hidden] must be display:none or folding a group does nothing');
  /* Share codes must never reach paper. */
  check(/@media print\{[\s\S]*?\.secret\{display:none!important\}/.test(css),
    'app.css: .secret (share codes) must be hidden in @media print');
  check(/@media print\{[\s\S]*?td\[data-no-print\]\{display:none!important\}/.test(css),
    'app.css: [data-no-print] columns must be hidden in @media print');
  check(/data-no-print/.test(fs.readFileSync(path.join(JS, 'ui.js'), 'utf8')),
    'ui.js: UI.table must support per-column data-no-print opt-out of printing');
})
/* ---- 8. competition description editor (Quill) ---------------------- */
.then(function () {
  var host = dom.findAll(sandbox.document.body, 'div').length; void host;
  var markup = sandbox.UI.editor('description', 'دربارهٔ مسابقه', '<p>سلام</p>', { full: true });
  var root = dom.makeNode('div');
  root.innerHTML = markup;

  /* Every useful control, each with a Persian tooltip. */
  ['bold', 'italic', 'underline', 'strike', 'script', 'code', 'blockquote', 'code-block',
    'list', 'indent', 'outdent', 'divider', 'link', 'image', 'clean', 'header',
    'undo', 'redo'].forEach(function (fmt) {
    var btns = dom.findAll(root, '[data-format="' + fmt + '"]');
    check(btns.length > 0, 'editor toolbar: the "' + fmt + '" control is missing');
    btns.forEach(function (b) {
      var t = String(b.attrs.title || '');
      check(t.length > 2 && !/^[\x20-\x7E]+$/.test(t),
        'editor toolbar: "' + fmt + '" has no Persian tooltip (got "' + t + '")');
    });
  });
  ['sub', 'super'].forEach(function (v) {
    check(dom.findAll(root, '[data-format="script"][data-value="' + v + '"]').length === 1,
      'editor toolbar: missing the "' + v + '" script button');
  });
  check(dom.findAll(root, '.edt-b[data-edt-help]').length === 1,
    'editor toolbar: non-technical staff need the in-editor help button');
  check(dom.findAll(root, '.edt-b[data-edt-src]').length === 1,
    'editor toolbar: the HTML source view is missing');
  /* The legacy contenteditable bar must not also own a source button: an
     unscoped querySelector handed the Quill button to both handlers. */
  var bar = dom.findAll(root, '[data-edt-fallback]')[0];
  check(!!bar, 'editor: the fallback bar must still be present when Quill cannot load');
  check(dom.findAll(bar, '[data-edt-src]').length === 1,
    'editor: the fallback bar and the Quill toolbar must each own exactly one source button');

  /* Whatever the editor can emit must survive both whitelists. */
  var src = fs.readFileSync(path.join(JS, 'ui.js'), 'utf8');
  var rich = (src.match(/var RICH_TAGS=\{([^}]*)\}/) || ['', ''])[1];
  ['SUB', 'SUP', 'PRE', 'CODE', 'HR', 'IMG', 'BLOCKQUOTE', 'H2', 'H3', 'H4', 'A', 'UL', 'OL', 'LI']
    .forEach(function (t) {
      check(rich.indexOf(t + ':1') > -1,
        'RICH_TAGS must allow <' + t.toLowerCase() + '> or richHtml() silently strips it');
    });
})
.then(function () {
  if (failures.length) {
    console.error('events contract FAILED (' + failures.length + '):');
    failures.forEach(function (f) { console.error('  - ' + f); });
    process.exit(1);
  }
  console.log('events contract OK (grids order/page server-side, pickers resolve, rider signup reaches payment, health records render, share codes stay off paper, Quill toolbar complete)');
});