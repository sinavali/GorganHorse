# Backend Blueprint — Gorgan Horse Federation Panel

**Document type:** Master Blueprint
**Companion documents:** Technical, User Usage, Project Proposal
**Status:** Final, production-ready, no phasing, no versioning
**Audience:** Implementers, reviewers, maintainers, product stakeholders

---

## Table of Contents

1. Overview
2. Principles
3. Scope
4. Roles & Permissions
5. Folder Structure
6. Domain Glossary
7. Database Schema
8. Domain Model & Flows
9. HTTP Envelope
10. Routes
11. Middleware Pipeline
12. Authorization Matrix
13. Reports & KPIs
14. Integrations
15. Settings Registry
16. Seed Data Principles
17. Printing & QR
18. Notifications & Messaging
19. File Uploads & Media
20. Caching & Logging
21. Backup / Restore / Reset / Demo
22. Installer
23. Security
24. Naming Conventions
25. Error Codes
26. Implementation Checklist
27. Risk Register & Prerequisites
28. Deployment & Portability
29. Closing Notes

---

## 1. Overview

The Gorgan Horse Federation Panel is a **monolithic PHP 8.x web panel** for managing horse-riding competitions in Golestan province, Iran. It is a **single-host, no-framework, RTL-first, fa-IR-first, Shamsi-aware** application serving federation internal operations: clubs, riders, horses, rades, competitions, signups, payments, results, and reporting.

The panel is hosted on a **subdomain** (e.g. `panel.gorganhorse.ir`). The public site (landing pages, blog, SEO) is handled separately by WordPress on the main domain. There is **no session sharing** between the two systems.

The panel is **authenticated-only** for all users (Admin, Manager, Rider, Club). The only public endpoints are the ZarinPal payment callbacks.

### 1.1 Audience

- **Implementers** — this document is the contract. Every architectural decision here is final.
- **Owners** — read the Project Proposal document for the executive summary.
- **Reviewers** — read Principles (§2), Naming (§24), Error Codes (§25), and the Technical document.

### 1.2 Non-negotiable facts

- Main culture: **fa-IR** (Persian, Iran)
- Timezone: **Asia/Tehran**
- Calendar: **Jalali (Shamsi)** for display; **UTC ISO-8601** in DB
- Direction: **RTL-first**
- Currency: **IRT (Iranian Toman)**, integers only
- Deployment: **copy-paste monolithic**, no build step required at runtime
- Frontend stack: **PHP-templated views + Alpine.js + Tailwind + AG Grid + SheetJS**

---

## 2. Principles

These principles are **mandatory**. Every line of code, every file, every method must comply.

### P01 — Documentation is mandatory

Every file, class, method, function, and endpoint **must** be documented in its header comment. Documentation must include:

- **Purpose** — what it does and why it exists.
- **Parameters** — type, meaning, constraints, defaults.
- **Return value** — type, shape, meaning.
- **Throws** — every exception it can raise.
- **Side effects** — DB writes, cache clears, file writes, notifications, SMS, external calls.
- **Example** — one working example for public methods and endpoints.

For controllers: also document the **route**, **auth requirement**, **role requirement**, and **JSON envelope shape**.

For services: also document **transaction boundaries** if the method wraps multiple writes.

For schema tables: document every column with a one-line description in the schema file.

For settings: every key is documented in the settings registry with type, default, and purpose.

**Undocumented code is rejected at review.**

### P02 — No soft delete

Hard delete with defined cascade rules, except:
- **Horses**: `soft_deleted` status (kept for historical reports).
- **Signups**: `cancelled` / `withdrawn` status (kept for financial records).

### P03 — Time handling

- All timestamps stored as **UTC ISO-8601** (`YYYY-MM-DDTHH:MM:SSZ`).
- Shamsi conversion is **display-only**.
- Report date filters accept Shamsi input; the backend converts to UTC ranges before querying.
- Jalali dates in seed data are converted to UTC at seed time.

### P04 — Money

- All money stored as **integer IRT (Toman)**.
- No floats.
- No Rial.
- No currency strings in DB.

### P05 — IDs and UUIDs

- Primary keys: `INTEGER PRIMARY KEY AUTOINCREMENT`.
- External references: `uuid TEXT UNIQUE` (v4).
- Never expose sequential IDs in URLs where a UUID can be used for the same purpose.

### P06 — SQL

- **Prepared statements everywhere.**
- No string concatenation of user input into SQL.
- Report queries use **whitelist-driven builders**. Client-supplied column names, operators, and sort keys must be validated against the report's declared schema.

### P07 — CSRF

- Every state-changing request (POST, PUT, PATCH, DELETE) requires a valid CSRF token.
- Token is per-session, rotated on privilege change.
- Token is echoed in every JSON envelope and injected into every HTML form.

### P08 — Sessions

- DB-backed sessions.
- Fixed 90-day lifetime from creation. **No sliding window.**
- Session payload contains only: `user_id`, `role`, `impersonated_by`, `csrf_token`, `ua_hash`, `last_activity_at`. Nothing else. Sensitive data fetched fresh per request.
- UA hash mismatch kills the session.
- IP change logs a warning; second mismatch kills the session.
- **Admins can force-logout all users** from the Users page (revokes all sessions except their own).

### P09 — Writes go through services

- Controllers never touch the database directly.
- Every write goes through a Service method.
- Services may not call other services in a cycle; cross-domain coordination happens in a dedicated coordinator method with a documented transaction boundary.

### P10 — Results and exceptions

- Every service method returns a result **or** throws a domain exception. No silent failures, no `false` returns as error signals.
- Every exception funnels through the `Handler`.
- `die()` and `exit()` are **forbidden** outside of payment redirects (which must use `exit()` intentionally after setting a Location header).

### P11 — Configuration

- All config lives in the `settings` table.
- No `.env` files.
- No `config/*.php`.
- Defaults are seeded at install.

### P12 — Cache

- All cache keys are hashed and namespaced.
- No raw keys.
- Namespaces: `settings`, `cultures`, `thumbs`, `reports`.
- Invalidation is namespace-scoped, not global (unless admin triggers a full clear).

### P13 — Logging

- All logs are **JSON lines**, one event per line.
- **Main app logs** (audit, app, login attempts, SMS, OTP, changelog) live in a **separate SQLite database** (`logs.sqlite`) for smaller main DB and faster queries.
- Rotation: daily folders, monthly parents.
- Retention: app 30d, audit 180d, SMS 90d, login attempts 30d. Payment orders retained forever (in main DB).

### P14 — Vendored libraries

- No Composer.
- Libraries vendored as plain files under `vendor/`.
- Custom PSR-4-ish autoloader maps namespaces to folders.

### P15 — Single front controller

- One entry point: `public/index.php`.
- All routes defined in `app/Http/routes.php`.

### P16 — Mobile-first, RTL-first

- Every page usable on a phone.
- All CSS uses logical properties (`margin-inline-start`, not `margin-left`).
- LTR is the special case, not the default.

### P17 — No cron, no queue

- Opportunistic cleanups (1-in-N probability per request).
- No background workers.

### P18 — Monolithic deployment

- One folder. Copy-paste deployable.
- No external services required at runtime except ZarinPal and MelyPayamak (both optional).

### P19 — Unified list pages

- One page per entity type, filtered by role via query parameters.
- No duplicate pages for Admin vs Manager vs Club.
- Bulk operations available on every list page.

### P20 — WordPress is separate

- No session sharing.
- No shared DB.
- No shared code.
- No WordPress API calls from the panel.

### P21 — Payments are idempotent

- Callbacks can be retried safely.
- Lock per authority prevents double processing.

### P22 — Price snapshots

- When a payment is made, the payment record's data is **snapshotted** into the signup row.
- Editing a payment template later never affects historical reports.

### P23 — File merging preference

- Related logic lives in the **same file** where practical.
- Multiple related classes may live in one file with the same namespace.
- The goal is **lower file count** and **lower cognitive overhead**, without sacrificing clarity.
- Target: ~40 PHP files in `app/` (excluding views).

### P24 — Seed data is rich

- Seed data must produce a **working agency view over one year of operation**.
- Reference principles in §16 for volume, variety, and validity.

### P25 — Culture is Iran-Tehran specific

- Default and primary culture: **fa-IR**.
- Timezone: **Asia/Tehran**.
- Calendar: **Jalali**.
- Numbers: Persian digits.
- Currency display: Iranian Toman.
- Phone: +98.
- All seed data uses Iranian names, cities, phone numbers.

### P26 — Impersonation is audited

- Every impersonated write is tagged with `impersonated_by`.
- Admin can impersonate any user.
- Banner in UI + `meta.impersonating` in envelope.

### P27 — Every entity page is A4-printable

- Competition, Horse, Rider, Club, Payment (single + list), Signup sheet (per competition), Standings.
- Clean layout, well-structured, branded.

### P28 — Every form has inline help tooltips

- Non-technical users are the primary audience.
- Tooltips explain complex fields.

### P29 — No dark mode, no keyboard shortcuts, no PWA

- Scope is locked. These are out.

### P30 — Documentation, again

- Every controller method includes an example request/response.
- Every service method includes a usage example.
- Every schema table includes a header comment with purpose and relationships.
- Every report class includes sample output columns.

---

## 3. Scope

### 3.1 In scope

- Authentication (username/password, optional OTP, captcha, sessions, rate limiting)
- Roles: Admin, Manager, Rider, Club
- Culture system (fa-IR default, en-US optional)
- Unified users module
- Clubs (with user accounts, bans, disable states, reports)
- Horses (with transfers, shares, images, soft delete, CSV import/export)
- Rades
- Payments (reusable price templates)
- Competitions (with venue club, Rade assignments, pause/resume, clone)
- Competition-Rades (bind Rade + Payment + capacity + auto-confirm + barrage flag)
- Signups (Rider × Horse × Competition-Rade)
- Payment orders (ZarinPal integration, IRT only)
- Results entry (draft → confirmed → published, per competition)
- Reports (unified page, rich filters, KPIs, exports, sharing)
- Notification center + broadcast messages
- SMS (MelyPayamak, disabled by default, OTP + notifications)
- Settings (rich registry)
- Backup / Restore / Reset / Demo seeding
- Installer wizard
- API-first JSON envelope
- A4 printable entities
- QR codes (URL + state, authed panel only)
- Bulk operations on all list pages
- Audit changelog (Admin sees Manager actions)
- Session management (Admin force-logout)
- Impersonation (Admin only)
- In-panel documentation tooltips

### 3.2 Out of scope

- Blog (WordPress handles it)
- Landing pages (WordPress handles them)
- Public site (except payment callbacks)
- WordPress API (developed separately)
- Email sending (dropped)
- PWA
- SEO files (`robots.txt`, `sitemap.xml`)
- Snapshot report sharing (replaced by in-panel view shares)
- Bulk SMS
- Signup waitlists
- Standalone health check endpoint
- Dark mode
- Keyboard shortcuts
- CLI
- Multi-tenancy
- Multi-database for the main app (single prod DB file)
- Migrations from old WordPress data
- Column parity with old Excel plugin (richer by default)
- Multi-round / barrage automation (manual flag only)
- Historical report preservation from old system

---

## 4. Roles & Permissions

### 4.1 Role definitions

**Admin** — full system access. Creates Managers and Clubs. Manages settings, backups, reset, demo, audit. Impersonates anyone. Force-logouts sessions.

**Manager** — full data access. Manages clubs, horses, rades, payments, competitions, signups, results, reports. Cannot: manage users above rider/club level, access settings, backups, reset, audit, or impersonate.

**Rider** — manages own profile, own horses, own signups. Views own reports. Can share reports to Managers/Admins only.

**Club** — manages own profile. Views affiliated riders, competitions at own venue, own bans. Full report access scoped to own club. Cannot share reports.

### 4.2 Disable states

Users (Riders and Clubs) can be in one of three states:

- `none` — normal.
- `limited` — can log in, can view, **cannot create or modify** records. Can generate reports. Shows a warning banner.
- `full` — cannot log in. Login attempt shows a "account is banned" message with instructions to contact support.

### 4.3 Ban types

- **Club bans** — a Club bans a Rider or Horse from selecting that Club as affiliation. Forward-looking only.
- **Admin/Manager bans** — global (Rider), per Competition, or per Rade. Priority: **Rider > Competition > Rade** (highest wins).

---

## 5. Folder Structure

Merged structure per P23. Target ~40 PHP files in `app/`.

```
/
├── .htaccess                          # → /public
├── README.md                          # project index
├── docs/
│   ├── README.md                      # documents index
│   ├── Backend Blueprint — Gorgan Horse Federation Panel.md
│   ├── Technical — Gorgan Horse Federation Panel.md
│   ├── User Usage — Gorgan Horse Federation Panel.md
│   └── Project Proposal — Gorgan Horse Federation Panel.md
├── public/
│   ├── index.php                      # single front controller
│   ├── .htaccess
│   ├── favicon.ico
│   └── assets/
│       ├── css/
│       │   ├── tailwind.css
│       │   ├── panel.css
│       │   └── print.css
│       ├── js/
│       │   ├── app.js
│       │   ├── grid.js
│       │   ├── qr.js
│       │   └── vendor/
│       ├── fonts/
│       └── img/
├── app/
│   ├── Bootstrap/
│   │   └── App.php
│   ├── Http/
│   │   ├── Kernel.php
│   │   ├── Middleware.php
│   │   ├── routes.php
│   │   └── Controllers/
│   │       ├── AuthController.php
│   │       ├── DashboardController.php
│   │       ├── UserController.php
│   │       ├── ClubController.php
│   │       ├── HorseController.php
│   │       ├── RadeController.php
│   │       ├── PaymentController.php
│   │       ├── CompetitionController.php
│   │       ├── SignupController.php
│   │       ├── ResultController.php
│   │       ├── ReportController.php
│   │       ├── NotificationController.php
│   │       ├── SettingsController.php
│   │       ├── AdminController.php
│   │       └── PrintController.php
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── UserService.php
│   │   ├── ClubService.php
│   │   ├── HorseService.php
│   │   ├── RadeService.php
│   │   ├── PaymentService.php
│   │   ├── CompetitionService.php
│   │   ├── SignupService.php
│   │   ├── ResultService.php
│   │   ├── BanService.php
│   │   ├── NotificationService.php
│   │   ├── SmsService.php
│   │   ├── CultureService.php
│   │   ├── SettingService.php
│   │   ├── CacheService.php
│   │   ├── LogService.php
│   │   ├── MediaService.php
│   │   ├── Report/
│   │   │   ├── ReportEngine.php
│   │   │   ├── KpiService.php
│   │   │   └── Reports.php
│   │   └── Admin/
│   │       ├── BackupService.php
│   │       └── DemoSeeder.php
│   ├── Support/
│   │   └── Helpers.php
│   ├── Exceptions/
│   │   └── Exceptions.php
│   ├── Models/
│   │   └── Models.php
│   └── Views/
│       ├── layouts/
│       │   ├── panel.php
│       │   ├── auth.php
│       │   └── print.php
│       ├── partials/
│       │   ├── sidebar.php
│       │   ├── topbar.php
│       │   ├── notification-bell.php
│       │   ├── banners.php
│       │   ├── tooltip.php
│       │   └── pagination.php
│       ├── panel/
│       │   ├── dashboard.php
│       │   ├── users.php
│       │   ├── user-edit.php
│       │   ├── profile.php
│       │   ├── clubs.php
│       │   ├── club-edit.php
│       │   ├── horses.php
│       │   ├── horse-edit.php
│       │   ├── horse-shares.php
│       │   ├── rades.php
│       │   ├── rade-edit.php
│       │   ├── payments.php
│       │   ├── payment-edit.php
│       │   ├── competitions.php
│       │   ├── competition-edit.php
│       │   ├── competition-results.php
│       │   ├── signups.php
│       │   ├── rider-competitions.php
│       │   ├── rider-signup.php
│       │   ├── payment-orders.php
│       │   ├── payment-order.php
│       │   ├── reports.php
│       │   ├── notifications.php
│       │   ├── messages.php
│       │   ├── settings.php
│       │   ├── audit.php
│       │   └── backups.php
│       ├── auth/
│       │   ├── login.php
│       │   ├── signup.php
│       │   └── forgot.php
│       ├── print/
│       │   ├── competition.php
│       │   ├── horse.php
│       │   ├── rider.php
│       │   ├── club.php
│       │   ├── payment.php
│       │   ├── payment-list.php
│       │   ├── signup-sheet.php
│       │   └── standings.php
│       └── errors/
│           ├── 404.php
│           ├── 500.php
│           └── maintenance.php
├── database/
│   ├── app.sqlite
│   ├── logs.sqlite
│   ├── schema.sql
│   ├── schema_logs.sql
│   ├── upgrades/
│   └── seeds/
│       ├── demo.php
│       └── reference.php
├── cultures/
│   ├── fa-IR.json
│   └── en-US.json
├── uploads/
│   ├── .htaccess
│   ├── avatars/
│   ├── horses/
│   ├── clubs/
│   └── demo/
├── cache/
│   ├── .htaccess
│   ├── settings/
│   ├── cultures/
│   └── thumbs/
├── logs/
│   ├── app/YYYY-MM/YYYY-MM-DD.log
│   └── audit/YYYY-MM/YYYY-MM-DD.log
├── backups/
│   └── .htaccess
├── vendor/
│   ├── autoload.php
│   ├── morilog/jalali/
│   ├── giggsey/libphonenumber-for-php/
│   ├── ezyang/htmlpurifier/
│   └── intervention/image/
└── docs/
    └── nginx.conf.sample
```

**Note:** The Technical document is the authority on file merging (P23). The Blueprint folder structure illustrates the target; specific class-to-file assignments follow Technical §4.3.

**Removed from earlier drafts:** `storage/shares/` (snapshots dropped), `ui.theme` setting (no dark mode).

**Reserved (present in schema, unused):** `password_resets`, `api_tokens`.

---

## 6. Domain Glossary

Every Persian term used in the panel, its English equivalent, and a one-line definition.

| Persian | English | Definition |
|---|---|---|
| رده | Rade | A reusable competition class definition (e.g. رده E, رده D1). |
| سوارکار | Rider | A user who rides horses and signs up for competitions. |
| اسب | Horse | A registered horse owned by exactly one rider. |
| باشگاه | Club | A federated club; can host competitions and/or serve as a rider's affiliation. |
| مسابقه | Competition | An event at a venue, with a registration window and a date. |
| شرکت در مسابقه | Signup | A rider entering a horse into a Rade of a Competition. |
| اسب جایگزین | Replacement horse | (Deprecated in favor of shares) A horse shared to a rider by another owner. |
| انتقال | Transfer | Handing ownership of a horse from one rider to another. |
| اشتراک | Share | Allowing another rider to use your horse in a signup. |
| تحریم | Ban | A forward-looking restriction on a rider or horse. |
| ممنوعیت | Disable | A user-level state blocking login (full) or modification (limited). |
| باراژ | Barrage | A tie-breaker round; recorded as a flag + notes per Competition-Rade. |
| ثبت‌نام | Registration | The act of registering for a competition. |
| صورت‌حساب | Payment Order | The ZarinPal transaction record for a signup. |
| مبلغ | Amount | Money in IRT (Toman), integer only. |
| الگوی پرداخت | Payment Template | A reusable price definition, bound to Competition-Rades. |
| کد اشتراک | Share code | A 6-digit code generated per (owner, horse, recipient). |
| کد انتقال | Transfer code | An 8-char code generated per transfer request. |
| مقام | Position | Final rank in a Rade (1st, 2nd, ...). |
| برنده | Winner | A boolean flag on a signup. |
| رده‌بندی | Standings | Aggregated performance across competitions. |
| تأیید | Verification | Rider account approval by Manager (or auto after 48h). |
| محدودیت | Limited state | User can log in but cannot create/modify records. |
| مسدودی | Full disable | User cannot log in. |
| جعل هویت | Impersonation | Admin acting as another user. |
| لاگ تغییرات | Changelog | Summary of Manager actions for Admin review. |
| لاگ حسابرسی | Audit log | Full write trail with actor, target, diff. |

---

## 7. Database Schema

The main DB (`app.sqlite`) holds operational data. The logs DB (`logs.sqlite`) holds audit, app logs, login attempts, SMS logs, OTP codes, and changelog.

Both databases are initialized from `schema.sql` and `schema_logs.sql`. Foreign keys enabled. WAL mode. Busy timeout 5s.

### 7.1 Main DB tables

| Table | Purpose |
|---|---|
| `users` | All accounts (Admin, Manager, Rider, Club) |
| `rider_profiles` | Rider-specific metadata |
| `sessions` | DB-backed sessions |
| `clubs` | Club profiles + linked club user account |
| `club_bans` | Clubs banning riders/horses from affiliation |
| `rider_bans` | Admin/Manager bans (global, competition, rade) |
| `horse_races` | Controlled vocabulary — races |
| `horse_colors` | Controlled vocabulary — colors |
| `horse_genders` | Controlled vocabulary — genders |
| `horses` | Horse records |
| `horse_images` | Horse gallery (max 5) |
| `horse_transfers` | Transfer requests |
| `horse_shares` | Owner shares horse to specific rider |
| `rades` | Reusable class definitions |
| `payments` | Reusable price templates |
| `competitions` | Competitions |
| `competition_rades` | Bind Rade + Payment + capacity + auto-confirm |
| `signups` | Rider × Horse × Competition-Rade |
| `payment_orders` | ZarinPal order lifecycle |
| `notifications` | In-panel notifications |
| `messages` | Broadcast messages |
| `message_recipients` | Broadcast recipients |
| `report_shares` | In-panel report shares |
| `media` | Uploaded files |
| `settings` | Rich settings registry |
| `cultures` | Culture list |
| `rate_limits` | Generic rate limiting |
| `password_resets` | Reserved (manager-triggered reset) |
| `api_tokens` | Reserved (future API access) |

### 7.2 Logs DB tables

| Table | Purpose |
|---|---|
| `audit_logs` | Every write with actor, target, diff |
| `app_logs` | Errors, warnings, slow queries |
| `login_attempts` | Failed and blocked logins |
| `sms_logs` | Outbound SMS |
| `otp_codes` | Hashed OTP codes |
| `changelog` | Manager action summaries |

### 7.3 Schema files

- `database/schema.sql` — main DB DDL with per-column comments.
- `database/schema_logs.sql` — logs DB DDL with per-column comments.

Full DDL is not reproduced in this document; it lives in the schema files and follows the entity list in §7.1–7.2. Every column is documented in the schema file with a one-line comment.

---

## 8. Domain Model & Flows

### 8.1 Core entities

- **Club** — profile + linked user account. Can be a venue (host) and/or an affiliation (rider's chosen club).
- **Horse** — owned by exactly one Rider. Can be shared to other Riders. Can be transferred.
- **Rade** — a class definition (e.g. رده E, رده D1). Reusable across competitions.
- **Payment** — a reusable price template. Bound to Competition-Rades.
- **Competition** — an event at a venue, with a registration window and a date.
- **Competition-Rade** — a Rade offered at a Competition, with a Payment, capacity, auto-confirm flag, barrage flag, and signup mode.
- **Signup** — a Rider entering a Horse into a Competition-Rade.
- **Payment Order** — the ZarinPal transaction lifecycle for a signup.

### 8.2 Key flows

#### 8.2.1 Rider signup flow

1. Rider logs in (username/password or phone/OTP if SMS enabled).
2. Opens "Competitions" → sees open competitions.
3. Opens a competition → sees its Rades.
4. Picks a Rade → picks a Horse (own or shared to them) → picks an affiliation Club → confirms.
5. System creates a `signup` row (`pending_payment`) + a `payment_order` row (`pending`) + snapshots Payment data into the signup.
6. System calls ZarinPal request → gets `authority` → saves it → redirects to ZarinPal.
7. Rider pays → ZarinPal redirects to `/payment/callback`.
8. Callback verifies → order becomes `paid` → signup becomes `paid` → if `auto_confirm=1`, signup becomes `confirmed`; else awaits Manager confirmation.
9. Rider gets a panel notification (and SMS if enabled).

**Free signups:** if `amount_irt = 0`, ZarinPal is skipped. Signup goes directly to `paid`, or to `confirmed` if `auto_confirm=1`.

#### 8.2.2 Horse transfer flow

1. Current owner opens Horse detail → "Initiate Transfer" → locks the horse, resets the share code.
2. System generates a transfer code → owner shares it with the buyer.
3. Buyer enters the code → sees general owner info → submits.
4. Owner sees the buyer's general info → accepts or rejects.
5. On accept: ownership changes; transfer marked `completed`; horse unlocked.
6. On reject: transfer marked `rejected`; code remains valid; buyer can retry.
7. Historical signups remain attached to the horse.

#### 8.2.3 Horse share flow

1. Owner opens Horse detail → "Share" → enters the receiving Rider's 6-digit share code → system creates a `horse_share`.
2. The receiving Rider sees the horse in their signup Horse picker.
3. The displayed name clearly indicates "Shared by [owner nickname]".

#### 8.2.4 Results flow

1. Manager opens Competition → "Results" tab → entry grid.
2. Manager fills `position`, `is_winner`, `result_notes` per signup, and `had_barrage` + `barrage_notes` per Competition-Rade.
3. Click "Save Draft" → `results_status = draft`.
4. Click "Confirm Results" → `results_status = confirmed`.
5. Click "Publish Results" → `results_status = published` → riders notified (panel + SMS if enabled).
6. After publish: only Admin can reopen (`reopen` → back to `confirmed`).

#### 8.2.5 Ban flow

- **Club ban:** Club opens its Ban page → adds a Rider or Horse → forward-looking only.
- **Admin/Manager ban:** Manager opens User detail → "Ban" → selects scope (global, competition, rade) → saves. Priority: Rider > Competition > Rade.

#### 8.2.6 Disable flow

- Manager/Admin opens User detail → "Disable" → selects `limited` or `full`.
- `limited`: user can log in but cannot create records. Banner displayed.
- `full`: user cannot log in. Login attempt shows "banned" banner.

#### 8.2.7 Verification flow

1. Rider signs up → `verification_status = pending`, `auto_verify_at = now + 48h`.
2. Pending rider **can log in**, edit profile, sees banner. Cannot add horses. Cannot sign up.
3. Manager verifies → `verification_status = verified`.
4. Manager rejects → user + horses + uploads hard-deleted; signups anonymized; payment orders kept.
5. If no action within 48h → system auto-verifies on next request touching that user.

#### 8.2.8 Competition cancellation

1. Manager opens Competition → "Cancel" → confirms.
2. All payment orders for that competition → `pending_refund`.
3. Manager marks each as `refunded` after manual refund. Toggle back supported.

---

## 9. HTTP Envelope

Every JSON response:

```json
{
  "ok": true,
  "data": { },
  "meta": {
    "page": 1, "per_page": 50, "total": 1240, "filtered": 312,
    "culture": "fa-IR", "direction": "rtl", "timezone": "Asia/Tehran",
    "server_time_utc": "2025-01-01T10:00:00Z",
    "impersonating": false,
    "request_id": "..."
  },
  "errors": null,
  "flash": { "success": null, "error": null },
  "csrf": "token"
}
```

Error:

```json
{
  "ok": false,
  "data": null,
  "errors": [{ "code": "AUTH_INVALID", "field": "password", "message": "..." }],
  "meta": { "request_id": "..." },
  "csrf": "token"
}
```

HTTP statuses: 200, 201, 204, 302 (HTML), 400, 401, 403, 404, 409, 422, 423, 429, 500, 503.

---

## 10. Routes

### 10.1 Auth (guest)

| Method | Path | Purpose |
|---|---|---|
| GET | `/auth/login` | Login page |
| POST | `/auth/login` | Password login |
| POST | `/auth/login/otp/request` | Send OTP |
| POST | `/auth/login/otp/verify` | Verify OTP |
| GET | `/auth/signup` | Signup page |
| POST | `/auth/signup` | Create rider account |
| GET | `/auth/forgot` | Forgot page (contact support) |
| GET | `/captcha/{token}` | Captcha image |
| POST | `/auth/logout` | Logout |

### 10.2 Payment callbacks (public)

| Method | Path | Purpose |
|---|---|---|
| GET | `/payment/callback` | ZarinPal callback |
| GET | `/payment/success` | Success page |
| GET | `/payment/failed` | Failure page |

### 10.3 Panel (auth)

**Dashboard** — `GET /panel`

**Profile** — `GET/POST /panel/profile`, `POST /panel/profile/avatar`, `POST /panel/profile/password`, `GET /panel/profile/sessions`, `POST /panel/profile/sessions/{id}/revoke`, `POST /panel/profile/sessions/revoke-all`

**Users** — `GET /panel/users?role=...`, `POST /panel/users`, `GET/PUT/DELETE /panel/users/{id}`, `POST /panel/users/{id}/verify`, `POST /panel/users/{id}/reject`, `POST /panel/users/{id}/disable`, `POST /panel/users/{id}/enable`, `POST /panel/users/{id}/reset-password`, `POST /panel/users/{id}/impersonate`, `POST /panel/impersonation/stop`, `POST /panel/users/{id}/sessions/revoke-all`, `POST /panel/users/bulk`

**Clubs** — `GET/POST /panel/clubs`, `GET/PUT/DELETE /panel/clubs/{id}`, `POST /panel/clubs/{id}/bans`, `DELETE /panel/clubs/{id}/bans/{ban_id}`, `GET /panel/clubs/{id}/print`, `POST /panel/clubs/bulk`

**Horses** — `GET/POST /panel/horses`, `GET/PUT/DELETE /panel/horses/{id}`, `POST /panel/horses/{id}/sold-to-non-rider`, `POST /panel/horses/{id}/images`, `DELETE /panel/horses/{id}/images/{media_id}`, `POST /panel/horses/{id}/share`, `DELETE /panel/horses/{id}/share/{share_id}`, `POST /panel/horses/{id}/transfer/lock`, `POST /panel/horses/{id}/transfer/unlock`, `POST /panel/horses/{id}/transfer/initiate`, `POST /panel/horses/{id}/transfer/accept`, `POST /panel/horses/{id}/transfer/reject`, `POST /panel/horses/{id}/transfer/cancel`, `GET /panel/horses/{id}/history`, `GET /panel/horses/{id}/print`, `POST /panel/horses/import`, `GET /panel/horses/export-template`, `POST /panel/horses/bulk`

**Horse shares inbox** — `GET /panel/horse-shares`, `POST /panel/horse-shares/{id}/accept`, `POST /panel/horse-shares/{id}/reject`

**Rades** — `GET/POST /panel/rades`, `GET/PUT/DELETE /panel/rades/{id}`, `POST /panel/rades/bulk`

**Payments (templates)** — `GET/POST /panel/payments`, `GET/PUT/DELETE /panel/payments/{id}`, `GET /panel/payments/{id}/print`, `GET /panel/payments/print-list`, `POST /panel/payments/bulk`

**Competitions** — `GET/POST /panel/competitions`, `GET/PUT/DELETE /panel/competitions/{id}`, `POST /panel/competitions/{id}/pause`, `POST /panel/competitions/{id}/resume`, `POST /panel/competitions/{id}/cancel`, `POST /panel/competitions/{id}/clone`, `GET /panel/competitions/{id}/print`, `GET /panel/competitions/{id}/signup-sheet/print`, `POST /panel/competitions/bulk`

**Competition-Rades** — `POST /panel/competitions/{id}/rades`, `PUT /panel/competitions/{id}/rades/{comp_rade_id}`, `DELETE /panel/competitions/{id}/rades/{comp_rade_id}`, `POST /panel/competitions/{id}/rades/{comp_rade_id}/barrage`

**Signups** — `GET /panel/signups`, `GET /panel/signups/{id}`, `POST /panel/signups/{id}/confirm`, `POST /panel/signups/{id}/reject`, `POST /panel/signups/{id}/position`, `POST /panel/signups/bulk`

**Rider signup flow** — `GET /panel/rider/competitions`, `GET /panel/rider/competitions/{id}`, `POST /panel/rider/competitions/{id}/signup`, `GET /panel/rider/signups`, `GET /panel/rider/signups/{id}`

**Payment orders** — `GET /panel/payment-orders`, `GET /panel/payment-orders/{id}`, `POST /panel/payment-orders/{id}/verify`, `POST /panel/payment-orders/{id}/mark-pending-refund`, `POST /panel/payment-orders/{id}/mark-refunded`, `POST /panel/payment-orders/{id}/unmark-refund`, `GET /panel/payment-orders/{id}/print`, `POST /panel/payment-orders/bulk`, `GET /panel/payment-orders/reconciliation`

**Results** — `GET /panel/competitions/{id}/results`, `POST /panel/competitions/{id}/results`, `POST /panel/competitions/{id}/results/confirm`, `POST /panel/competitions/{id}/results/publish`, `POST /panel/competitions/{id}/results/reopen`

**Reports** — `GET /panel/reports`, `POST /panel/reports/data`, `POST /panel/reports/export`, `GET /panel/reports/download/{token}`, `POST /panel/reports/share`, `GET /panel/reports/shares`, `POST /panel/reports/shares/{id}/revoke`

**Notifications** — `GET /panel/notifications`, `POST /panel/notifications/{id}/read`, `POST /panel/notifications/read-all`

**Messages** — `GET /panel/messages`, `POST /panel/messages`, `GET /panel/messages/{id}`, `POST /panel/messages/{id}/read`

**Settings** — `GET/POST /panel/settings`, `GET/POST /panel/settings/sms`, `POST /panel/settings/sms/test`, `GET/POST /panel/settings/payment`, `POST /panel/settings/payment/test`, `POST /panel/cache/clear`

**Audit** — `GET /panel/audit`

**Backups** — `GET /panel/backups`, `POST /panel/backups`, `POST /panel/backups/{name}/restore`, `GET /panel/backups/{name}/download`, `DELETE /panel/backups/{name}`

**Maintenance** — `POST /panel/reset`, `POST /panel/demo/seed`, `POST /panel/demo/clear`

**Print** — `GET /panel/print/{entity}/{id}`

**QR** — `GET /panel/qr?data={url}`

---

## 11. Middleware Pipeline

Per request, in order:

1. `SecurityHeadersMiddleware`
2. `MaintenanceMiddleware` — 423 if maintenance
3. `CultureMiddleware` — resolve culture
4. `RateLimitMiddleware` — route-specific
5. `AuthMiddleware` — required/optional
6. `DisabledUserMiddleware` — block full-disable, restrict limited
7. `RoleMiddleware` — route-specific
8. `ImpersonationMiddleware` — tag writes
9. `CsrfMiddleware` — state-changing requests

---

## 12. Authorization Matrix

| Resource | Admin | Manager | Rider | Club |
|---|---|---|---|---|
| Users list | all | riders + clubs | — | — |
| Users create | any | rider + club | — | — |
| Users edit | any | rider + club | self | self |
| Users delete | ✓ | — | — | — |
| Impersonate | ✓ | — | — | — |
| Users disable | ✓ | ✓ | — | — |
| Clubs CRUD | ✓ | ✓ | — | — |
| Clubs view | ✓ | ✓ | list | own |
| Club bans | ✓ | ✓ | — | own |
| Horses CRUD | ✓ all | ✓ all | own | — |
| Horse transfer | ✓ | ✓ | own | — |
| Horse share | ✓ | ✓ | own | — |
| Rades CRUD | ✓ | ✓ | — | — |
| Payments CRUD | ✓ | ✓ | — | — |
| Competitions CRUD | ✓ | ✓ | — | — |
| Competition-Rades | ✓ | ✓ | — | — |
| Signups create | ✓ | ✓ | self | — |
| Signups confirm/reject | ✓ | ✓ | — | — |
| Results enter | ✓ | ✓ | — | — |
| Results publish | ✓ | ✓ | — | — |
| Results reopen | ✓ | — | — | — |
| Payment orders view | all | all | own | — |
| Payment orders verify | ✓ | ✓ | — | — |
| Payment orders refund | ✓ | ✓ | — | — |
| Reports | all | all | self | club |
| Reports share | any | any | to managers/admins | — |
| Notifications | ✓ | ✓ | ✓ | ✓ |
| Messages broadcast | ✓ | ✓ | — | — |
| Settings | ✓ | — | — | — |
| Audit | ✓ | — | — | — |
| Backups | ✓ | — | — | — |
| Reset / Demo | ✓ | — | — | — |
| Print any entity | ✓ | ✓ | own | own |
| QR | ✓ | ✓ | ✓ | ✓ |

---

## 13. Reports & KPIs

### 13.1 Unified report engine

One page: `/panel/reports`. Rich filters, rich columns, sidebar presets. AG Grid with column toggling, drag-and-drop reorder, sort, filter, pagination (default page 1). Grid state (columns, order, filters) persists in `localStorage` per user. Page number always resets to 1.

**Report types:** `signups`, `revenue`, `results`, `horses`, `riders`, `clubs`, `payments`, `bans`.

### 13.2 Sidebar presets

The sidebar pre-fills filter state via query params (e.g. "Signups — this competition" → `report=signups&competition_id=X`).

### 13.3 Dashboards & KPIs

**Admin dashboard**
- Total riders, horses, clubs, competitions
- Revenue this month, this week, today
- Pending verifications, confirmations, refunds
- Active sessions
- Signups over time (30d), revenue over time (30d), Rade popularity
- Recent changelog entries (last 20)
- Quick actions

**Manager dashboard**
- Same minus system/session stats
- Verification queue shortcut
- Confirmation queue shortcut
- Today's competitions

**Rider dashboard**
- My horses count
- My signups (pending/confirmed)
- My wins
- Upcoming competitions
- Pending shares/transfer requests

**Club dashboard**
- Competitions at venue (count)
- Affiliated riders (count)
- Revenue generated (this month)
- Active bans
- Reports shortcut

### 13.4 KPI definitions

| KPI | Formula |
|---|---|
| Confirmation rate | confirmed / paid |
| Revenue per rider | Σ amount / distinct riders |
| Win rate | Σ is_winner / count(signups) |
| Average position | avg(position) |
| Horse utilization | count(signups) / days_since_created |
| Club revenue share | club revenue / total revenue |
| Pending payment count | count(status='pending') |
| Refund rate | refunded / paid |
| Fill rate | signups / capacity |

---

## 14. Integrations

### 14.1 ZarinPal (production only)

- **Request:** `POST https://payment.zarinpal.com/pg/v4/payment/request.json`
- **Verify:** `POST https://payment.zarinpal.com/pg/v4/payment/verify.json`
- **Currency:** IRT
- **Callback:** `GET /payment/callback?Authority=X&Status=OK`
- **Idempotency:** lock per authority prevents double verification.
- **Refunds:** manual only; panel action marks `pending_refund` / `refunded`.

### 14.2 MelyPayamak (SMS)

- **Disabled by default.**
- Admin enables via `/panel/settings/sms`.
- **OTP:** 5-digit codes, 2-minute TTL, 3 attempts max, 5 requests per phone per hour, 10 per IP per hour.
- **Notifications:** signup, payment, confirmation, results, transfer, ban.
- **Fallback:** if SMS is disabled, OTP endpoints return 403 `SMS_DISABLED`.

### 14.3 Email

- **Completely dropped.**
- No PHPMailer, no SMTP settings, no email templates.
- Password reset: Admin/Manager sets a new password manually.

---

## 15. Settings Registry

### general
`app.name`, `app.tagline`, `app.env`, `app.debug`, `app.maintenance`, `app.maintenance_message`, `app.timezone_default` (`Asia/Tehran`), `app.default_culture` (`fa-IR`), `app.url_force_https`, `app.url_trusted_proxies`

### identity [system]
`app.key`, `app.key_rotated_at`

### whitelabel
`brand.name`, `brand.logo_light_media_id`, `brand.logo_dark_media_id`, `brand.favicon_media_id`, `brand.primary_color`, `brand.secondary_color`, `brand.login_background_media_id`, `brand.footer_text`, `brand.copyright`, `brand.pdf_header_text`, `brand.pdf_footer_text`

### auth
`auth.allow_signup`, `auth.password_min_length` (8), `auth.password_require_upper` (false), `auth.password_require_digit` (false), `auth.password_require_symbol` (false), `auth.session_absolute_days` (90), `auth.captcha_on_login`, `auth.captcha_on_signup`, `auth.captcha_on_otp_request`, `auth.rate_login_per_window` (5), `auth.rate_login_window_seconds` (300), `auth.rate_ip_hourly_cap` (20), `auth.auto_verify_hours` (48)

### uploads
`uploads.max_image_mb` (10), `uploads.max_doc_mb` (25), `uploads.user_quota_mb` (500), `uploads.image_max_dimension` (2560), `uploads.image_quality` (82), `uploads.image_format` (`original`), `uploads.allowed_image_mimes`, `uploads.path_pattern` (`{yyyy}/{mm}/{uuid}.{ext}`)

### clubs
`clubs.default_logo_media_id`, `clubs.enable_bans` (true), `clubs.ban_expiry_days` (365), `clubs.max_affiliated_riders` (null)

### horses
`horses.max_images` (5), `horses.share_code_length` (6), `horses.transfer_code_length` (8), `horses.transfer_expiry_days` (7), `horses.allow_transfer` (true), `horses.allow_sharing` (true)

### competitions
`competitions.default_capacity` (null), `competitions.allow_late_entries` (true), `competitions.auto_status_change` (true), `competitions.registration_pause_notice`, `competitions.result_entry_requires_confirmation` (true)

### payments
`payment.gateway` (`zarinpal`), `payment.zarinpal_merchant_id`, `payment.currency` (`IRT`), `payment.callback_url`, `payment.success_redirect`, `payment.failed_redirect`

### sms
`sms.enabled` (false), `sms.username`, `sms.password`, `sms.sender_number`, `sms.otp_pattern`, `sms.otp_ttl_seconds` (120), `sms.otp_max_attempts` (3), `sms.otp_length` (5), `sms.notify_on_signup` (true), `sms.notify_on_payment` (true), `sms.notify_on_confirmation` (true), `sms.notify_on_results` (true), `sms.notify_on_transfer` (true), `sms.notify_on_ban` (true), `sms.rate_limit_per_hour` (10)

### reports
`reports.default_per_page` (50), `reports.max_export_rows` (50000), `reports.enable_grid_state_persistence` (true), `reports.print_header_text`, `reports.print_footer_text`

### cache
`cache.enabled`, `cache.ttl_settings`, `cache.ttl_cultures`, `cache.ttl_thumbs`

### logs
`logs.app_retention_days` (30), `logs.audit_retention_days` (180), `logs.sms_retention_days` (90), `logs.login_retention_days` (30), `logs.cleanup_probability` (0.01)

### backup
`backup.include_shares`, `backup.retention_count`, `backup.suffix_default`

### security
`security.headers_csp`, `security.headers_hsts`, `security.cookie_same_site` (`lax`), `security.trusted_ips_admin`

### ui
`ui.font_primary` (`Vazirmatn`), `ui.panel_path` (`panel`), `ui.default_avatar_admin_text` (`A`), `ui.default_avatar_manager_text` (`M`)

**Removed:** `ui.theme` (no dark mode).

---

## 16. Seed Data Principles

Seed data is **not included in this blueprint** as fixed records. Instead, the seeder must satisfy the following principles and volume.

### 16.1 Volume (mandatory minimums)

- **Clubs:** 4 active clubs
- **Rades:** 6 rade definitions (آزاد، رده E، رده D، رده D1، رده تمرینی، رده مبتدی)
- **Payments:** 5 payment templates
- **Riders:** 60 riders, split across experience levels (30 active, 20 occasional, 10 inactive)
- **Horses:** 90 horses, distributed across riders (1–4 per rider), with valid microchips, valid races, valid colors, and correct ownership history
- **Competitions:** 52 competitions, one per week over 12 months (2025-01 to 2025-12), each with 3–6 Rades
- **Signups:** ~1,800 signups distributed across competitions and Rades (5–20 per Rade)
- **Payment orders:** ~1,600 paid, ~150 pending, ~50 refunded
- **Results:** published for all competitions older than 30 days, draft for the last 4
- **Transfers:** 8–12 horse transfers over the year
- **Shares:** 15–20 active horse shares
- **Bans:** 3 club bans, 5 admin bans
- **Notifications:** generated as side effects
- **Messages:** 6 broadcast messages

### 16.2 Validity rules

- All dates in the past.
- All Shamsi conversions accurate.
- All phone numbers valid Iranian +98.
- All national IDs pass the Iranian check-digit algorithm.
- All microchips 15-digit and unique.
- All competition dates past or future as appropriate.
- All payment amounts in IRT.
- All results plausible (winner exists, positions unique per Rade).
- All horses belong to their owners.

### 16.3 Variety rules

- Iranian first names, last names, horse names, club names.
- Iranian cities (Gorgan, Tehran, Mashhad, etc.).
- Iranian clubs (باشگاه هیرکان، باشگاه شکوه طبیعت، باشگاه سزار).
- Horse colors, races, genders from controlled vocabularies.
- Rades with realistic distributions (آزاد and رده E most popular).
- Some competitions with barrage (`had_barrage=1`), most without.
- Some riders with multiple horses, some with one.
- Some horses with multiple signups across different Rades and competitions.
- Some payment orders marked `refunded`.
- Some clubs with bans.

### 16.4 Seed structure

The seeder must be **idempotent**. Re-running never duplicates records. All seeded rows carry `is_demo = 1` so they can be cleared in bulk.

### 16.5 Demo clear

"Clear demo" removes only rows where `is_demo = 1`, and their associated files under `uploads/demo/`.

---

## 17. Printing & QR

### 17.1 Printable entities

- Competition (single + list)
- Horse (single + list)
- Rider (single)
- Club (single + list)
- Payment (single + list + selected-as-individuals)
- Signup sheet (per competition)
- Standings (per rider, per horse, per rider-horse pair)

### 17.2 Print styles

- A4, portrait.
- Print header shows brand + entity title + Shamsi date + QR code.
- Print footer shows page number + federation name.
- No JS needed at print time; server-rendered HTML.

### 17.3 QR codes

- QR encodes the current panel URL + query state.
- Authed panel users scanning it see the same view.
- Generated on-the-fly via `qr.js` (client) or `/panel/qr?data=...` (server, cached).
- Available on: competition paper, signup sheet, report page, entity page header.

---

## 18. Notifications & Messaging

### 18.1 Panel notifications

Events that generate notifications:

- Signup created
- Payment received
- Payment failed
- Payment refunded
- Rider verification pending
- Rider verified
- Rider rejected
- Signup confirmed
- Signup rejected
- Competition assigned to club
- Ban applied
- Transfer request received
- Transfer accepted
- Transfer rejected
- Horse shared to you
- Results published
- Admin broadcast

### 18.2 Broadcast messages

- Managers/Admins can create broadcasts to: all riders, all riders of a competition, all riders of a competition-rade, selected users.
- Delivered in-panel and (if SMS enabled) via SMS.
- Read receipts tracked per recipient.

### 18.3 SMS notification list (important events)

The following notifications also go via SMS when `sms.enabled = true` and the corresponding `sms.notify_on_*` is true:

- Signup created (rider)
- Payment received (rider)
- Payment failed (rider)
- Signup confirmed (rider)
- Signup rejected (rider)
- Results published (rider)
- Transfer request received (owner)
- Transfer accepted (initiator)
- Horse shared to you (recipient)
- Ban applied (target)
- Verification verified (rider)
- Admin broadcast (recipients)

---

## 19. File Uploads & Media

- Single version per file.
- UUID filenames.
- Extension coerced from MIME.
- Images compressed server-side (GD-first, Imagick if available).
- SVG sanitized.
- Per-kind size caps.
- `.htaccess` in `uploads/` disables PHP execution.
- Horse gallery: max 5 images per horse.
- Avatars: Riders and Clubs can upload. Admins and Managers use default text avatars (A / M).

---

## 20. Caching & Logging

### 20.1 Cache

- File-based under `cache/`.
- Namespaces: `settings`, `cultures`, `thumbs`, `reports`.
- TTL per namespace (settings-configurable).
- Admin "Clear Cache" clears all.

### 20.2 Logs

- **App logs** (`logs.sqlite.app_logs`) — errors, warnings, slow queries (>200 ms).
- **Audit logs** (`logs.sqlite.audit_logs`) — every write with actor, target, diff.
- **Changelog** (`logs.sqlite.changelog`) — Manager action summary for Admin.
- **Login attempts** (`logs.sqlite.login_attempts`) — only failures and blocks.
- **SMS logs** (`logs.sqlite.sms_logs`).
- **OTP codes** (`logs.sqlite.otp_codes`) — hashed.
- File-based JSON-lines rotation under `logs/app/YYYY-MM/YYYY-MM-DD.log` and `logs/audit/YYYY-MM/YYYY-MM-DD.log`.
- Retention: app 30d, audit 180d, SMS 90d, login attempts 30d. Payment orders retained forever (main DB).

---

## 21. Backup / Restore / Reset / Demo

### 21.1 Backup

- Admin UI trigger.
- Zip: `app.sqlite`, `logs.sqlite`, `uploads/`.
- DB copy via `VACUUM INTO`.
- Naming: `backup-YYYY-MM-DD_HHMMSS-{suffix}.zip`.
- Storage: `backups/`.

### 21.2 Restore

1. Enter maintenance.
2. Pre-restore safety snapshot.
3. Replace DB + uploads.
4. Re-inject pre-restore app key.
5. Upsert current admin.
6. Revoke all sessions except current admin's.
7. Clear cache.
8. Exit maintenance.
9. Audit entry.

### 21.3 Reset

- Wipes DB data (except settings + current admin).
- Wipes uploads/.
- Re-injects current admin + session.
- Clears cache.
- Audit entry.

### 21.4 Demo

- Seeder idempotent, rich (see §16).
- All rows tagged `is_demo = 1`.
- "Clear demo" removes only demo rows + demo media.

---

## 22. Installer

- Entry: `/install` (blocked if `settings` table populated).
- Steps:
  1. Requirements check (PHP version, extensions).
  2. Writable checks.
  3. Create `app.sqlite` + `logs.sqlite`.
  4. Apply `schema.sql` + `schema_logs.sql`.
  5. Generate `app.key`.
  6. Seed reference data (cultures, settings, roles, horse genders/races/colors).
  7. Create first Admin.
  8. Optional: seed demo data.
  9. Self-lock.

---

## 23. Security

- CSRF: per-session token, required on all state-changing requests.
- XSS: output escaping by default (`e()` helper); HTMLPurifier for rich HTML.
- SQLi: prepared statements; whitelist-driven report queries.
- Sessions: HttpOnly, SameSite=Lax, Secure, rotation on privilege change, UA binding.
- Rate limiting: login, signup, OTP.
- Captcha: server-generated, signed, single-use.
- Headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP (configurable), HSTS if HTTPS.
- Uploads: MIME sniffing, UUID rename, no execution.
- Sensitive dirs protected via `.htaccess` (Apache) + Nginx sample.
- Password hashing: `PASSWORD_DEFAULT`.
- Random: `random_bytes`.
- Installer self-locks.

---

## 24. Naming Conventions

Single source of truth for naming. Any deviation is a bug.

### 24.1 Database

| Item | Convention | Example |
|---|---|---|
| Table names | `snake_case`, plural | `competition_rades` |
| Column names | `snake_case` | `affiliation_club_id` |
| Primary keys | `id` | `id` |
| Foreign keys | `{referenced_table_singular}_id` | `rider_user_id`, `club_id` |
| Boolean columns | `is_*` / `has_*` / `allow_*` | `is_confirmed`, `had_barrage` |
| Timestamp columns | `*_at` | `created_at`, `verified_at` |
| UUID columns | `uuid` | `uuid` |
| Snapshot columns | `*_snapshot` | `payment_amount_irt_snapshot` |
| Enum columns | `status`, `*_status`, `*_state`, `*_type` | `verification_status` |
| JSON columns | `*_json` | `filter_state_json` |

### 24.2 Settings keys

- Dot notation: `{group}.{key}`.
- All lowercase, snake_case within segments.
- Examples: `auth.session_absolute_days`, `sms.otp_ttl_seconds`, `payment.zarinpal_merchant_id`.

### 24.3 Routes

- Lowercase.
- Kebab-case for multi-word segments.
- Plural for collections.
- Nouns for resources; verbs only for actions.
- Examples: `/panel/competitions`, `/panel/competitions/{id}/results/publish`, `/panel/payment-orders/reconciliation`.

### 24.4 Classes and methods

| Item | Convention | Example |
|---|---|---|
| Classes | PascalCase | `SignupService` |
| Interfaces | PascalCase, `Interface` suffix | `ReportInterface` |
| Methods | camelCase | `confirmSignup()` |
| Constants | UPPER_SNAKE | `MAX_IMAGES` |
| Namespaces | PSR-4-ish, matches folder | `App\Services` |
| Controllers | `{Domain}Controller` | `HorseController` |
| Services | `{Domain}Service` | `HorseService` |
| Report classes | `{Name}Report` | `RevenueReport` |
| Middleware | `{Name}Middleware` | `RoleMiddleware` |
| Exceptions | `{Name}Exception` | `ValidationException` |

### 24.5 Error codes

- UPPER_SNAKE with domain prefix.
- Domain prefixes: `AUTH_`, `USER_`, `SIGNUP_`, `PAYMENT_`, `SMS_`, `CAPTCHA_`, `VALIDATION_`, `RATE_`, `SERVER_`, `NOT_FOUND`, `FORBIDDEN`, `MAINTENANCE`.
- Examples: `AUTH_INVALID`, `USER_DISABLED_FULL`, `PAYMENT_ALREADY_VERIFIED`, `SMS_DISABLED`.

### 24.6 Config and env

- No `.env`.
- No `config/*.php`.
- All settings in `settings` table.

### 24.7 Files

- One class per file unless merged (P23).
- Merged files: `Models.php`, `Exceptions.php`, `Helpers.php`, `Middleware.php`, `Reports.php`.
- Templates: `kebab-case.php`.
- Schema files: `schema.sql`, `schema_logs.sql`.

### 24.8 Assets

- CSS: `kebab-case.css`.
- JS: `kebab-case.js`.
- Images: `kebab-case.{ext}`.
- Fonts: `family-name/weight.woff2`.

### 24.9 Audit action names

- Dot notation: `{entity}.{action}`.
- Examples: `user.create`, `user.update`, `user.disable`, `signup.confirm`, `payment.verify`, `horse.transfer.accept`.

---

## 25. Error Codes

Every error code raised by the panel, with HTTP status, Persian and English messages, and the trigger.

| Code | HTTP | Persian | English | Trigger |
|---|---|---|---|---|
| `AUTH_INVALID` | 401 | نام کاربری یا رمز عبور نادرست است | Invalid credentials | Login with wrong username/password |
| `AUTH_RATE_LIMITED` | 429 | تعداد تلاش‌های شما بیش از حد مجاز است | Too many attempts | Login rate limit exceeded |
| `AUTH_SESSION_EXPIRED` | 401 | نشست شما منقضی شده است | Session expired | Session missing or expired |
| `AUTH_CSRF_INVALID` | 403 | درخواست نامعتبر است | Invalid request | CSRF token mismatch |
| `AUTH_CAPTCHA_REQUIRED` | 422 | لطفا کد امنیتی را وارد کنید | Captcha required | Captcha missing |
| `CAPTCHA_INVALID` | 422 | کد امنیتی نادرست است | Invalid captcha | Captcha wrong or expired |
| `USER_DISABLED_FULL` | 403 | حساب شما مسدود شده است | Account banned | Login attempt on full-disabled user |
| `USER_DISABLED_LIMITED` | 403 | حساب شما محدود شده است | Account restricted | Write attempt on limited-disabled user |
| `USER_NOT_VERIFIED` | 403 | حساب شما هنوز تایید نشده است | Account not verified | Pending rider attempts restricted action |
| `USER_NOT_FOUND` | 404 | کاربر یافت نشد | User not found | Lookup by ID or username failed |
| `USER_PHONE_TAKEN` | 409 | این شماره قبلا ثبت شده است | Phone already registered | Signup with existing phone |
| `USER_USERNAME_TAKEN` | 409 | این نام کاربری قبلا ثبت شده است | Username already registered | Signup with existing username |
| `USER_NATIONAL_ID_INVALID` | 422 | کد ملی نامعتبر است | Invalid national ID | National ID check failed |
| `USER_PHONE_INVALID` | 422 | شماره موبایل نامعتبر است | Invalid phone | Phone validation failed |
| `USER_PASSWORD_WEAK` | 422 | رمز عبور ضعیف است | Weak password | Password policy failed |
| `CLUB_NOT_FOUND` | 404 | باشگاه یافت نشد | Club not found | Lookup failed |
| `CLUB_USER_TAKEN` | 409 | این حساب باشگاه قبلا استفاده شده است | Club user already linked | Duplicate club user |
| `CLUB_BANNED` | 403 | این باشگاه شما را تحریم کرده است | Club has banned you | Signup with banning club |
| `HORSE_NOT_FOUND` | 404 | اسب یافت نشد | Horse not found | Lookup failed |
| `HORSE_NOT_OWNED` | 403 | این اسب متعلق به شما نیست | Horse not owned | Access attempt on foreign horse |
| `HORSE_MICROCHIP_TAKEN` | 409 | این میکروچیپ قبلا ثبت شده است | Microchip already registered | Duplicate microchip |
| `HORSE_TRANSFER_LOCKED` | 423 | انتقال این اسب در حال انجام است | Horse transfer in progress | Signup on locked horse |
| `HORSE_NOT_ACTIVE` | 422 | این اسب غیرفعال است | Horse not active | Signup on inactive horse |
| `RADE_NOT_FOUND` | 404 | رده یافت نشد | Rade not found | Lookup failed |
| `RADE_SLUG_TAKEN` | 409 | این نامک قبلا استفاده شده است | Slug already taken | Duplicate rade slug |
| `PAYMENT_NOT_FOUND` | 404 | پرداخت یافت نشد | Payment not found | Lookup failed |
| `PAYMENT_SLUG_TAKEN` | 409 | این نامک قبلا استفاده شده است | Slug already taken | Duplicate payment slug |
| `PAYMENT_ALREADY_VERIFIED` | 409 | این پرداخت قبلا تایید شده است | Payment already verified | Double verify attempt |
| `PAYMENT_VERIFICATION_FAILED` | 422 | تایید پرداخت ناموفق بود | Payment verification failed | ZarinPal returned failure |
| `PAYMENT_GATEWAY_DISABLED` | 503 | درگاه پرداخت غیرفعال است | Payment gateway disabled | Settings disabled |
| `PAYMENT_MERCHANT_MISSING` | 500 | تنظیمات درگاه ناقص است | Gateway misconfigured | Missing merchant ID |
| `COMPETITION_NOT_FOUND` | 404 | مسابقه یافت نشد | Competition not found | Lookup failed |
| `COMPETITION_SLUG_TAKEN` | 409 | این نامک قبلا استفاده شده است | Slug already taken | Duplicate competition slug |
| `COMPETITION_NOT_OPEN` | 422 | ثبت‌نام این مسابقه باز نیست | Registration closed | Signup attempt on closed |
| `COMPETITION_REGISTRATION_PAUSED` | 422 | ثبت‌نام موقتا متوقف شده است | Registration paused | Signup attempt when paused |
| `COMPETITION_RADE_NOT_FOUND` | 404 | رده در این مسابقه یافت نشد | Competition-Rade not found | Lookup failed |
| `COMPETITION_RADE_FULL` | 422 | ظرفیت این رده تکمیل است | Rade full | Capacity reached |
| `COMPETITION_RADE_CAPACITY_BELOW_COUNT` | 422 | ظرفیت نمی‌تواند کمتر از تعداد ثبت‌نام‌ها باشد | Capacity below signup count | Capacity edit blocked |
| `SIGNUP_NOT_FOUND` | 404 | ثبت‌نام یافت نشد | Signup not found | Lookup failed |
| `SIGNUP_DUPLICATE` | 409 | این ثبت‌نام قبلا انجام شده است | Duplicate signup | Unique index violation |
| `SIGNUP_INVALID_STATE` | 422 | وضعیت ثبت‌نام اجازه این عملیات را نمی‌دهد | Invalid signup state | State transition blocked |
| `SIGNUP_RIDER_BANNED` | 403 | شما از شرکت در این مسابقه منع شده‌اید | Rider banned | Ban check failed |
| `SIGNUP_HORSE_BANNED` | 403 | این اسب از شرکت در این مسابقه منع شده است | Horse banned | Ban check failed |
| `TRANSFER_NOT_FOUND` | 404 | درخواست انتقال یافت نشد | Transfer not found | Lookup failed |
| `TRANSFER_CODE_INVALID` | 422 | کد انتقال نامعتبر است | Invalid transfer code | Wrong code |
| `TRANSFER_EXPIRED` | 422 | کد انتقال منقضی شده است | Transfer code expired | TTL exceeded |
| `SHARE_NOT_FOUND` | 404 | اشتراک یافت نشد | Share not found | Lookup failed |
| `SHARE_CODE_INVALID` | 422 | کد اشتراک نامعتبر است | Invalid share code | Wrong code |
| `SMS_DISABLED` | 403 | سرویس پیامک فعال نیست | SMS disabled | OTP request when disabled |
| `SMS_SEND_FAILED` | 502 | ارسال پیامک ناموفق بود | SMS send failed | Provider error |
| `OTP_INVALID` | 422 | کد وارد شده نادرست است | Invalid OTP | Wrong code |
| `OTP_EXPIRED` | 422 | کد وارد شده منقضی شده است | OTP expired | TTL exceeded |
| `OTP_MAX_ATTEMPTS` | 429 | تعداد تلاش‌ها بیش از حد مجاز است | OTP attempts exceeded | Max attempts hit |
| `OTP_RATE_LIMITED` | 429 | تعداد درخواست‌ها بیش از حد مجاز است | OTP rate limited | Rate limit hit |
| `VALIDATION_FAILED` | 422 | لطفا خطاهای فرم را برطرف کنید | Validation failed | Any field error |
| `NOT_FOUND` | 404 | موردی یافت نشد | Not found | Generic 404 |
| `FORBIDDEN` | 403 | شما به این بخش دسترسی ندارید | Forbidden | Role check failed |
| `RATE_LIMITED` | 429 | تعداد درخواست‌های شما بیش از حد مجاز است | Rate limited | Generic rate limit |
| `MAINTENANCE` | 423 | سیستم در حال تعمیر است | Under maintenance | Maintenance mode |
| `SERVER_ERROR` | 500 | خطای سرور. لطفا بعدا تلاش کنید | Server error | Uncaught exception |
| `SERVICE_UNAVAILABLE` | 503 | سرویس در دسترس نیست | Service unavailable | Dependency failure |
| `BACKUP_FAILED` | 500 | پشتیبان‌گیری ناموفق بود | Backup failed | File/disk error |
| `RESTORE_FAILED` | 500 | بازیابی ناموفق بود | Restore failed | File/disk error |
| `IMPORT_FAILED` | 422 | ورود داده‌ها ناموفق بود | Import failed | CSV parse error |
| `EXPORT_FAILED` | 500 | خروجی گرفتن ناموفق بود | Export failed | Disk/permission error |

---

## 26. Implementation Checklist

Linear task list for the implementer. Each item links to the section(s) that define it.

### 26.1 Bootstrap

- [ ] `public/index.php` — single front controller (§5)
- [ ] `app/Bootstrap/App.php` — Container, Router, Request, Response, Envelope, Database, LogDatabase (§2 P14–P15)
- [ ] `vendor/autoload.php` — PSR-4-ish autoloader

### 26.2 Schema

- [ ] `database/schema.sql` — main DB DDL with per-column comments (§7.1)
- [ ] `database/schema_logs.sql` — logs DB DDL (§7.2)
- [ ] `database/upgrades/` — placeholder folder

### 26.3 Auth

- [ ] `AuthService` — password, OTP, sessions, rate limiting, captcha (§10.1, §23)
- [ ] `AuthController` — login, signup, forgot, captcha, logout (§10.1)
- [ ] Session table + middleware (§2 P08)
- [ ] Captcha generation
- [ ] OTP flow (only when SMS enabled)

### 26.4 Culture & Settings

- [ ] `cultures/fa-IR.json` — Iran-Tehran specific (§2 P25)
- [ ] `cultures/en-US.json`
- [ ] `CultureService` — resolve, translate, format
- [ ] `SettingService` — registry, cache, invalidation
- [ ] `CalendarService` — wrapped by `CultureService` for Jalali

### 26.5 Middleware

- [ ] `Middleware.php` — all 9 middleware classes (§11)
- [ ] `Kernel.php` — pipeline orchestrator

### 26.6 Users

- [ ] `UserService` — CRUD, verify, reject, disable, impersonate (§4, §12)
- [ ] `UserController` — unified users module (§10.3)
- [ ] Verification flow (§8.2.7)
- [ ] Disable states (§4.2)
- [ ] Session revocation

### 26.7 Clubs

- [ ] `ClubService` — CRUD, bans (§4.3, §8.2.5)
- [ ] `ClubController`
- [ ] Club user account creation

### 26.8 Horses

- [ ] `HorseService` — CRUD, images, shares, transfers, import/export (§8.2.2, §8.2.3)
- [ ] `HorseController`
- [ ] CSV import/export with header-row mapping
- [ ] Soft delete + sold-to-non-rider status

### 26.9 Rades

- [ ] `RadeService` — CRUD
- [ ] `RadeController`

### 26.10 Payments

- [ ] `PaymentService` — templates CRUD, orders, ZarinPal gateway (§14.1)
- [ ] `PaymentController`
- [ ] ZarinPal request + verify + callback

### 26.11 Competitions

- [ ] `CompetitionService` — CRUD, Rades, pause/resume, clone (§8.2.1)
- [ ] `CompetitionController`
- [ ] Competition-Rade binding with Payment + capacity + auto-confirm

### 26.12 Signups

- [ ] `SignupService` — create, confirm, reject, position, withdraw (§8.2.1)
- [ ] `SignupController`
- [ ] Rider signup flow
- [ ] Price snapshot on creation

### 26.13 Results

- [ ] `ResultService` — draft, confirm, publish, reopen (§8.2.4)
- [ ] `ResultController`

### 26.14 Reports

- [ ] `ReportEngine` — registry, runner, query builder, share (§13)
- [ ] `KpiService` — dashboards and summary tiles
- [ ] `Reports.php` — all report classes
- [ ] Signed download URLs
- [ ] Grid state persistence

### 26.15 Notifications & Messages

- [ ] `NotificationService` — create, read, broadcast (§18)
- [ ] `NotificationController`
- [ ] Broadcast message delivery

### 26.16 SMS & OTP

- [ ] `SmsService` — MelyPayamak client (§14.2)
- [ ] OTP generation, hashing, verification
- [ ] Notification hooks

### 26.17 Audit & Changelog

- [ ] `LogService` — logger, audit, changelog (§20.2)
- [ ] Audit viewer (Admin only)

### 26.18 Backup / Restore / Reset / Demo

- [ ] `BackupService` — backup, restore, reset (§21)
- [ ] `DemoSeeder` — rich idempotent seeder (§16)

### 26.19 Print & QR

- [ ] Print templates (§17)
- [ ] `PrintController`
- [ ] QR generation (client + server)

### 26.20 Frontend polish

- [ ] Tailwind CSS build (committed)
- [ ] Alpine.js components
- [ ] AG Grid wrapper
- [ ] SheetJS exports
- [ ] Inline help tooltips

### 26.21 Installer

- [ ] `/install` wizard (§22)
- [ ] Requirements check
- [ ] Schema application
- [ ] Reference seed
- [ ] First Admin creation

### 26.22 Documentation

- [ ] `README.md` (root)
- [ ] `docs/README.md`
- [ ] Schema comments
- [ ] Settings registry comments
- [ ] Report class comments
- [ ] Controller method comments
- [ ] Service method comments

---

## 27. Risk Register & Prerequisites

### 27.1 Client prerequisites (before deployment)

- [ ] **Domain and subdomain DNS.** `panel.gorganhorse.ir` must resolve to the hosting server.
- [ ] **HTTPS certificate.** Let's Encrypt or commercial.
- [ ] **Hosting.** PHP 8.1+ (8.4 recommended), SQLite 3, ~5 GB storage, OPcache enabled.
- [ ] **ZarinPal merchant ID** (production). Test/sandbox is not used at runtime.
- [ ] **MelyPayamak credentials** (optional). Only needed if SMS is enabled.
- [ ] **Backup storage.** External disk or cloud folder for periodic backup downloads.
- [ ] **Administrator identity.** First Admin's username, phone, and password.
- [ ] **Brand assets.** Logo, favicon, brand colors (optional; defaults apply).
- [ ] **Approval to host outside WordPress.** Confirms panel/subdomain split.

### 27.2 Operational risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| SQLite write contention at peak | Low | Medium | WAL mode; busy timeout; documented threshold (~50 concurrent writers) |
| SMS provider outage | Medium | Low | SMS is optional; panel notifications still deliver |
| ZarinPal outage | Low | High | Callbacks idempotent; manual verify button; audit trail |
| Disk full | Low | High | Retention rules; admin alert on low disk; backups off-site |
| Backup corruption | Low | High | Pre-restore safety snapshot; VACUUM INTO for consistency |
| Credential leak (ZarinPal / SMS) | Low | High | Secrets stored in settings (DB); restrict panel access; rotate on suspicion |
| Admin account loss | Low | High | Restore from backup; installer supports first-Admin reset via file-based recovery |
| Session hijack | Low | Medium | UA binding; IP warning; force-logout; 90-day cap |
| Race condition on capacity | Low | Medium | Capacity check inside transaction; unique index on signup |
| Double payment callback | Low | Low | Lock per authority; idempotent verify |
| Rider signup abuse | Medium | Low | Rate limiting on signup; verification queue |
| CSV import malformed | Medium | Low | Header-row mapping; per-row error report; transaction rollback |
| Browser compatibility | Low | Low | Modern browsers only; fallbacks documented |

### 27.3 Out-of-scope risks (explicitly not addressed)

- WordPress site security (separate system).
- Email deliverability (dropped).
- Multi-tenant scaling (single-tenant).
- Mobile native apps.
- Offline mode.

---

## 28. Deployment & Portability

- Copy-paste the entire project folder. Nothing else needed.
- Exclude `cache/*` and `database/*.sqlite` from transfer if starting fresh.
- Apache works out-of-box with `.htaccess`. Nginx: ship `docs/nginx.conf.sample`.
- Domains change freely — no hostnames stored.
- PHP 8.1+ (8.4 supported).
- OPcache recommended.

---

## 29. Closing Notes

- **This is the master blueprint.** Every architectural decision here is final.
- **The Technical document** specifies how: stack, implementation rules, auth internals, validations.
- **The User Usage document** specifies interactions: flows, KPIs per role, frontend build.
- **The Project Proposal document** is the client-facing summary.
- **No versioning. No phasing.** This is the production-ready final version.
- **Documentation is mandatory** at every level (P01, P30).
- **Seed data is rich** and represents a full year of agency operation (§16).
