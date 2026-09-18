# Backend Blueprint — Gorgan Horse Federation Panel

**Version:** Final (no versioning, no phasing — this is the production-ready specification)
**Document type:** Master Blueprint
**Companion documents:** Technical, User Usage, Proposal

---

## Table of Contents

1. Overview
2. Principles
3. Scope
4. Roles & Permissions
5. Folder Structure
6. Database Schema
7. Domain Model & Flows
8. HTTP Envelope
9. Routes
10. Middleware Pipeline
11. Authorization Matrix
12. Reports & KPIs
13. Integrations
14. Settings Registry
15. Seed Data Principles
16. Printing & QR
17. Notifications & Messaging
18. File Uploads & Media
19. Caching & Logging
20. Backup / Restore / Reset / Demo
21. Installer
22. Security
23. Deployment & Portability

---

## 1. Overview

The Gorgan Horse Federation Panel is a **monolithic PHP 8.x web panel** for managing horse-riding competitions in Golestan province, Iran. It is a **single-host, no-framework, RTL-first, fa-IR-first, Shamsi-aware** application that serves the federation's internal operations: clubs, riders, horses, rades, competitions, signups, payments, results, and reporting.

The panel is hosted on a **subdomain** (e.g. `panel.gorganhorse.ir`). The public-facing site (landing pages, blog, SEO) is handled separately by WordPress on the main domain. There is **no session sharing** between the two systems.

The panel is **authenticated-only** for all users (Admin, Manager, Rider, Club). The only public endpoints are the ZarinPal payment callbacks.

### 1.1 Audience

- **Implementers** — this document is the contract. Every architectural decision here is final.
- **Owners** — read the Proposal document for the executive summary.
- **Reviewers** — read Principles + Technical documents.

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
- Reference principles in §15 for volume, variety, and validity.

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

**Club** — manages own profile. Views affiliated riders, competitions at own venue, own bans. Full report access scoped to own club.

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

Merged structure. Target ~40 PHP files in `app/`.

```
/
├── .htaccess                          # → /public
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
│       │   ├── app.js                 # Alpine bootstrap, helpers, tooltips
│       │   ├── grid.js                # AG Grid wrapper, state persistence
│       │   ├── qr.js                  # QR generator
│       │   └── vendor/
│       ├── fonts/
│       └── img/
├── app/
│   ├── Bootstrap/
│   │   └── App.php                    # Container, Router, Request, Response, Envelope, Database, LogDatabase
│   ├── Http/
│   │   ├── Kernel.php
│   │   ├── Middleware.php             # all middleware classes
│   │   ├── routes.php
│   │   └── Controllers/
│   │       ├── AuthController.php     # auth, login, signup, captcha, OTP, logout
│   │       ├── DashboardController.php
│   │       ├── UserController.php     # users, profile, sessions, impersonation
│   │       ├── ClubController.php     # clubs, bans
│   │       ├── HorseController.php    # horses, images, shares, transfers, import/export
│   │       ├── RadeController.php
│   │       ├── PaymentController.php  # templates, orders, callbacks
│   │       ├── CompetitionController.php  # competitions + competition_rades
│   │       ├── SignupController.php   # signups + rider signup flow
│   │       ├── ResultController.php
│   │       ├── ReportController.php   # reports + shares
│   │       ├── NotificationController.php  # notifications + messages
│   │       ├── SettingsController.php  # settings + sms + payment config
│   │       ├── AdminController.php    # backups, maintenance, audit
│   │       └── PrintController.php    # print + qr
│   ├── Services/
│   │   ├── AuthService.php            # auth, sessions, rate limiting, captcha, OTP
│   │   ├── UserService.php            # users, rider profile, impersonation
│   │   ├── ClubService.php
│   │   ├── HorseService.php           # horses, transfers, shares, images
│   │   ├── RadeService.php
│   │   ├── PaymentService.php         # templates, orders, ZarinPal gateway
│   │   ├── CompetitionService.php     # competitions, rades
│   │   ├── SignupService.php
│   │   ├── ResultService.php
│   │   ├── BanService.php             # club bans + admin/manager bans
│   │   ├── NotificationService.php    # notifications + broadcast messages
│   │   ├── SmsService.php             # MelyPayamak + OTP
│   │   ├── CultureService.php         # culture, translation, calendar
│   │   ├── SettingService.php
│   │   ├── CacheService.php
│   │   ├── LogService.php             # logger, audit, changelog
│   │   ├── MediaService.php           # upload, image processing
│   │   ├── Report/
│   │   │   ├── ReportEngine.php       # Registry + Runner + QueryBuilder + Share
│   │   │   ├── KpiService.php
│   │   │   └── Reports.php            # all report classes in one file
│   │   └── Admin/
│   │       ├── BackupService.php      # backup, restore, reset
│   │       └── DemoSeeder.php
│   ├── Support/
│   │   └── Helpers.php                # Str, Arr, Hash, Uuid, Clock, Path, Validator, Sanitizer, NationalId, PersianDigits
│   ├── Exceptions/
│   │   └── Exceptions.php             # all exception classes + Handler
│   ├── Models/
│   │   └── Models.php                 # all model classes in one file
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
│   ├── app.sqlite                     # runtime, gitignored
│   ├── logs.sqlite                    # runtime, gitignored
│   ├── schema.sql                     # main schema
│   ├── schema_logs.sql                # logs schema
│   ├── upgrades/
│   └── seeds/
│       ├── demo.php                   # rich demo data (see §15)
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
├── storage/
│   ├── shares/
│   └── tmp/
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
├── docs/
│   ├── BLUEPRINT.md
│   ├── TECHNICAL.md
│   ├── USER-USAGE.md
│   ├── PROPOSAL.md
│   └── nginx.conf.sample
└── README.md
```

---

## 6. Database Schema

The main DB (`app.sqlite`) holds operational data. The logs DB (`logs.sqlite`) holds audit, app logs, login attempts, SMS logs, OTP codes, and changelog.

Both databases are initialized from `schema.sql` and `schema_logs.sql` respectively. Foreign keys are enabled in both. WAL mode is used. Busy timeout is 5 seconds.

### 6.1 Main DB tables

| Table | Purpose |
|---|---|
| `users` | All accounts (Admin, Manager, Rider, Club) |
| `rider_profiles` | Rider-specific metadata (national ID, insurance, avatar, age category) |
| `sessions` | DB-backed sessions |
| `clubs` | Club profiles + linked club user account |
| `club_bans` | Clubs banning riders/horses from affiliation |
| `rider_bans` | Admin/Manager bans (global, competition, rade) |
| `horse_races` | Controlled vocabulary — horse races |
| `horse_colors` | Controlled vocabulary — horse colors |
| `horse_genders` | Controlled vocabulary — genders |
| `horses` | Horse records |
| `horse_images` | Horse gallery (max 5 per horse) |
| `horse_transfers` | Transfer requests between owners |
| `horse_shares` | Owner shares horse to specific rider |
| `rades` | Reusable class definitions |
| `payments` | Reusable price templates |
| `competitions` | Competitions |
| `competition_rades` | Bind Rade + Payment + capacity + auto-confirm |
| `signups` | Rider × Horse × Competition-Rade |
| `payment_orders` | ZarinPal order lifecycle |
| `notifications` | In-panel notifications per user |
| `messages` | Broadcast messages |
| `message_recipients` | Broadcast message recipients |
| `report_shares` | In-panel report shares |
| `media` | Uploaded files |
| `settings` | Rich settings registry |
| `cultures` | Culture list |
| `rate_limits` | Generic rate limiting |
| `password_resets` | Manager-triggered reset tokens |
| `api_tokens` | Reserved for future API access |

### 6.2 Logs DB tables

| Table | Purpose |
|---|---|
| `audit_logs` | Audit trail of all writes |
| `app_logs` | Application errors, warnings, slow queries |
| `login_attempts` | Failed/blocked login attempts |
| `sms_logs` | Outbound SMS records |
| `otp_codes` | OTP codes |
| `changelog` | Manager action summary for Admin |

### 6.3 Schema files

- `database/schema.sql` — main DB DDL. Every table has a header comment.
- `database/schema_logs.sql` — logs DB DDL.

**Every column is documented** in the schema file with a one-line comment. See `schema.sql` for full DDL; the DDL is not reproduced in this document.

---

## 7. Domain Model & Flows

### 7.1 Core entities

- **Club** — profile + linked user account. Can be a venue (host) and/or an affiliation (rider's chosen club).
- **Horse** — owned by exactly one Rider. Can be shared to other Riders. Can be transferred.
- **Rade** — a class definition (e.g. رده E, رده D1). Reusable across competitions.
- **Payment** — a reusable price template. Bound to Competition-Rades.
- **Competition** — an event at a venue, with a registration window and a date.
- **Competition-Rade** — a Rade offered at a Competition, with a Payment, capacity, auto-confirm flag, barrage flag, and signup mode.
- **Signup** — a Rider entering a Horse into a Competition-Rade.
- **Payment Order** — the ZarinPal transaction lifecycle for a signup.

### 7.2 Key flows

#### 7.2.1 Rider signup flow

1. Rider logs in (username/password or phone/OTP if SMS enabled).
2. Rider opens "Competitions" page → sees open competitions.
3. Rider opens a competition → sees its Rades.
4. Rider picks a Rade → picks a Horse (own or shared to them) → picks an affiliation Club → confirms.
5. System creates a `signup` row (`pending_payment`) + a `payment_order` row (`pending`) + snapshots the Payment data into the signup.
6. System calls ZarinPal request → gets `authority` → saves it → redirects to ZarinPal.
7. Rider pays → ZarinPal redirects to `/payment/callback`.
8. Callback verifies → order becomes `paid` → signup becomes `paid` → if `auto_confirm=1`, signup becomes `confirmed`; else `paid` awaits Manager confirmation.
9. Rider gets a panel notification (and SMS if enabled).

#### 7.2.2 Horse transfer flow

1. Current owner opens Horse detail → clicks "Initiate Transfer" → system locks the horse (no new signups allowed), resets the share code.
2. System generates a transfer code → owner shares it with the buyer.
3. Buyer enters the code → sees general owner info (safe data only) → submits.
4. Owner sees the buyer's general info → accepts or rejects.
5. On accept: `horse.owner_user_id` changes; transfer row is marked `completed`; horse is unlocked.
6. On reject: transfer row is marked `rejected`; the code remains valid; buyer can retry.
7. Historical signups remain attached to the horse.

#### 7.2.3 Horse share flow

1. Owner opens Horse detail → clicks "Share" → enters the receiving Rider's 6-digit share code (or selects them by username) → system creates a `horse_share` row.
2. The receiving Rider sees the horse in their signup Horse picker.
3. The displayed name clearly indicates "Shared by [owner nickname]".

#### 7.2.4 Results flow

1. Manager opens Competition → "Results" tab → entry grid.
2. Manager fills `position`, `is_winner`, `result_notes` per signup, and `had_barrage` + `barrage_notes` per Competition-Rade.
3. Manager clicks "Save Draft" → `results_status = draft`.
4. Manager clicks "Confirm Results" → `results_status = confirmed`.
5. Manager clicks "Publish Results" → `results_status = published` → riders are notified (panel + SMS if enabled).
6. After publish: only Admin can reopen (`reopen` → back to `confirmed`).

#### 7.2.5 Ban flow

1. **Club ban** — Club account opens its own Ban page → adds a Rider or Horse → forward-looking only.
2. **Admin/Manager ban** — Manager opens User detail → "Ban" → selects scope (global, competition, rade) → saves. Priority: Rider > Competition > Rade.

#### 7.2.6 Disable flow

1. Manager/Admin opens User detail → "Disable" → selects `limited` or `full`.
2. `limited`: user can log in but cannot create records. Banner displayed.
3. `full`: user cannot log in. Login attempt shows a "banned" banner.

#### 7.2.7 Verification flow

1. Rider signs up → `verification_status = pending`, `auto_verify_at = now + 48h`.
2. Pending rider **can log in**, edit profile, sees a banner. Cannot add horses. Cannot sign up.
3. Manager verifies → `verification_status = verified`.
4. Or Manager rejects → user + horses + uploads are hard-deleted; signups are anonymized; payment orders kept.
5. If no action for 48h → system auto-verifies on next request touching that user.

#### 7.2.8 Competition cancellation

1. Manager opens Competition → "Cancel" → confirms.
2. All payment orders for that competition → `pending_refund`.
3. Manager marks each as `refunded` after manual refund. Can toggle back.

---

## 8. HTTP Envelope

Every JSON response:

```json
{
  "ok": true,
  "data": { },
  "meta": {
    "page": 1, "per_page": 50, "total": 1240, "filtered": 312,
    "culture": "fa-IR", "direction": "rtl", "timezone": "Asia/Tehran",
    "server_time_utc": "2025-01-01T10:00:00Z",
    "impersonating": false
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
  "meta": { },
  "csrf": "token"
}
```

HTTP statuses: 200, 201, 204, 302 (HTML), 400, 401, 403, 404, 409, 422, 423, 429, 500, 503.

---

## 9. Routes

### 9.1 Auth (guest)

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

### 9.2 Payment callbacks (public)

| Method | Path | Purpose |
|---|---|---|
| GET | `/payment/callback` | ZarinPal callback |
| GET | `/payment/success` | Success page |
| GET | `/payment/failed` | Failure page |

### 9.3 Panel (auth)

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

## 10. Middleware Pipeline

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

## 11. Authorization Matrix

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

## 12. Reports & KPIs

### 12.1 Unified report engine

One page: `/panel/reports`. Rich filters, rich columns, sidebar presets. AG Grid with column toggling, drag-and-drop reorder, sort, filter, pagination (default page 1). Grid state (columns, order, filters) persists in `localStorage` per user. Page number always resets to 1.

**Report types** (single file, one class per report):
- `signups`
- `revenue`
- `results`
- `horses`
- `riders`
- `clubs`
- `payments`
- `bans`

Each report declares its own columns, filters, sort keys, and aggregations.

### 12.2 Sidebar presets

The sidebar pre-fills filter state via query params (e.g. "Signups — this competition" → `report=signups&competition_id=X`).

### 12.3 Dashboards & KPIs

**Admin dashboard**
- Total riders, horses, clubs, competitions
- Revenue this month, this week, today
- Pending verifications, confirmations, refunds
- Active sessions
- Signups over time (30d), revenue over time (30d), Rade popularity (bar)
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

### 12.4 KPI definitions

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

## 13. Integrations

### 13.1 ZarinPal (production only)

- **Request:** `POST https://payment.zarinpal.com/pg/v4/payment/request.json`
- **Verify:** `POST https://payment.zarinpal.com/pg/v4/payment/verify.json`
- **Currency:** IRT
- **Callback:** `GET /payment/callback?Authority=X&Status=OK`
- **Idempotency:** lock per authority prevents double verification.
- **Refunds:** manual only; panel action marks `pending_refund` / `refunded`.

### 13.2 MelyPayamak (SMS)

- **Disabled by default.**
- Admin enables via `/panel/settings/sms`.
- **OTP:** 5-digit codes, 2-minute TTL, 3 attempts max, 5 requests per phone per hour, 10 per IP per hour.
- **Notifications:** signup, payment, confirmation, results, transfer, ban.
- **Fallback:** if SMS is disabled, OTP endpoints return 403 `SMS_DISABLED`.

### 13.3 Email

- **Completely dropped.**
- No PHPMailer, no SMTP settings, no email templates.
- Password reset: Admin/Manager sets a new password manually.

---

## 14. Settings Registry

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
`ui.theme` (`light`), `ui.font_primary` (`Vazirmatn`), `ui.panel_path` (`panel`), `ui.default_avatar_admin_text` (`A`), `ui.default_avatar_manager_text` (`M`)

---

## 15. Seed Data Principles

Seed data is **not included in this blueprint** as fixed records. Instead, the seeder must satisfy the following principles and volume.

### 15.1 Volume (mandatory minimums)

- **Clubs:** 4 active clubs
- **Rades:** 6 rade definitions (آزاد، رده E، رده D، رده D1، رده تمرینی، رده مبتدی)
- **Payments:** 5 payment templates
- **Riders:** 60 riders, split across experience levels (30 active, 20 occasional, 10 inactive)
- **Horses:** 90 horses, distributed across riders (1–4 per rider), with valid microchips, valid races, valid colors, and correct ownership history
- **Competitions:** 52 competitions, one per week over 12 months (2025-01 to 2025-12), each with 3–6 Rades
- **Signups:** ~1,800 signups, distributed across competitions and Rades (5–20 per Rade)
- **Payment orders:** ~1,600 paid, ~150 pending, ~50 refunded
- **Results:** published for all competitions older than 30 days, draft for the last 4
- **Transfers:** 8–12 horse transfers over the year
- **Shares:** 15–20 active horse shares
- **Bans:** 3 club bans, 5 admin bans
- **Notifications:** generated as side effects (not explicitly seeded)
- **Messages:** 6 broadcast messages

### 15.2 Validity rules

- All dates must be in the past.
- All Shamsi conversions must be accurate.
- All phone numbers must be valid Iranian +98 numbers.
- All national IDs must pass the Iranian check-digit algorithm.
- All microchips must be 15-digit and unique.
- All competition dates must be in the past or future as appropriate.
- All payment amounts must be in IRT.
- All results must be plausible (winner exists, positions unique per Rade).
- All horses must belong to their owners.

### 15.3 Variety rules

- Iranian first names, last names, horse names, club names.
- Iranian cities (Gorgan, Tehran, Mashhad, etc.).
- Iranian clubs (باشگاه هیرکان، باشگاه شکوه طبیعت، باشگاه سزار).
- Horse colors, races, and genders from the controlled vocabularies.
- Rades with realistic distributions (آزاد and رده E most popular).
- Some competitions with barrage (`had_barrage=1`), most without.
- Some riders with multiple horses, some with one.
- Some horses with multiple signups in different Rades across different competitions.
- Some payment orders marked `refunded`.
- Some clubs with bans.

### 15.4 Seed structure

The seeder must be **idempotent**. Re-running it never duplicates records. All seeded rows carry `is_demo = 1` so they can be cleared in bulk.

### 15.5 Cleared by demo clear

"Clear demo" removes only rows where `is_demo = 1`, and their associated files under `uploads/demo/`.

---

## 16. Printing & QR

### 16.1 Printable entities

- Competition (single + list)
- Horse (single + list)
- Rider (single)
- Club (single + list)
- Payment (single + list + selected-as-individuals)
- Signup sheet (per competition)
- Standings (per rider, per horse, per rider-horse pair)

### 16.2 Print styles

- A4, portrait.
- Print header shows brand + entity title + Shamsi date.
- Print footer shows page number + federation name.
- Table layouts use `print.css`.
- No JS needed at print time; server-rendered HTML.

### 16.3 QR codes

- QR encodes the current panel URL + query state.
- Authed panel users scanning it see the same view.
- Generated on-the-fly via `qr.js` (client-side) or `qr.php` (server-side, cached).
- Available on: competition paper, signup sheet, report page, entity page header.

---

## 17. Notifications & Messaging

### 17.1 Panel notifications

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

### 17.2 Broadcast messages

- Managers/Admins can create broadcasts to: all riders, all riders of a competition, all riders of a competition-rade, selected users.
- Delivered in-panel and (if SMS enabled) via SMS.
- Read receipts tracked per recipient.

---

## 18. File Uploads & Media

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

## 19. Caching & Logging

### 19.1 Cache

- File-based under `cache/`.
- Namespaces: `settings`, `cultures`, `thumbs`, `reports`.
- TTL per namespace (settings-configurable).
- Admin "Clear Cache" clears all.

### 19.2 Logs

- **App logs** (`logs.sqlite.app_logs`) — errors, warnings, slow queries (>200 ms).
- **Audit logs** (`logs.sqlite.audit_logs`) — every write (create/update/delete) with actor, target, diff.
- **Changelog** (`logs.sqlite.changelog`) — summary of Manager actions for Admin.
- **Login attempts** (`logs.sqlite.login_attempts`) — only failures and blocks.
- **SMS logs** (`logs.sqlite.sms_logs`).
- **OTP codes** (`logs.sqlite.otp_codes`) — hashed.
- File-based JSON-lines rotation under `logs/app/YYYY-MM/YYYY-MM-DD.log` and `logs/audit/YYYY-MM/YYYY-MM-DD.log` for raw retention.
- Retention: app 30d, audit 180d, SMS 90d, login attempts 30d. Payment orders retained forever (main DB).

---

## 20. Backup / Restore / Reset / Demo

### 20.1 Backup

- Admin UI trigger.
- Zip: `database/app.sqlite`, `database/logs.sqlite`, `uploads/`, `storage/shares/` (optional).
- DB copy via `VACUUM INTO`.
- Naming: `backup-YYYY-MM-DD_HHMMSS-{suffix}.zip`.
- Storage: `backups/`.

### 20.2 Restore

1. Enter maintenance.
2. Pre-restore safety snapshot.
3. Replace DB + uploads.
4. Re-inject pre-restore app key.
5. Upsert current admin.
6. Revoke all sessions except current admin's.
7. Clear cache.
8. Exit maintenance.
9. Audit entry.

### 20.3 Reset

- Wipes DB data (except settings + current admin).
- Wipes uploads/.
- Re-injects current admin + session.
- Clears cache.
- Audit entry.

### 20.4 Demo

- Seeder is idempotent, rich (see §15).
- All rows tagged `is_demo = 1`.
- "Clear demo" removes only demo rows + demo media.

---

## 21. Installer

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

## 22. Security

- CSRF: per-session token, required on all state-changing requests.
- XSS: output escaping by default (`e()` helper); HTMLPurifier for rich HTML.
- SQLi: prepared statements; whitelist-driven report queries.
- Sessions: HttpOnly, SameSite=Lax, Secure, rotation on privilege change, UA binding.
- Rate limiting: login, signup, OTP, share unlock.
- Captcha: server-generated, signed, single-use.
- Headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP (configurable), HSTS if HTTPS.
- Uploads: MIME sniffing, UUID rename, no execution.
- Sensitive dirs protected via `.htaccess` (Apache) + Nginx sample.
- Password hashing: `PASSWORD_DEFAULT`.
- Random: `random_bytes`.
- Installer self-locks.

---

## 23. Deployment & Portability

- Copy-paste the entire project folder. Nothing else needed.
- Exclude `cache/*` and `database/*.sqlite` from transfer if starting fresh.
- Apache works out-of-box with `.htaccess`. Nginx: ship `docs/nginx.conf.sample`.
- Domains change freely — no hostnames stored.
- PHP 8.1+ (8.4 supported).
- OPcache recommended.
