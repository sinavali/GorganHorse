# AGENTS.md — Gorgan Horse Federation Panel

**Project:** Backend panel for the Gorgan Horse Federation (هیئت سوارکاری استان گلستان)
**Branch:** `kilo/modular-chime-ft3` → PR to `main`
**Language:** PHP 8.1+, Persian (fa-IR), English
**Database:** SQLite 3 (WAL mode, two files: app.sqlite, logs.sqlite)

---

## Tech Stack

| Layer | Choice |
|---|---|
| Language | PHP 8.1+ (8.4 supported), strict types |
| Framework | None — custom bootstrap, tiny DI container |
| Database | SQLite 3 (WAL), two connections (app + logs) |
| Frontend | Server-rendered PHP templates, Alpine.js 3 (vendored), Tailwind CSS (built) |
| Grid | AG Grid Community (vendored) |
| Exports | SheetJS (vendored) |
| Templating | Plain PHP, no engine |
| Package manager | None — all libraries vendored as plain files |
| No Composer, no Node at runtime | |

---

## Architecture Principles

These are MANDATORY. Every line of code must follow them.

1. **Writes go through services.** Controllers never touch the DB directly.
2. **Exception funnel.** All exceptions pass through `App\Exceptions\Handler`. No `die()`/`exit()` except payment redirects.
3. **Money is integer IRT (Toman).** All timestamps are UTC ISO-8601.
4. **Prepared statements everywhere.** Report queries are whitelist-driven — raw user input never reaches SQL.
5. **Sessions are DB-backed.** 90-day fixed lifetime, UA-bound, HttpOnly, SameSite=Lax, Secure.
6. **Payments are idempotent.** Price snapshots protect historical reports.
7. **Documentation is mandatory.** Every file, class, method, and endpoint must have docblocks.
8. **One entry point.** All requests go through `public/index.php`.
9. **Two DB connections.** `app.sqlite` (operational) and `logs.sqlite` (audit, logs, OTP).
10. **No email.** Email is dropped entirely. All communication via in-panel notifications + optional SMS (MelyPayamak).

---

## Roles & Permissions

| Role | Scope |
|---|---|
| Admin | Full access including impersonation, audit, backups, settings |
| Manager | Full CRUD on clubs, horses, rades, payments, competitions, signups, results. No settings/audit/backups. No impersonation. |
| Rider | Own records only (scoped by user_id). Can share/transfer horses, sign up for competitions. |
| Club | Scoped to own club. View affiliated riders, competitions at venue, bans, reports. |

---

## Key File Locations

### Application Code

| Path | Purpose |
|---|---|
| `public/index.php` | Single entry point |
| `app/Http/routes.php` | Flat route table |
| `app/Http/Controllers/` | One controller per domain |
| `app/Services/` | Business logic (one file per domain) |
| `app/Models/Models.php` | All models (thin data structures) |
| `app/Exceptions/Exceptions.php` | All exceptions |
| `app/Http/Middleware.php` | All middleware (9 middleware pipeline) |
| `app/Views/` | PHP templates |
| `app/Views/panel/` | Panel page templates (30+ files) |
| `app/Views/auth/` | Auth pages |
| `app/Views/print/` | Print templates (8 entities) |
| `app/Services/Report/` | Report engine |
| `app/Support/Helpers.php` | Global helper functions |
| `app/Bootstrap/` | App bootstrap, Database setup |

### Configuration & Data

| Path | Purpose |
|---|---|
| `database/schema.sql` | Main DB schema |
| `database/schema_logs.sql` | Logs DB schema |
| `database/seeds/reference.php` | Reference seed data |
| `cultures/fa-IR.json` | Persian culture |
| `cultures/en-US.json` | English culture |
| `documents/` | All specification documents |
| `config.toml` | OpenHands LLM configuration |

### Runtime Directories (excluded from git)

| Path | Purpose |
|---|---|
| `database/*.sqlite` | Runtime databases |
| `uploads/` | User-uploaded media |
| `cache/` | File cache |
| `logs/` | Raw JSON-lines logs |
| `backups/` | Zip backups |
| `vendor/` | Vendored PHP libraries (tracked in git) |
| `public/assets/` | Frontend assets (tracked in git) |

---

## Authentication & Security

- Login: username OR phone (E.164 +98...)
- Password hashing: `password_hash(PASSWORD_DEFAULT)`
- OTP login via SMS (MelyPayamak) — disabled by default
- Captcha: server-generated GD image, signed token, TTL 3 min, single-use
- Rate limiting: login (5 failures/5min), OTP (5/phone/hour), signup (3/hour per IP)
- CSRF: 32-byte hex token, `hash_equals` verification, rotated on privilege change
- Security headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP, HSTS
- Uploads: UUID rename, MIME sniffing, `.htaccess` prevents PHP execution

---

## Development Workflow

### Running the project
1. PHP 8.1+ with extensions: `pdo_sqlite`, `sqlite3`, `mbstring`, `json`, `openssl`, `fileinfo`, `gd`/`imagick`, `zip`, `intl`, `curl`, `dom`
2. Copy project folder to web root
3. Visit `/install` to set up databases, settings, and first admin
4. No Composer, no Node, no build step needed at runtime

### Adding a new route
1. Add entry to `app/Http/routes.php`
2. Create controller method
3. Create view template in `app/Views/panel/` or `app/Views/auth/`
4. Add service method if business logic needed
5. Document with docblocks

### Adding a new report
1. Create report class implementing `ReportInterface` in `app/Services/Report/Reports.php`
2. Register in registry with unique key
3. Define columns, filters, sortable columns, default columns
4. Whitelist all dynamic SQL parts

### Database changes
1. Schema in `database/schema.sql`
2. Use transactions for multi-step writes
3. All queries use prepared statements
4. Logs go to `logs.sqlite` (separate connection)

---

## Code Standards

- `declare(strict_types=1);` in every PHP file
- PHP 8.1+ syntax, PSR-12 style
- Class names: PascalCase, methods/variables: camelCase, constants: UPPER_SNAKE
- All code strings in English; all user-facing strings in fa-IR
- No closing `?>` in PHP-only files
- No trailing whitespace
- One blank line between methods
- Docblocks mandatory at file, class, and method level

---

## Integration Points

| Service | Purpose | Default |
|---|---|---|
| ZarinPal | Payment gateway (IRT/Toman) | Required for paid signups |
| MelyPayamak | SMS (OTP + notifications) | Disabled; Admin enables |
| OpenStreetMap | Venue maps (if added) | No Google Maps (blocked in Iran) |

---

## Testing

- PHPUnit configuration: `phpunit.xml.dist`
- Run tests: `phpunit` or `vendor/bin/phpunit`
- No tests currently exist; add test coverage for new services and controllers

---

## Key Conventions for Agents

- Persian (fa-IR) is primary language. All UI in Persian.
- Jalali (Shamsi) calendar for all date displays. Gregorian for DB storage.
- Friday and Saturday weekend. Week starts Saturday.
- Timezone: Asia/Tehran.
- Currency: Toman (IRT), integers only, suffix "تومان".
- Phone: E.164 (+98...) in DB, displayed as 09xxxxxxxxx.
- National ID: 10 digits with Iranian check-digit algorithm.
- Microchip: 15 digits, unique among active + sold_to_non-rider horses.
- No dark mode, no PWA, no email, no CLI, no multi-tenancy.
