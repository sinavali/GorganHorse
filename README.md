# README.md — Gorgan Horse Federation Panel

**Project:** Backend panel for the Gorgan Horse Federation (هیئت سوارکاری استان گلستان)
**Status:** Specification complete, ready for implementation
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
| [`docs/Technical — Gorgan Horse Federation Panel.md`](./docs/Technical%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Implementers, maintainers | Stack, implementation rules, auth internals, validations, deployment. |
| [`docs/User Usage — Gorgan Horse Federation Panel.md`](./docs/User%20Usage%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Frontend developers, UX | Every screen, flow, KPI, empty state, error message, print view. |

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
├── docs/                   ← all specification docs
├── public/                      ← web root (single entry point)
├── app/                         ← application code
├── database/                    ← schemas, seeds, runtime DBs
├── cultures/                    ← culture JSON files (fa-IR, en-US)
├── uploads/                     ← user-uploaded media
├── cache/                       ← file cache
├── logs/                        ← raw log retention
├── backups/                     ← zip backups
├── vendor/                      ← vendored PHP libraries
└── docs/                        ← auxiliary files (nginx sample, etc.)
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
  (sidebar, topbar, filter bars, pagers, buttons) removed.
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

`tests/Unit/FrontendContractTest.php` also reflects over every controller to assert each
public method is callable as `method($request, $ctx)` — the Kernel only passes those two
arguments, and route parameters arrive via `$request->attr()`. A controller declaring a
third required parameter 500s every route that uses it.

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
