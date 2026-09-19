# Technical Specification — Gorgan Horse Federation Panel

**Document type:** Technical Implementation Specification
**Companion documents:** Blueprint, User Usage, Proposal
**Status:** Final, production-ready, no phasing, no versioning

---

## Table of Contents

1. Purpose & Audience
2. Tech Stack
3. Runtime Requirements
4. Bootstrapping & Lifecycle
5. Routing & HTTP Layer
6. Envelope & Response Rules
7. Database Layer
8. Models Layer
9. Services Layer
10. Controllers Layer
11. Authentication
12. Authorization
13. Middleware Pipeline
14. CSRF
15. Culture, Calendar & Numbers
16. Validation Rules
17. Business Rules & Invariants
18. Report Engine
19. Payment Integration (ZarinPal)
20. SMS Integration (MelyPayamak)
21. File Uploads & Media
22. Caching
23. Logging
24. Backup / Restore / Reset
25. Installer
26. Frontend Architecture
27. Print & QR
28. Error Handling
29. Security Implementation
30. Performance
31. Code Style & Standards
32. Documentation Standard
33. Third-Party Libraries
34. Deployment

---

## 1. Purpose & Audience

This document specifies **how** the Gorgan Horse Federation Panel is implemented. It complements the Blueprint (which specifies **what**). Every rule here is mandatory.

Audience: implementers, reviewers, maintainers.

---

## 2. Tech Stack

### 2.1 Backend

| Layer | Choice | Notes |
|---|---|---|
| Language | PHP 8.1+ (8.4 supported) | Strict types on |
| Framework | None | Custom bootstrap, tiny DI container |
| Database | SQLite 3 (WAL) | Two files: `app.sqlite`, `logs.sqlite` |
| DB access | PDO | Prepared statements only |
| Package manager | None | Vendored libraries only |
| Autoloader | Custom PSR-4-ish | `vendor/autoload.php` |
| Templating | Plain PHP | No engine |
| Migrations | Manual SQL files + upgrades folder | No framework |
| CLI | None | All operations via UI |

### 2.2 Frontend

| Layer | Choice | Notes |
|---|---|---|
| Base | Server-rendered PHP templates | RTL-first |
| Interactivity | Alpine.js 3 (vendored) | ~15 KB |
| CSS | Tailwind CSS (built once, committed) | Logical properties |
| Grid | AG Grid Community (vendored) | Server-side row model contract |
| Exports | SheetJS (vendored) | XLSX + CSV |
| QR | qrcode.js (vendored) | Client-side generation |
| Fonts | Vazirmatn (self-hosted) | Persian |
| Icons | Inline SVG | No icon font |

**No build step required at runtime.** Tailwind is built during development and the output is committed. If the developer wishes to rebuild, `npx tailwindcss` is used locally only.

### 2.3 Vendored libraries (PHP)

- `morilog/jalali` — Jalali ↔ Gregorian conversion
- `giggsey/libphonenumber-for-php` — phone validation
- `ezyang/htmlpurifier` — HTML sanitization
- `intervention/image` — image resize/compress/convert (GD or Imagick)
- `erusev/parsedown` — markdown → HTML for admin-authored help text

---

## 3. Runtime Requirements

- PHP 8.1+ (8.4 supported)
- Extensions: `pdo_sqlite`, `sqlite3`, `mbstring`, `json`, `openssl`, `fileinfo`, `gd` or `imagick`, `zip`, `intl`, `curl`, `dom`
- OPcache recommended
- Writable dirs: `database/`, `uploads/`, `cache/`, `logs/`, `storage/`, `backups/`
- No cron, no queue, no daemon
- Recommended PHP settings: `memory_limit=256M`, `max_execution_time=60`, `upload_max_filesize=25M`, `post_max_size=30M`

---

## 4. Bootstrapping & Lifecycle

### 4.1 Single entry point

Every request enters via `public/index.php`. The flow:

1. Define `BASE_PATH` constant.
2. Require `vendor/autoload.php`.
3. Require `app/Bootstrap/App.php`.
4. Instantiate the `Container`.
5. Bind core services (Database, LogDatabase, Request, Response, Envelope, Router, Setting, Culture, Logger, Audit, Cache).
6. Load settings (cached).
7. Resolve culture.
8. Build middleware pipeline.
9. Match route.
10. Execute controller action.
11. Emit response (JSON envelope for API routes, HTML for panel routes).

### 4.2 Container

A **tiny PSR-11-like** container. Bindings are strings → closures or singleton instances. Auto-resolution is not supported; every dependency is explicit.

```php
$c->bind('db', fn() => new Database(...));
$c->singleton('settings', fn($c) => new SettingService($c->get('db'), $c->get('cache')));
```

### 4.3 File merging policy

To keep the file count low (Principle P23):

- **Models** — all in `app/Models/Models.php` (one file, one namespace, many classes).
- **Exceptions** — all in `app/Exceptions/Exceptions.php`.
- **Support helpers** — all in `app/Support/Helpers.php`.
- **Middleware** — all in `app/Http/Middleware.php`.
- **Reports** — all report classes in `app/Services/Report/Reports.php`.
- **Controllers** — one file per domain, not per action.
- **Services** — one file per domain.

The merged file must still respect **PSR-4-ish namespace-to-folder mapping** (folder level, not file level).

---

## 5. Routing & HTTP Layer

### 5.1 Route definition

Routes are declared in `app/Http/routes.php` as a flat array:

```php
return [
    ['GET',  '/auth/login',           [AuthController::class, 'loginForm'],   ['guest']],
    ['POST', '/auth/login',           [AuthController::class, 'login'],       ['guest', 'csrf', 'rate:login']],
    // ...
];
```

Each route is `[METHOD, PATH, [Controller::class, method], [middleware...]]`.

Path parameters use `{param}` syntax and are extracted by the Router.

### 5.2 Router matching

- Match on method + path.
- Path parameters become request attributes.
- No regex paths beyond `{param}`.
- On no match: 404.

### 5.3 Request object

`Request` exposes:
- `method()`, `path()`, `query()`, `post()`, `json()`, `files()`, `header()`, `cookie()`
- `attr($key)` for router-assigned parameters
- `ip()`, `userAgent()`, `uaHash()`

### 5.4 Response object

`Response` supports:
- `html($body, $status = 200)`
- `json($payload, $status = 200)`
- `redirect($url, $status = 302)`
- `noContent()`
- `download($path, $name)`

HTML responses render PHP templates with a shared `View` helper.

---

## 6. Envelope & Response Rules

Every JSON response is wrapped (see Blueprint §8). Implemented by `Envelope::ok($data, $meta)` and `Envelope::error($errors, $meta, $status)`.

- `csrf` is always the current session's CSRF token.
- `meta.culture`, `meta.direction`, `meta.timezone` come from the resolved culture.
- `meta.server_time_utc` is ISO-8601 UTC.
- `meta.impersonating` is `true` when the current session has `impersonated_by` set.

Errors include `code` (machine-readable), `field` (nullable), `message` (translated).

---

## 7. Database Layer

### 7.1 Two connections

`Database` class opens two PDO connections:

- `app.sqlite` — operational data.
- `logs.sqlite` — audit, app logs, login attempts, SMS, OTP, changelog.

Both:
- `PRAGMA journal_mode=WAL`
- `PRAGMA foreign_keys=ON`
- `PRAGMA busy_timeout=5000`
- `PRAGMA synchronous=NORMAL`

### 7.2 Connection interface

- `Database::pdo()` returns the PDO handle.
- `Database::transaction(callable $fn)` runs a closure inside a transaction and commits or rolls back.
- No global state; the connection is a singleton inside the container.

### 7.3 Queries

- All queries use prepared statements.
- Named parameters preferred.
- Batching (inserts, updates) uses explicit transactions.
- Every query that could exceed ~200 ms is logged to `app_logs` with level `warning`.

### 7.4 Whitelist enforcement

Any query built dynamically (report engine, filter UI) must pass columns, operators, and sort keys through a whitelist declared by the report class. Raw user input never reaches SQL.

---

## 8. Models Layer

All models live in `app/Models/Models.php`. Each model is a class with:

- Table name constant.
- Column list constant.
- Type casts map.
- Relations declared as methods.
- **No business logic.**
- **No database writes.**

Models are thin data structures. Writes happen via services. Reads happen via services or the report engine.

### 8.1 BaseModel

Provides:
- `find($id)`, `findBy($column, $value)`, `all()`
- `hydrate($row)`
- `toArray()`
- `toJson()`

### 8.2 Model documentation

Each model class has a docblock:

- **Purpose** — what it represents.
- **Table** — DB table name.
- **Relations** — list of related models and their types.
- **Side effects** — none expected.

---

## 9. Services Layer

### 9.1 Purpose

Services contain all business logic. They:

- Receive dependencies via constructor.
- Expose intention-revealing methods.
- Wrap multi-step writes in transactions.
- Validate inputs.
- Fire notifications, SMS, and audits as side effects.
- Return typed results or throw domain exceptions.

### 9.2 Service method documentation

Every public method's docblock must include:

- **Purpose**
- **Parameters** — type, name, meaning, constraints
- **Returns** — type, shape, meaning
- **Throws** — every exception it may raise
- **Side effects** — DB writes, cache clears, file writes, notifications, SMS, external calls
- **Transaction** — yes/no
- **Example** — one working snippet

Example:

```php
/**
 * Confirm a paid signup.
 *
 * Marks the signup as confirmed, records the confirming user, and notifies the rider.
 *
 * @param int $signupId    Signup ID (must exist, must be in 'paid' state).
 * @param int $confirmedBy User ID of the confirming manager/admin.
 * @return array{signup_id:int, confirmed_at:string} Confirmation metadata.
 * @throws NotFoundException        If the signup does not exist.
 * @throws ValidationException      If the signup is not in 'paid' state.
 * @throws DisabledUserException    If the confirming user is disabled.
 *
 * Side effects:
 *   - writes to signups (status='confirmed', is_confirmed=1, confirmed_by, confirmed_at)
 *   - clears cache namespace 'reports'
 *   - creates a notification for the rider
 *   - sends SMS if sms.enabled and sms.notify_on_confirmation
 *   - writes to logs.sqlite.changelog
 *
 * Transaction: yes.
 *
 * @example
 *   $service->confirm(42, $managerId);
 */
```

### 9.3 Service list (merged)

All service classes live under `app/Services/`. One file per domain (see Blueprint §5).

---

## 10. Controllers Layer

### 10.1 Purpose

Controllers translate HTTP ↔ services. They:

- Parse input.
- Validate at the boundary (using shared validators).
- Call the appropriate service.
- Build the envelope.
- Return a response.

Controllers do **not**:
- Contain business logic.
- Touch the DB directly.
- Emit `die()` or `exit()` (except payment redirects).

### 10.2 Controller method documentation

Every controller method's docblock must include:

- **Route** — METHOD + path
- **Auth requirement** — guest / auth / role
- **Parameters** — route params, query, body
- **Returns** — envelope shape or template name
- **Throws** — every exception path
- **Example** — a sample request and response

Example:

```php
/**
 * Confirm a paid signup.
 *
 * Route:      POST /panel/signups/{id}/confirm
 * Auth:       auth, role:admin|manager
 * Parameters: id (int, route)
 * Returns:    JSON envelope with data = { signup_id, confirmed_at }
 * Throws:     404 NotFoundException, 422 ValidationException, 403 AuthException
 *
 * @example
 *   POST /panel/signups/42/confirm
 *   → { ok: true, data: { signup_id: 42, confirmed_at: "2025-01-01T..." } }
 */
```

---

## 11. Authentication

### 11.1 Identity

- Login field accepts **username** OR **phone**.
- Phone stored as **E.164** (`+98...`).
- Username for auto-generated Riders is a **unique 6–8 digit number**.
- Password hashing: `password_hash(PASSWORD_DEFAULT)`.
- Rehash on login if algorithm cost changes.

### 11.2 Password login flow

1. Rate limit check (`rate:login`).
2. Captcha check if `auth.captcha_on_login`.
3. Look up user by username or phone.
4. If not found → generic failure message (no enumeration).
5. If `disable_state = full` → `USER_DISABLED_FULL` (403).
6. If `verification_status = pending` and role = rider → allow login, set session flag `pending_verification=true`.
7. Verify password.
8. On success: rotate session ID, bind UA hash, record `last_login_at` and IP.
9. Issue `Set-Cookie`: HttpOnly, SameSite=Lax, Secure (when HTTPS), Path=/.
10. Audit entry.
11. If `pending_verification=true` → envelope `meta.pending_verification = true`; UI shows banner.

### 11.3 OTP login flow

Available only when `sms.enabled = true`.

1. `POST /auth/login/otp/request` with `phone`.
2. Rate limit: 5 per phone per hour, 10 per IP per hour.
3. Captcha if `auth.captcha_on_otp_request`.
4. Generate 5-digit code, hash it, store in `logs.sqlite.otp_codes` with 120s TTL.
5. Send via MelyPayamak using the configured pattern.
6. `POST /auth/login/otp/verify` with `phone`, `code`.
7. Max 3 attempts. On success, mark used, create session (same as password login).
8. On failure: increment attempts; if attempts ≥ 3, invalidate code.

### 11.4 Sessions

- Table: `sessions`.
- ID: 64 random chars (base62 or hex), stored in cookie `session_id`.
- Payload (JSON): `{ csrf_token: "..." }`.
- Session row fields: `user_id`, `role`, `impersonated_by`, `ip`, `ua_hash`, `payload`, `last_activity_at`, `created_at`, `expires_at`.
- **Lifetime:** fixed 90 days from `created_at`. No sliding.
- **Rotation:** on login, on privilege change, on password change, on impersonation start/end.
- **UA mismatch:** kill session.
- **IP mismatch:** log warning; second mismatch kills session.
- **Force logout:** Admin can revoke all sessions from the Users page.

### 11.5 Captcha

- Server-generated image via GD.
- Character set excludes ambiguous glyphs.
- Signed token stored in session; TTL 3 minutes; single-use.
- Applied to: login, signup, OTP request, password reset request.

### 11.6 Rate limiting

- Generic table `rate_limits` in main DB.
- Rules:
  - Login: 5 failed attempts per (IP, username) in 5 min → locked until last failure + 5 min.
  - Login: soft cap 20 attempts/hour per IP.
  - OTP request: 5/phone/hour, 10/IP/hour.
  - OTP verify: 3 attempts/code.
  - Signup: 3/hour per IP.
  - Share unlock (if applicable): not in scope after snapshot removal.

### 11.7 Password reset

- **No self-service reset via email.**
- **No self-service reset via SMS OTP.**
- Manager/Admin can set a new password from the user's detail page.
- Manager/Admin types the new password manually (no auto-generation).
- Optionally, a short-lived `password_resets` token may be issued for a Manager-triggered flow (retained in schema for future use).

### 11.8 Pending rider

- Can log in.
- Can edit profile.
- **Cannot add horses.**
- **Cannot sign up for competitions.**
- Sees a persistent warning banner.
- Auto-verified after 48 hours.
- If rejected by Manager → account + horses + uploads hard-deleted; signups anonymized; payment orders kept.

### 11.9 Disable states

- `limited`: can log in, read-only, can generate reports, can share reports. Cannot create/modify records.
- `full`: cannot log in; login attempt returns `USER_DISABLED_FULL` with a "contact support" message.

---

## 12. Authorization

### 12.1 Middleware-driven

- `RoleMiddleware` accepts a list of allowed roles per route.
- Role is loaded from session.
- Impersonation does not bypass role checks (Admin impersonating a Rider is subject to Rider permissions).

### 12.2 Scoping

- Managers: global.
- Riders: scoped to own records (via `user_id`).
- Clubs: scoped to own club (via `club.user_id`).

Scoping is enforced in services, not controllers. Controllers only declare the role; services enforce ownership.

### 12.3 Record-level scoping rules

- Rider accessing a horse: `horse.owner_user_id = current_user_id` OR an active share exists.
- Rider accessing a signup: `signup.rider_user_id = current_user_id`.
- Club accessing a report: rows where `affiliation_club_id = club.id` OR `competitions.venue_club_id = club.id`.

---

## 13. Middleware Pipeline

Executed in order per request:

1. `SecurityHeadersMiddleware` — sets standard headers.
2. `MaintenanceMiddleware` — if `app.maintenance = 1`, returns 423 for panel routes; allows `/install`, `/payment/callback`, `/auth/logout`.
3. `CultureMiddleware` — resolves culture: `?culture=` → user pref → cookie → Accept-Language → default.
4. `RateLimitMiddleware` — applied per route (`rate:login`, `rate:otp`, etc.).
5. `AuthMiddleware` — required/optional. On missing auth for required routes → redirect (HTML) or 401 (JSON).
6. `DisabledUserMiddleware` — blocks full-disable; restricts limited.
7. `RoleMiddleware` — checks `role` against route's allowed roles.
8. `ImpersonationMiddleware` — tags writes with `impersonated_by`; blocks sensitive actions (impersonated users cannot change passwords, cannot initiate transfers, etc.).
9. `CsrfMiddleware` — verifies CSRF token for POST/PUT/PATCH/DELETE.

---

## 14. CSRF

- Token: 32 random bytes, hex-encoded.
- Stored in session payload.
- Echoed in every JSON envelope (`csrf`) and every HTML form (hidden input `_csrf`).
- Sent via header `X-CSRF-Token` for AJAX requests.
- Verified with `hash_equals`.
- Rotated on login, logout, privilege change, and impersonation start/end.

---

## 15. Culture, Calendar & Numbers

### 15.1 Culture source of truth

- `cultures` table: list, active status, default, direction, timezone.
- `cultures/{code}.json`: translations, formatting metadata, fonts, number formats.

### 15.2 fa-IR defaults (Iran-Tehran specific)

- Code: `fa-IR`
- Name: فارسی (ایران)
- Direction: `rtl`
- Calendar: `jalali`
- Timezone: `Asia/Tehran`
- Week start: Saturday
- Weekend: Friday
- Numbers: Persian digits (`۰۱۲۳۴۵۶۷۸۹`)
- Decimal: `٫`
- Thousands: `٬`
- Currency: IRT (`تومان`), suffix, space before
- Phone: +98
- Locale: `fa_IR`
- Font: Vazirmatn

### 15.3 Date handling

- DB: UTC ISO-8601 (`YYYY-MM-DDTHH:MM:SSZ`).
- Display: Jalali, formatted per culture JSON.
- Input: Shamsi picker on frontend; backend converts to UTC.
- Reports: Shamsi date filters converted to UTC ranges before SQL.

### 15.4 Number handling

- Storage: integers (money) or floats only where physically justified.
- Display: formatted per culture JSON.
- Input: accept both Persian and Latin digits; normalize before validation.

---

## 16. Validation Rules

### 16.1 Common rules

| Field | Rule |
|---|---|
| Username | 6–8 digits (auto for riders); 3–32 chars for managers/admins |
| Phone | E.164 (+98...); validated via libphonenumber |
| Email | RFC 5322; optional; never used for sending |
| Password | min 8 chars; no complexity requirements |
| National ID | 10 digits; Iranian check-digit algorithm |
| Microchip | 15 digits; unique among active + sold_to_non_rider horses |
| UELN | up to 30 chars; optional |
| Share code | 6 digits; unique per horse |
| Transfer code | 8 chars; unique per transfer |
| UUID | v4 |
| Money | integer ≥ 0; ≤ 1,000,000,000 IRT |

### 16.2 Entity-specific rules

**User**
- `role` in {admin, manager, rider, club}
- `disable_state` in {none, limited, full}
- `verification_status` in {pending, verified, rejected}
- Unique: `username`, `phone`, `email`

**Club**
- Unique: `slug`
- `user_id` unique

**Horse**
- Unique: `microchip_number` (among active + sold_to_non_rider)
- `gender` in {مادیان، نریان، اخته}
- `status` in {active, sold_to_non_rider, soft_deleted}
- `ghamari_birthday` must be a valid date in the past

**Rade**
- Unique: `slug`
- `age_min`, `age_max` if present: `age_min ≤ age_max`

**Payment**
- Unique: `slug`
- `amount_irt` ≥ 0

**Competition**
- Unique: `slug`
- `start_registration_at` < `end_registration_at` ≤ `start_at`
- `status` in {draft, open, closed, running, finished, cancelled}
- `results_status` in {draft, confirmed, published}

**Competition-Rade**
- Unique per competition: `(competition_id, rade_id)`
- `capacity` null or ≥ 0
- `signup_mode` in {per_competition, per_rade}
- `payment_id` required

**Signup**
- Unique per (competition, competition_rade, rider, horse) — except when status in {cancelled, withdrawn}
- `status` in {pending_payment, paid, confirmed, rejected, cancelled, withdrawn}
- `position` null or ≥ 1
- Payment snapshot columns populated when status ∈ {paid, confirmed}

**Payment Order**
- `status` in {pending, paid, failed, pending_refund, refunded}
- `amount_irt` ≥ 0
- `authority` unique when not null
- `ref_id` unique when not null

**Media**
- Image: mime in allowed list; size ≤ settings
- Doc: mime in allowed list; size ≤ settings
- SVG: sanitized before storage

---

## 17. Business Rules & Invariants

### 17.1 Signup rules

- A signup is created only in state `pending_payment`.
- A signup transitions to `paid` only via ZarinPal verify callback.
- A signup transitions to `confirmed` when either:
  - `competition_rades.auto_confirm = 1` (auto, at payment success), or
  - Manager/Admin confirms manually.
- A signup transitions to `rejected` only from `paid` or `confirmed` by a Manager/Admin.
- A signup transitions to `cancelled` only from `pending_payment` or `paid` (before confirmation).
- A signup transitions to `withdrawn` only from `confirmed` (rider chooses not to race).
- A rejected/cancelled/withdrawn signup remains in DB for reports.

### 17.2 Capacity

- Signup is blocked if Rade capacity is reached (excluding cancelled/withdrawn rows).
- Capacity reduction below current count is blocked.

### 17.3 Price snapshot

- On signup creation, the following are copied into the signup:
  - `payment_id_snapshot`
  - `payment_name_snapshot`
  - `payment_amount_irt_snapshot`
- These are immutable after creation.
- Reports use snapshot columns, not the live `payments` table.

### 17.4 Payment template changes

- Changing `payments.amount_irt` affects **future** signups only.
- Existing signups keep their snapshot.
- Existing Competition-Rades referencing the payment template will use the new amount on next signup.

### 17.5 Horse ownership

- A horse is always tied to exactly one Rider (`owner_user_id`).
- A horse can be shared to multiple Riders (via `horse_shares`).
- A horse can be transferred via the transfer flow.
- A horse with historical signups cannot be hard-deleted; it must be marked `sold_to_non_rider` or `soft_deleted`.
- Microchip uniqueness ignores `soft_deleted` rows.

### 17.6 Transfer

- Only the current owner can initiate a transfer.
- Initiating a transfer locks the horse (`transfer_locked = 1`) and resets the share code.
- The transfer code is valid until:
  - accepted, or
  - rejected (code remains valid), or
  - cancelled by the owner, or
  - expired (default 7 days).
- On accept: ownership changes; horse is unlocked; transfer row marked `completed`.

### 17.7 Share

- Only the owner can share.
- The recipient must be a Rider.
- Share code is per (owner, horse, recipient).
- Removing the share deactivates the row (no hard delete).

### 17.8 Bans

- Club bans are forward-looking.
- Admin/Manager bans: priority Rider > Competition > Rade.
- A ban on Rider applies everywhere.
- A ban on Competition applies to all Rades of that competition.
- A ban on Rade applies only to that Rade.

### 17.9 Results

- Results are entered per signup (`position`, `is_winner`, `result_notes`).
- Barrage is a flag on `competition_rades` (`had_barrage`) plus free-text notes.
- Results flow: `draft` → `confirmed` → `published`.
- Once published, only Admin can reopen (`published` → `confirmed`).
- Published results generate rider notifications.

### 17.10 Disable states

- Disabled (limited) users cannot create/modify records.
- Fully disabled users cannot log in.

### 17.11 Impersonation

- Admin only.
- Impersonated session stores `impersonated_by`.
- Every write during impersonation is tagged.
- Impersonated users cannot:
  - change passwords
  - initiate transfers
  - delete accounts
  - access settings
  - revoke sessions

### 17.12 Idempotency

- Payment callbacks are locked per authority.
- Duplicate callbacks return the existing result without re-processing.
- Notifications are deduplicated by (user, type, ref) within a 5-minute window.

---

## 18. Report Engine

### 18.1 Overview

Single endpoint: `POST /panel/reports/data`. Accepts report key, filters, columns, sort, pagination. Returns rows + meta.

### 18.2 Report class contract

Every report implements:

```php
interface ReportInterface {
    public static function key(): string;
    public static function label(): array;         // per culture
    public static function columns(): array;       // ColumnDefinition[]
    public static function filters(): array;       // FilterDefinition[]
    public static function sortable(): array;      // column keys
    public static function defaultColumns(): array;
    public function build(array $ctx): ReportQuery;
    public function project(array $row): array;
    public function authorize(User $user): bool;
}
```

### 18.3 Query building

- `ReportQueryBuilder` accepts whitelisted columns, filters, sort, and pagination.
- It emits SQL with named parameters.
- Raw input is never concatenated.
- Date filters (Shamsi input) are converted to UTC ranges.

### 18.4 Column and filter definitions

- Columns: `key`, `label` (per culture), `type` (string|int|float|date|datetime|bool|enum), `aggregatable`, `exportable`, `printable`.
- Filters: `key`, `label`, `type`, `operators` (in, eq, ne, gt, lt, between, contains), `options`, `validators`.

### 18.5 Export

- `POST /panel/reports/export` returns a signed URL.
- Signed URL: `GET /panel/reports/download/{token}` where token is HMAC-signed and expires in 1 hour.
- Format: XLSX (via SheetJS on frontend) or CSV (server-generated).

### 18.6 Grid state persistence

- Column visibility, order, filters, and sort persist in `localStorage` per user per report.
- Page number always resets to 1.

### 18.7 Report sharing

- `report_shares` row: owner, shared_to, report_key, filter_state_json.
- Recipient sees the same view live, using the sharer's filters.
- Riders can share only with Managers/Admins.
- Managers/Admins can share with anyone.
- Shares can be revoked.

---

## 19. Payment Integration (ZarinPal)

### 19.1 Endpoints

- Request: `POST https://payment.zarinpal.com/pg/v4/payment/request.json`
- Verify: `POST https://payment.zarinpal.com/pg/v4/payment/verify.json`

### 19.2 Request payload

```json
{
  "merchant_id": "...",
  "amount": 5000000,
  "currency": "IRT",
  "callback_url": "https://panel.example.com/payment/callback",
  "description": "شرکت در مسابقه ...",
  "metadata": { "signup_uuid": "..." }
}
```

### 19.3 Verify payload

```json
{
  "merchant_id": "...",
  "amount": 5000000,
  "authority": "..."
}
```

### 19.4 Response codes

- `100` — success.
- `101` — already verified.
- Others — failure.

### 19.5 Callback flow

1. `GET /payment/callback?Authority=X&Status=OK`.
2. Look up `payment_order` by `authority`.
3. If already `paid`, return success without re-processing.
4. Call verify.
5. On success: update order (`paid`, `ref_id`, `card_pan`, `verified_at`), update signup (`paid`), auto-confirm if applicable.
6. On failure: order → `failed`; signup stays `pending_payment`.
7. Redirect to success or failed page.

### 19.6 Refunds

- Manual only.
- Manager clicks "Mark pending refund" → status `pending_refund`.
- After manual refund, clicks "Mark refunded" → status `refunded`.
- Toggle back supported.

### 19.7 Reconciliation

- Page: `/panel/payment-orders/reconciliation`.
- Compares local orders (by `authority`, `ref_id`) against a manually supplied ZarinPal report (CSV upload).
- Flags mismatches.

---

## 20. SMS Integration (MelyPayamak)

### 20.1 Client

- Base URL: `https://rest.payamak-panel.com/api/SendSMS/`
- Auth: `username` + `password` (API key).
- Endpoints: `SendSMS`, `SendSMSWithPattern` (for OTP).

### 20.2 Configuration

- All settings under `sms.*`.
- Disabled by default.
- Admin enables via panel.

### 20.3 OTP

- 5-digit code.
- 120s TTL.
- 3 attempts max.
- Rate limited: 5/phone/hour, 10/IP/hour.
- Stored hashed in `logs.sqlite.otp_codes`.
- Sent via pattern.

### 20.4 Notifications

- Signup, payment, confirmation, results, transfer, ban.
- Each gated by `sms.notify_on_*` setting.
- Failures are logged but never block the calling action.

### 20.5 Fallback

- If SMS is disabled, OTP endpoints return `403 SMS_DISABLED`.
- If SMS fails mid-lifecycle, the action continues; the SMS log records the failure.

---

## 21. File Uploads & Media

### 21.1 Storage

- `uploads/{yyyy}/{mm}/{uuid}.{ext}` for general media.
- `uploads/avatars/{user_id}.{ext}` for avatars.
- `uploads/horses/{horse_id}/{uuid}.{ext}` for horse gallery.
- `uploads/clubs/{club_id}/{uuid}.{ext}` for club media.
- `uploads/demo/` for demo seed media.

### 21.2 Processing

- Images: GD-first, Imagick if present.
- Max dimension configurable (default 2560).
- Quality configurable (default 82).
- Format: `original` or `webp`.
- SVG: sanitized via a whitelist.

### 21.3 Limits

- Per-kind size caps (image 10 MB, doc 25 MB).
- Per-user total quota (500 MB).
- Horse gallery: max 5 images per horse.

### 21.4 Security

- `.htaccess` in `uploads/` disables PHP execution.
- Filenames replaced by UUID; original name stored as metadata.
- MIME sniffed server-side; extension coerced from MIME.
- Downloads served via authenticated routes; no direct path leakage.

---

## 22. Caching

### 22.1 Implementation

- File-based under `cache/`.
- Namespaces: `settings`, `cultures`, `thumbs`, `reports`.
- Keys: `namespace:hash`.
- TTL per namespace (settings-configurable).

### 22.2 Invalidation

- On write, the affected namespace is cleared.
- No global invalidation on every write.
- Admin "Clear Cache" clears all namespaces.

### 22.3 Cache service API

- `get($ns, $key, $ttl, $producer)`
- `forget($ns, $key)`
- `clearNamespace($ns)`
- `clearAll()`

---

## 23. Logging

### 23.1 Storage

- `logs.sqlite` for structured logs.
- `logs/app/YYYY-MM/YYYY-MM-DD.log` and `logs/audit/YYYY-MM/YYYY-MM-DD.log` for raw JSON-lines retention.

### 23.2 Structured tables

- `audit_logs` — every write with actor, target, diff, result.
- `app_logs` — errors, warnings, slow queries.
- `login_attempts` — failed and blocked.
- `sms_logs` — outbound.
- `otp_codes` — hashed.
- `changelog` — manager action summaries.

### 23.3 Format

JSON lines, one event per line:

```json
{"ts":"2025-01-01T10:00:00Z","lvl":"info","rid":"...","actor":{"id":1,"role":"admin","ip":"1.2.3.4","ua_hash":"..."},"action":"user.create","target":{"type":"user","id":42},"diff":{"role":"manager"},"result":"ok"}
```

### 23.4 Retention

- App logs: 30 days.
- Audit: 180 days.
- SMS: 90 days.
- Login attempts: 30 days.
- Changelog: forever (small rows).
- Payment orders: forever (in main DB).

### 23.5 Opportunistic cleanup

- 1 in 100 requests triggers cleanup.
- Runs inside a short transaction.

---

## 24. Backup / Restore / Reset

### 24.1 Backup

- Admin UI trigger.
- Zip: `app.sqlite`, `logs.sqlite`, `uploads/`, `storage/shares/` (optional).
- DB copy via `VACUUM INTO`.
- Naming: `backup-YYYY-MM-DD_HHMMSS-{suffix}.zip`.
- Storage: `backups/`.

### 24.2 Restore

1. Maintenance mode on.
2. Pre-restore safety snapshot.
3. Replace DB + uploads.
4. Re-inject pre-restore app key.
5. Upsert current admin.
6. Revoke all sessions except current admin's.
7. Clear cache.
8. Maintenance mode off.
9. Audit entry.

### 24.3 Reset

- Wipes DB data (except settings + current admin).
- Wipes uploads/.
- Re-injects current admin + session.
- Clears cache.
- Audit entry.

### 24.4 Demo seed

- Idempotent.
- Rich (see Blueprint §15).
- All rows tagged `is_demo = 1`.
- Files under `uploads/demo/`.

### 24.5 Demo clear

- Deletes all `is_demo = 1` rows.
- Deletes demo media.

---

## 25. Installer

### 25.1 Entry

- `/install` — blocked if `settings` table has rows.

### 25.2 Steps

1. Requirements check (PHP version, extensions).
2. Writable checks.
3. Create `app.sqlite` + `logs.sqlite`.
4. Apply `schema.sql` + `schema_logs.sql`.
5. Generate `app.key`.
6. Seed reference data:
   - cultures (fa-IR, en-US)
   - default settings
   - horse genders, races, colors
7. Create first Admin (username, phone, password).
8. Optional: seed demo data.
9. Self-lock (write `app.installed = 1` to settings).

### 25.3 Failure

- On failure, rollback the DB files and unlock.

---

## 26. Frontend Architecture

### 26.1 Philosophy

- Server-rendered PHP templates.
- Alpine.js for local interactivity.
- AG Grid for large data.
- No SPA.
- No build step at runtime.

### 26.2 Template structure

- `app/Views/layouts/panel.php` — panel chrome.
- `app/Views/layouts/auth.php` — auth chrome.
- `app/Views/layouts/print.php` — print chrome.
- `app/Views/partials/*` — shared fragments.
- `app/Views/panel/*` — per-page templates.
- `app/Views/auth/*` — auth pages.
- `app/Views/print/*` — print templates.
- `app/Views/errors/*` — error pages.

### 26.3 CSS

- Tailwind CSS, built and committed.
- `print.css` for A4 print styles.
- Logical properties everywhere.
- Vazirmatn font self-hosted.

### 26.4 JS

- `app.js` — Alpine bootstrap, tooltip helpers, CSRF injection, form helpers.
- `grid.js` — AG Grid wrapper with server-side row model.
- `qr.js` — QR generator.
- Alpine components are declared inline in templates.

### 26.5 Grid contract

POST `/panel/reports/data`:

```json
{
  "report": "signups",
  "page": 1,
  "per_page": 50,
  "sort": [{ "col": "created_at", "dir": "desc" }],
  "filters": [{ "col": "status", "op": "in", "value": ["paid"] }],
  "columns": ["rider", "horse", "competition", "rade", "position"]
}
```

Response `data`:

```json
{
  "columns": [ { "key": "rider", "label": "سوارکار", "type": "string" } ],
  "rows": [ { "rider": "...", "horse": "...", "position": 1 } ],
  "page": 1, "per_page": 50, "total": 1240, "filtered": 312
}
```

### 26.6 Persistence

- Column visibility, order, filters, sort → `localStorage` per user per report.
- Page number → always 1.

### 26.7 Accessibility

- Keyboard-navigable grid.
- Focus rings on all interactive elements.
- Sufficient contrast.

### 26.8 Tooltips

- Inline help tooltips on complex fields.
- Content authored in fa-IR; may be overridden per culture.

---

## 27. Print & QR

### 27.1 Print views

- Server-rendered PHP templates.
- `@page { size: A4 portrait; margin: 12mm; }`.
- Print header: brand + entity title + Shamsi date.
- Print footer: page number + federation name.
- Tables use `print.css`.

### 27.2 Printable entities

- Competition (single + list)
- Horse (single + list)
- Rider (single)
- Club (single + list)
- Payment (single + list + selected-as-individuals)
- Signup sheet (per competition)
- Standings (per rider, per horse, per rider-horse pair)

### 27.3 QR

- QR encodes the current panel URL + query state.
- Generated client-side via `qr.js` or server-side via `/panel/qr?data=...` (cached).
- Available on: competition paper, signup sheet, report page, entity page header.

---

## 28. Error Handling

### 28.1 Exception funnel

All exceptions pass through `App\Exceptions\Handler`.

- `ValidationException` → 422 with field errors.
- `AuthException` → 401 (JSON) or redirect (HTML).
- `DisabledUserException` → 403 with `USER_DISABLED_LIMITED` or `USER_DISABLED_FULL`.
- `NotFoundException` → 404.
- `RateLimitException` → 429 with `Retry-After`.
- Uncaught → 500 + logged with request id.

### 28.2 Request ID

Every request gets a request id (UUID). It is:
- Included in every log entry.
- Returned in `meta.request_id` for errors.
- Shown to the user in production error pages.

### 28.3 Debug mode

- `app.debug = 1` → stack traces in errors.
- `app.debug = 0` → generic messages, request id only.

---

## 29. Security Implementation

- **CSRF:** per-session token, `hash_equals` comparison.
- **XSS:** output escaping by default; HTMLPurifier for rich HTML.
- **SQLi:** prepared statements everywhere; whitelist-driven report queries.
- **Sessions:** HttpOnly, SameSite=Lax, Secure, rotation, UA binding, IP warning.
- **Rate limiting:** login, signup, OTP.
- **Captcha:** server-generated, single-use, TTL 3 min.
- **Headers:** X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP (configurable), HSTS if HTTPS.
- **Uploads:** MIME sniffing, UUID rename, no execution.
- **Sensitive dirs:** protected via `.htaccess` (Apache) + Nginx sample.
- **Password hashing:** `PASSWORD_DEFAULT`.
- **Token comparisons:** `hash_equals`.
- **Random:** `random_bytes`.
- **Installer:** self-locks.

---

## 30. Performance

- **SQLite WAL** for concurrent reads.
- **Indexes** on all foreign keys and frequent filters.
- **Prepared statements cached** by PDO.
- **Reports** paginated; exports stream.
- **Cache** for settings, cultures, thumbnails.
- **Slow query log** at 200 ms.
- **OPcache** recommended.
- **HTTP caching** for static assets (long TTL, versioned filenames).

---

## 31. Code Style & Standards

- PHP 8.1+ with `declare(strict_types=1);` in every file.
- PSR-12 style, enforced manually.
- Class names: `PascalCase`.
- Methods and variables: `camelCase`.
- Constants: `UPPER_SNAKE`.
- One blank line between methods.
- No trailing whitespace.
- No closing `?>` in PHP-only files.
- All strings in code are English; all user-facing strings are translated.

---

## 32. Documentation Standard

**Documentation is mandatory.** Every file, class, method, function, endpoint must be documented.

### 32.1 File header

```php
<?php
declare(strict_types=1);

/**
 * File: app/Services/SignupService.php
 *
 * Purpose:
 *   Handle signup lifecycle: creation, confirmation, rejection, cancellation,
 *   withdrawal, and result entry.
 *
 * Dependencies:
 *   - Database (main)
 *   - LogDatabase
 *   - SettingService
 *   - NotificationService
 *   - SmsService
 *   - PaymentService
 *
 * Conventions:
 *   - All money in IRT integers.
 *   - All timestamps in UTC ISO-8601.
 *   - All writes wrap in a transaction.
 *
 * @package App\Services
 */
```

### 32.2 Class docblock

Purpose, responsibilities, non-responsibilities, usage example.

### 32.3 Method docblock

Purpose, parameters, return, throws, side effects, transaction boundary, example.

### 32.4 Inline comments

Only where the why is not obvious from the code. Never explain the what.

### 32.5 Schema comments

Every column is documented in `schema.sql` with a one-line comment.

### 32.6 Setting comments

Every setting has a docstring: type, default, purpose, related settings.

### 32.7 Report comments

Every report class documents:
- Report key.
- Available columns (with types).
- Available filters (with operators).
- Default sort.
- Sample output.

### 32.8 Controller comments

Every controller method documents:
- Route.
- Auth/role requirement.
- Parameters.
- Return shape.
- Example.

### 32.9 Commit comments (if using VCS)

- First line: imperative summary.
- Body: why, not what.
- Reference the affected spec section.

---

## 33. Third-Party Libraries (vendored)

### 33.1 PHP

| Library | Purpose | Notes |
|---|---|---|
| `morilog/jalali` | Jalali ↔ Gregorian | Wrapped by `CultureService` |
| `giggsey/libphonenumber-for-php` | Phone validation | Full metadata |
| `ezyang/htmlpurifier` | HTML sanitization | Rich HTML fields |
| `intervention/image` | Image processing | GD default, Imagick if present |
| `erusev/parsedown` | Markdown → HTML | Help text only |

### 33.2 Frontend

| Library | Purpose | Notes |
|---|---|---|
| Alpine.js 3 | Interactivity | Vendored |
| AG Grid Community | Data grid | Vendored |
| SheetJS | XLSX export | Vendored |
| qrcode.js | QR generation | Vendored |
| Vazirmatn | Persian font | Self-hosted |
| Tailwind CSS | Styling | Built output committed |

All libraries are vendored as plain files under `vendor/` (PHP) or `public/assets/js/vendor/` (JS). No CDN at runtime.

---

## 34. Deployment

### 34.1 Transfer

- Copy the entire project folder.
- Exclude `cache/*` and `database/*.sqlite` if starting fresh.
- Include `vendor/`, `public/assets/`.

### 34.2 Apache

- Works out of the box with `.htaccess` at root and in `public/`.

### 34.3 Nginx

- Ship `docs/nginx.conf.sample`.
- Document `try_files` and PHP-FPM configuration.

### 34.4 PHP version floor

- Enforced by the installer check.

### 34.5 OPcache

- Recommended; installer warns if disabled.

### 34.6 Domains

- Change freely — no hostnames stored.
- `app.url_force_https` and `security.trusted_ips_admin` are the only domain-related settings.

### 34.7 Backups

- Before any upgrade or restore, take a backup.
