# README.md — Gorgan Horse Federation Panel

**Project:** Backend panel for the Gorgan Horse Federation (هیئت سوارکاری استان گلستان)
**Status:** Implemented (per-feature status tracked in [`docs/Features.md`](./docs/Features.md))
**Stack:** PHP 8.1+, SQLite, no framework, RTL-first, fa-IR-first

---

## What this is

A monolithic PHP web panel that manages the operational lifecycle of horse-riding competitions in Golestan province, Iran. It handles clubs, riders, horses, rades, competitions, signups, online payments (ZarinPal), results, and reporting.

The panel is hosted on a subdomain (e.g. `panel.gorganhorse.ir`). The public-facing website (landing pages, blog, SEO) is handled separately by WordPress on the main domain. There is no session sharing between the two systems.

The panel is **authenticated-only** for all users. The only public endpoints are the ZarinPal payment callbacks.

---

## Documentation

All project documentation lives in `/docs/`. Read in this order depending on your role.

| Document | Audience | Purpose |
|---|---|---|
| [`docs/README.md`](./docs/README.md) | Everyone | Documents index and reading guide. |
| [`docs/Project Proposal — Gorgan Horse Federation Panel.md`](./docs/Project%20Proposal%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Client, stakeholders | Executive summary, deliverables, KPIs, success metrics. |
| [`docs/Backend Blueprint — Gorgan Horse Federation Panel.md`](./docs/Backend%20Blueprint%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Architects, reviewers | Master blueprint: principles, scope, schema, flows, routes, KPIs, integrations, glossary, error codes, implementation checklist, risk register. |
| [`docs/Technical Specification — Gorgan Horse Federation Panel.md`](./docs/Technical%20Specification%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Implementers, maintainers | Stack, implementation rules, auth internals, validations, deployment. |
| [`docs/User Usage Specification — Gorgan Horse Federation Panel.md`](./docs/User%20Usage%20Specification%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Frontend developers, UX | Every screen, flow, KPI, empty state, error message, print view. |
| [`docs/Features.md`](./docs/Features.md) | Everyone | Per-feature implementation status, with the notes behind each decision. |

**Note:** The Blueprint includes merged sections for Glossary, Naming Conventions, Error Codes, Implementation Checklist, and Risk Register. Standalone documents for those are not needed.

---

## Quick orientation

### For the client
Start with the **Project Proposal**. It explains the problem, the solution, who it's for, what it delivers, and the KPIs.

### For architects and reviewers
Read the **Backend Blueprint** end-to-end. Every architectural decision is final. Pay special attention to §2 (Principles) and §29 (Closing Notes).

### For implementers
Read the **Technical** document alongside the **Backend Blueprint**. The Technical document is the authority on file layout, stack choices, and code standards. The Blueprint is the authority on domain rules, schema, and flows.

### For frontend developers
Read the **User Usage** document end-to-end. It specifies every screen, every flow, every KPI, every error state. Build the UI from this document, not from guesswork.

### For UX reviewers
The User Usage document is your reference. Validate against real users (Admin, Manager, Rider, Club) before shipping.

---

## Project structure

```
/
├── README.md                    ← you are here
├── docs/                        ← all specification docs (see below)
├── public/                      ← web root (single entry point)
├── app/                         ← application code
├── database/                    ← schemas, seeds, runtime DBs
├── cultures/                    ← culture JSON files (fa-IR, en-US)
├── uploads/                     ← user-uploaded media (outside the web root)
├── cache/                       ← file cache
├── logs/                        ← raw log retention
├── backups/                     ← zip backups
├── tests/                       ← PHP + front-end contract suites (`php tests/run_all_tests.php`)
├── vendor/                      ← vendored PHP libraries
└── docs/                        ← specification docs + auxiliary files (nginx sample, …)
```

Full folder structure is in the **Backend Blueprint §5**.

---

## Frontend (`public/views`)

The panel ships with a self-contained, dependency-free single-page frontend. Everything
lives under `public/views/` — no build step, no npm, no bundler — which matches the
project's “no Node at runtime” constraint.

```
public/views/
├── index.html                 ← SPA shell (served for `/`, `/payment/success`, `/payment/failed`)
└── assets/
    ├── favicon.svg
    ├── fonts/                 ← vendored Vazirmatn woff2 files (+ README with licence)
    ├── vendor/                ← vendored Quill 2.0.3 (+ README with licence)
    ├── css/app.css            ← design system (CSS variables, RTL, dark mode, tables, modals)
    └── js/
        ├── i18n.js            ← Jalali calendar, Persian digits, money/date formatting
        ├── api.js             ← JSON API client (envelope handling, CSRF, uploads, downloads)
        ├── ui.js              ← UI kit (icons, toasts, modals, tables, forms, datepicker)
        ├── auth.js            ← login/OTP/signup/forgot + first-run installer wizard
        ├── pages-core.js      ← dashboard, profile, users, clubs, rades, notifications, messages
        ├── pages-horses.js    ← horse registry, media, shares, transfers, history
        ├── pages-events.js    ← competitions, rades-in-competition, signups, results, standings
        ├── pages-finance.js   ← payment templates, orders, refunds, ZarinPal reconciliation
        ├── pages-system.js    ← reports, settings, SMS, audit, backups/maintenance, search
        └── app.js             ← shell, History-API router, role-aware navigation (loads last)
```

- **Served by** `public/index.php`: the SPA shell is returned for `GET /`,
  `/index.html`, `/payment/success`, and `/payment/failed`; every other path is
  dispatched to the JSON API by the Kernel. Static assets are read directly by the web
  server from `public/views/assets/`.
- **Routing** uses clean URLs through the History API (`/dashboard`, `/horses/12`,
  `/competitions/3/results`, `/signups?competition_id=3`). `public/index.php` serves the
  SPA shell for any `GET` that is not an API path and not a real static file, so deep
  links, bookmarks and hard refreshes all work.
- **Auth** follows the API contract: unauthenticated requests fall back to the login
  screen, and the shell caches the CSRF token from each response envelope and echoes it
  via `X-CSRF-Token` on state-changing requests.
- **Notifications** deep-link into panel paths (`/panel/horses/12`, …); the router maps
  them to the matching SPA route.
- **Filters are URL state**: every grid stores its filters (search, selects, chips) in the
  query string, so reloading or sharing a URL restores the same filtered view.
- **Cultures**: `fa-IR` (Jalali calendar, Persian digits, RTL) and `en-US` (Gregorian
  calendar, Latin digits) are stored in `settings.app.default_culture` and overridable per
  browser through the `culture` cookie. `GET /panel/cultures` + `POST /panel/culture`
  change it; the topbar switcher and the Settings page both use them.
- **Public competition pages**: `GET /c/{slug}` renders a shareable, server-side HTML
  page with OG tags and a call-to-action; the slug is generated when a competition is saved.
- **Media** is streamed by the authenticated `GET /media/{id}` route, so uploads (which
  live outside the web root) are never publicly reachable.
- **Installer**: `/install` is an SPA page (like every other non-API path), not a JSON
  endpoint. It is backed by two JSON routes: `GET /panel/requirements` (guest; returns
  `{installed, requirements[]}` from the `app.installed` setting) and `POST /install/run`.
  The shell reads `/panel/requirements` at boot, shows the wizard when `installed` is
  false, and re-checks it after login so flipping the setting (e.g. from the maintenance
  page) bounces the browser back to the installer.
- **Printing**: every grid and single-record page carries a print button in its header
  (added automatically by `pageHead()`; pass `{noPrint:true}` to opt out). `UI.preparePrint()`
  injects a letterhead block with the federation name, the document title, the active
  filter chips and the row count, then restores the screen after `window.print()`. The
  `@media print` block in `app.css` lays the page out as a real A4 document: full-width
  grids with repeating table headers, zebra rows, page-break rules, and chrome
  (sidebar, topbar, filter bars, pagers, buttons) removed. The calendar is the one
  exception to hiding interactive chrome: its 7-column grid loses most of its rows on
  A4, so print flattens the month into a list of days instead of printing empty boxes.
- **Exports** are culture-aware. Every CSV writer (report export, audit log, SMS
  delivery log) passes its cells through `CultureService::exportCell()`, which renders
  ISO timestamps as the same readable dates the screen shows — `۱۴۰۵/۰۹/۰۵ ۰۴:۰۵`
  under `fa-IR`, `2026-11-26 04:05` under `en-US`. Numbers are left numeric so
  spreadsheets can still use them.
- **Reports filtering** has two layers. The simple bar holds a free-text search (mapped
  to the report's `contains` filter), a date-range control with quick presets (this
  month / 30 days / 90 days / this year / custom Jalali range), the report's main enum
  filter as toggle chips, a page-size selector and a clear-all button. The
  “فیلتر پیشرفته” drawer exposes every declared filter column with its operator
  (`eq`, `ne`, `contains`, `gt`, `gte`, `lt`, `lte`, `in`, `between`) and a
  type-appropriate value editor. Both layers compile into the same `filters[]` payload
  the report engine already takes, and the whole state lives in the query string.
- **Resilience**: list payloads go through `UI.rows()` (always an array), grid cells are
  rendered inside a try/catch, related lookups tolerate empty tables, and a throwing page
  shows a recoverable error panel instead of a blank screen.
- **Inbox pages** (notifications, messages) load with an explicit error branch and a
  retry button rather than an un-`catch`ed promise, so a failed request shows a message
  instead of an endless spinner. Notifications lead with the unread count, label each
  item by kind (`UI.notifLabel()`), show a relative time, and separate "mark read" from
  "open the link" so a card is never an ambiguous click target. Messages preview the
  body, name the sender and scope, and explain an empty inbox in words.
- **Profile** carries personal activity from `GET /panel/profile/stats`, which is scoped
  to the signed-in account (so it is safe for every role): role-aware stat cards, a
  six-month signup trend, and the signup status mix.
- **Ordering and paging happen in SQL.** `GET /panel/signups` and
  `GET /panel/standings` accept `sort`, `dir`, `page` and `per_page` and both return
  the `{rows, total, page, per_page}` envelope. The services resolve the sort key
  through a whitelist (`SignupService::SORTABLE`, `ResultService::STANDINGS_SORTABLE`)
  and fall back to a default column for anything unknown, so a pager and a sort header
  can never disagree about which rows are on screen. Sorting also resets to page 1.
  `/standings` used to return every confirmed signup in the province in a single
  response; it is now paginated and scoped by rider/horse/page size.
- **Competition entries live on the competition page.** Staff no longer get bounced to
  `/signups` to see a competition's entries: `compSignupsSection()` renders them grouped
  by "rade" (one collapsible block per rade, with capacity, signup count and remaining
  places) with a toggle to a single flat sortable list. Approve / reject / position stay
  in place, and the view state (`?su_view=&su_status=&su_q=`) is URL state.
- **Competitions carry a wide banner** (`competitions.banner_media_id`), uploaded through
  `POST /panel/competitions/{id}/banner` and shown as the hero of the public page.
  Because uploads live outside the web root and `GET /media/{id}` requires auth, the
  image reaches anonymous visitors through the guest route `GET /c/{slug}/banner`,
  which only ever resolves `competitions.banner_media_id`.
- **The public competition page** (`GET /c/{slug}`) is a landing page rather than a
  form: full-bleed hero (banner overlay or gradient), status pill, two-column body with
  a sticky facts sidebar, capacity bars per rade, the announcement callout, and the
  sanitised description/rules. `og:image` points at the banner.
- **Rich text fields are Quill 2**, not a hand-rolled `contenteditable`: staff are not
  technical, so headings, lists, links and **drag-and-drop / paste / pick images** all
  work. The toolbar is built by `edtToolbar()` and offers undo/redo, a block-format
  select (paragraph/H2/H3/H4), bold/italic/underline/strike-through, subscript,
  superscript, inline code, blockquote, code block, bullet/ordered lists, indent and
  outdent, a divider, link, image (uploads through `POST /panel/media` and embeds
  `/media/{id}`), clear formatting, an HTML source view (so ready-made markup can be
  pasted) and a Persian help dialog. Colour, font size and alignment are deliberately
  not offered: they serialise to inline styles the sanitizer strips anyway. Images can
  also be dropped or pasted straight into the editor. What it can produce is whitelisted
  on both ends: `HtmlSanitizer::ALLOWED_TAGS` on the server and `RICH_TAGS` in
  `richHtml()`, which also strips non-`http(s)` `href`/`src` values and hardens links
  with `target="_blank" rel="noopener noreferrer"`. Quill's `getSemanticHTML()` is
  stored, so no editor classes leak into the database, and the original `contenteditable`
  editor remains as a fallback if the vendor file is ever missing. Quill's own CSS is
  deliberately not vendored (`quill.snow.css` is LTR-first), so a small RTL theme in
  `app.css` applies the panel's tokens under `.ql-` selectors.
- **The horse gallery** honours `horses.max_images` (5): responsive tiles, a "cover"
  badge on the first image, numbered tiles, the add tile hidden once the cap is reached,
  an "n of 5" counter, multi-file upload, and a keyboard-navigable lightbox
  (Esc / ← / →, backdrop click).
- **Vendored front-end libraries and fonts** live under `public/views/assets/vendor/`
  and `public/views/assets/fonts/`, because the project has no build step, no npm and no
  bundler. Both directories carry a `README.md` recording what the file is, where it
  came from and under which licence. Quill 2 is loaded **lazily** (only when a rich-text
  field is initialised); Vazirmatn is preloaded and served from the panel so the public
  page renders the same on a host with no outbound network.
- **Share codes are secrets** and never leave through a side channel: `UI.secret()`
  renders them blurred behind a reveal/copy control (so the digits are not in the
  printed DOM at all), `.secret` is `display:none` under `@media print`, and both CSV
  writers redact them server-side via `ReportEngine::isSecretColumn()`.
- **Printed grids drop what only makes sense on screen.** A column can opt out with
  `noPrint:true`, which emits `data-no-print` and is hidden by `@media print` — the
  selection checkbox and per-row action columns use it instead of costing a column each
  on paper.
- **The horses grid filters on what the API already supported**: status, gender, race,
  colour, owner, microchip, free text and page size. `gender` / `race` / `colour` are
  stored on `horses` as *labels* while the controlled vocabularies are id-keyed, so
  `HorseService::list()` resolves a numeric filter value to its label first (a bare
  label still works, and an unknown id matches nothing instead of everything).
- **Sidebar groups fold.** Collapse state lives on `aria-expanded`, and both
  `.nsec[aria-expanded="false"] + .nsec-items` and `.nsec-items[hidden]` are forced to
  `display:none`: an author `display` declaration beats the UA `[hidden]` rule, which is
  why every group used to stay open.
- **The rider signup flow** reads the chosen rade from the radio group by walking the
  nodes, not by querying the `<form>` (the radios render in the neighbouring column) or
  an `input[…]:checked` pseudo-selector. The selected rade is highlighted, the running
  total names it, the reason the button is disabled is stated, and horse/club are
  validated before the payment request.

### Operations

| Command | Purpose |
|---|---|
| `php database/migrations.php` | Idempotent schema/settings migration runner (safe to re-run). |
| `php cron.php` | Scheduled jobs (deadline alerts, auto-verification, nightly backup, session cleanup). `--force` ignores the `scheduler.enabled` switch, `--job=<name>` runs one job. |
| `POST /panel/cron?key=…` | Same jobs over HTTP for hosts without crontab (key from `settings.scheduler.secret`). |
| `php tests/run_all_tests.php` | Full suite: helpers/models, services, HTTP routes, and the frontend contract tests. |

### Frontend contract tests

`tests/Frontend/` runs the panel's **real** UI helpers (`UI.filterBar`, `UI.initPicks`)
in Node against a small dependency-free DOM stub (`dom-stub.js`), instead of only
linting the JavaScript. They pin the contracts that break whole pages rather than one
assertion:

- every `[data-spk]` picker block carries a hidden input and a `.spk-pop`, and the
  search box (a plain input, not a picker) never claims `data-spk` — otherwise
  `initPicks()` throws `Cannot read properties of null (reading 'value')` while the
  filter bar is built;
- `UI.filterBar()` returns the element that actually carries `setCount` /
  `hasFilters` / `onClear` / `clearAction` / `redraw` (attaching them to a separate
  wrapper made `fbar.setCount(...)` throw inside `load()`, which left every grid
  stuck on its loading skeleton even though the request had returned 200);
- `liveLabel()` stays in scope for `chips()` — nesting it inside `draw()` made a
  filter select that already held a value raise `liveLabel is not defined` and
  aborted the whole bar;
- `UI.pickField()` closes its `data-spk-opts` attribute, and `initPicks()` actually
  wires the block — an unterminated attribute swallows the rest of the markup, so
  the picker silently rendered with no options (this emptied the Settings culture
  dropdown);
- `/install` stays an SPA page and `public/index.php` and `app.js` agree on which
  paths are pages vs API calls.

`router-contract.test.js` covers the URL layer and the print path:

- a path carrying an id resolves to its **record** page (`/users/12` →
  `userDetail`), not the collection — the mapping used to be guarded by
  `if(id && !pageKey)`, which is never true for a known collection, so clicking a
  row just re-rendered the grid with the id left in the URL;
- every sidebar entry navigates through `routePathFor()`. Building the href as
  `'/' + key` produced `/paymentOrders`, `/smsLog` and `/mySignups`, none of
  which the page map knows, so those pages rendered the client-side 404;
- `printHtml()` never calls a bare `num()` — `ui.js` only defines `faNum()`, and
  the `ReferenceError` aborted `preparePrint()` *before* `window.print()` ran, so
  the print button did nothing at all;
- every CSV writer routes its cells through `CultureService::exportCell()`.

`tests/Unit/FrontendContractTest.php` also reflects over every controller to assert each
public method is callable as `method($request, $ctx)` — the Kernel only passes those two
arguments, and route parameters arrive via `$request->attr()`. A controller declaring a
third required parameter 500s every route that uses it.

`events-contract.test.js` renders the signups, standings, competition and horse pages
for real and pins the contracts that broke them:

- `Pages.signups.load()` referencing `SCOLS`, which was declared inside
  `Pages.competitions.render()` and therefore out of scope — the `.then()` threw and a
  200 response still left the grid on its loading skeleton forever. `SCOLS` now lives at
  module scope, which is also what makes the rider / horse / competition cells links;
- ordering and paging must be **issued to the server** (`sort` / `dir` / `page` on a new
  request) rather than applied to the rows already on screen, and the nested
  `filters.sort` object must not leak into the query string as `[object Object]`;
- the competition page must group its signups per rade with the flat-list toggle, and
  must not offer the old "go to /signups" header link;
- the horse gallery must cap at five images and mark the cover.

`dom-stub.js` backs these suites, and two of its gaps mattered: a descendant selector
(`#tbl [data-page]`) only stepped into the *first* child of the previous match, so pager
buttons never had their click handler bound, and `innerHTML`/`appendChild` did not
re-parent parsed nodes, so `#tbl` was not an ancestor of the pager at all. Both are fixed.

> Note: this project's `php.ini` runs with `zend.assertions=-1`, which compiles
> `assert()` away. `FrontendContractTest` therefore throws explicit exceptions instead of
> asserting. The pre-existing suites still use `assert()`, so they pass vacuously unless
> run with `php -d zend.assertions=1` (where they currently report pre-existing failures).

Demo data is produced by `DemoSeeder` (`POST /panel/demo/seed`, or the panel's
backups/maintenance page): 20 clubs, 240 riders, 520 horses with health records, ~150
competitions spanning 104 weeks back and 8 weeks forward, ~6,000 signups with a
realistic status mix, plus results, transfers, shares, bans, notifications and
messages.

---

## Non-negotiable facts

- **Culture:** fa-IR (Persian, Iran), RTL-first.
- **Timezone:** Asia/Tehran.
- **Calendar:** Jalali (Shamsi) for display; UTC ISO-8601 in DB.
- **Currency:** IRT (Toman), integers only.
- **Deployment:** monolithic, copy-paste, no build step at runtime.
- **No Composer, no Node at runtime.** Libraries vendored as plain files.
- **Documentation is mandatory** for every file, class, method, and endpoint.
- **No versioning, no phasing.** This is the production-ready final version.

---

## Principles at a glance

Full list is in **Backend Blueprint §2**. Highlights:

- Writes go through services. Controllers never touch the DB directly.
- Every exception funnels through a `Handler`. No `die()` / `exit()` outside payment redirects.
- All money is integer IRT. All timestamps are UTC ISO-8601.
- Prepared SQL statements everywhere. Report queries are whitelist-driven.
- Sessions are DB-backed, 90-day fixed lifetime, UA-bound.
- Payments are idempotent. Price snapshots protect historical reports.
- Related logic lives in the same file where practical (lower file count).
- Seed data must be rich: one year of agency operation, ~52 competitions, ~1,800 signups.

---

## External integrations

| Integration | Purpose | Default |
|---|---|---|
| **ZarinPal** | Online payment in IRT (Toman) | Required for paid signups |
| **MelyPayamak** | SMS notifications and OTP | Disabled by default; Admin enables |

No other external services are required. Email is dropped entirely.

---

## For AI Agents (OpenHands, Copilot, CrewPilot)

**Start here.** `AGENTS.md` is the single source of truth for AI agents working in this repository. It contains:

- Tech stack and architecture principles (10 mandatory P-principles)
- Roles and permissions matrix
- Key file locations for every layer
- Authentication and security rules
- Development workflow (adding routes, reports, DB changes)
- Code standards and documentation requirements
- Integration points (ZarinPal, MelyPayamak)
- Testing approach
- Iran-specific conventions (Jalali calendar, Toman currency, Persian digits)

---

## Getting started

### For the client / stakeholders
1. Read the **Project Proposal**.
2. Skim the **Backend Blueprint §1–4** for context.
3. Review **Backend Blueprint §27** (Risk Register) for prerequisites.

### For developers
1. Read the **Backend Blueprint** end-to-end.
2. Read the **Technical** document end-to-end.
3. Read the **User Usage** document for the frontend contract.
4. Follow **Backend Blueprint §26** (Implementation Checklist) in order.
5. Enforce **Backend Blueprint §2** (Principles) in every file.

### Before deployment
1. Complete every item in **Backend Blueprint §27.1** (Client Prerequisites).
2. Run the installer (`/install`).
3. Seed reference data.
4. Create the first Admin.
5. Take a backup.
6. Smoke-test the payment callback with a small live ZarinPal transaction.
7. Enable SMS only after verifying MelyPayamak credentials work.

---

## Support

- **Specification questions:** refer to the specific document and section number.
- **Implementation questions:** check the Technical document first.
- **UX questions:** check the User Usage document first.
- **Domain questions:** check the Blueprint §6 (Glossary) and §8 (Flows).

---

## License & ownership

The codebase is the property of the Gorgan Horse Federation. No third-party SaaS, no vendor lock-in, no per-user fees.
