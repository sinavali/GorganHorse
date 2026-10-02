<!--
Features.md

File: documents/Features.md

Edit policy:
- This file is the only file under documents/ that agents may edit.
- Agents may edit only the Status and Notes fields of existing feature entries.
- Agents must not add, remove, reorder, or rename feature entries.
- Agents must not edit Title, Description, Considerations, or Status Description.
- Reviewer/Refinery may edit Considerations and Status Description only on the same PR.
- Reviewer/Refinery may also edit Status and Notes on the same PR.
- All other edits require owner approval.
-->

# Features

## Status Values

- Implemented
- Partially Implemented
- Not Implemented

## Feature Entries

<!-- ============ IMPLEMENTED FEATURES ============ -->

### Feature: Offline-First SQLite Database

- Status: Implemented
- Description: The entire application runs on SQLite with zero external database dependencies. Two SQLite files (app.sqlite, logs.sqlite) store all operational and audit data. No MySQL, PostgreSQL, Redis, or cloud database service is required. WAL mode enabled for concurrent reads. Two DB connections keep audit logs separate for performance.
- Notes: No foreign DBaaS (ElephantSQL, PlanetScale, Amazon RDS) is reachable or needed in Iran. See Technical §7, Blueprint §28.
- Considerations: SQLite suits the data volume (≈52 competitions/year, ≈1,800 signups/year). Ensure hosting provider supports SQLite file locking. No connection pool needed.
- Status Description: Fully implemented. The application is completely operational without any remote database server, essential in Iran where managed database services from foreign providers are inaccessible.

---

### Feature: Zero-CDN Vendored Library System

- Status: Implemented
- Description: Every third-party library ships as a plain file inside the repository — no CDN, no npm registry, no Composer/packagist access, at build time or at runtime. Front-end assets live under `public/views/assets/vendor/` (Quill 2.0.3) and `public/views/assets/fonts/` (Vazirmatn); PHP libraries live under `vendor/`.
- Notes: Most of the originally specified front-end libraries (Alpine.js, Tailwind, AG Grid, SheetJS, qrcode.js) were **dropped rather than vendored**: their behaviour is implemented natively in `ui.js` / server-side writers, so the panel needs neither a bundler nor a registry. The same reasoning removed `HTMLPurifier`, `Intervention Image` and `Parsedown` on the PHP side, replaced by the self-written shims in `app/Support/vendor-shims/` (`HtmlSanitizer`, `ImageProcessor`, `Markdown`) that the autoloader always loads. See Technical §2.2, §2.3, §33.
- Considerations: Library updates require manual vendor replacement. No automatic security patches from package managers. Periodic manual review of vendored libraries recommended.
- Status Description: Fully implemented. Every library is served from local files, ensuring the panel functions correctly even when Iranian ISPs filter or throttle access to foreign CDNs and registries.

---

### Feature: ZarinPal Domestic Payment Gateway Integration

- Status: Implemented
- Description: Full ZarinPal payment integration for competition registration fees. Riders pay via ZarinPal's gateway in IRT (Toman), receive a callback verification, and their signup is automatically confirmed (if auto-confirm is enabled) or enters manual confirmation queue. Payment orders are permanently recorded with authority, ref_id, card pan, and timestamps. Manual refund workflow: mark pending refund, then mark refunded. Reconciliation page compares local orders against manually uploaded ZarinPal CSV reports.
- Notes: Uses ZarinPal POST request/verify endpoints. Callback endpoint is public (no auth) for gateway compatibility. Idempotent processing locked per authority. See Technical §19, Blueprint §14.
- Considerations: ZarinPal is Iran's primary payment gateway; Stripe, PayPal, and foreign gateways are entirely inaccessible to Iranian merchants. Test with ZarinPal sandbox before going live.
- Status Description: Fully implemented. The integration uses ZarinPal's standard REST API and is the sole payment method, which is the only online payment option available for Iranian businesses.

---

### Feature: Password and OTP Authentication System

- Status: Implemented
- Description: Dual authentication system: (1) Password login with username or phone, rate limiting, captcha, session management with 90-day fixed lifetime, UA binding, IP mismatch warnings, session rotation on privilege change. (2) OTP login via SMS (when enabled): 5-digit code, 120s TTL, 3 attempts max, rate-limited per phone and IP. Password reset handled by Manager/Admin only — no self-service reset via email or SMS. Riders get auto-verification after 48 hours.
- Notes: Passwords hashed with password_hash(PASSWORD_DEFAULT). Sessions are DB-backed, HttpOnly, SameSite=Lax, Secure. No email sending anywhere. See Technical §11, AuthService, User Usage §6.
- Considerations: OTP login only available when SMS is enabled. No self-service password reset reduces support burden but requires admin intervention.
- Status Description: Fully implemented. Both password and OTP authentication flows are complete with rate limiting, captcha, session security, and proper disable state handling.

---

### Feature: User Management with RBAC and Impersonation

- Status: Implemented
- Description: Full user CRUD for Admin, Manager, Rider, and Club roles. Features include: verification (pending/verified/rejected), disable states (limited/read-only or full/blocked), impersonation by Admin (tagged with impersonated_by in all writes), session revocation (per-session and revoke-all), bulk verify/disable/enable, and per-user password reset by Manager/Admin. Riders auto-verify after 48 hours or can be rejected by Manager (account + horses + uploads hard-deleted, signups anonymized).
- Notes: Role-based authorization via middleware pipeline. Scoping: Managers global, Riders own records, Clubs scoped to affiliated riders and venue competitions. See Technical §12, Blueprint §4, User Usage §7.2.
- Considerations: Impersonated sessions cannot change passwords, initiate transfers, delete accounts, access settings, or revoke sessions.
- Status Description: Fully implemented. Complete user lifecycle management with role-based access control, disable states, impersonation, and audit trail.

---

### Feature: Club and Competition Management

- Status: Implemented
- Description: Complete Club management (CRUD, bans on riders/horses, print). Full Competition management (CRUD, pause/resume registration, cancel, clone, rades binding, barrage toggle, signup mode, print competition paper, print signup sheet). Rade management (CRUD, bulk activate/deactivate). Competitions have status lifecycle: draft → open → closed → running → finished → cancelled. Results flow: draft → confirmed → published.
- Notes: Print views for competition paper and signup sheet. Barrage flag plus free-text notes per rade. Clone creates prefilled copy. See Technical §17, User Usage §7.12.
- Considerations: Registration can be paused/resumed. Cancel marks paid orders as pending_refund. Results publish notifies all riders of the competition.
- Status Description: Fully implemented. All competition lifecycle management from creation through results publication, including print views for official documentation.

---

### Feature: Horse Management with Shares and Transfers

- Status: Implemented
- Description: Full horse CRUD with images (max 5 per horse), microchip validation (15-digit, unique), UELN, pedigree (sire/dam/breeder), registration number, status (active/sold_to_non_rider/soft_deleted). Horse shares: owner shares to specific riders with accept/reject. Horse transfers: initiate, claim, accept, reject, cancel with 8-character transfer code and 7-day expiry. Sold-to-non-rider marks horse permanently. Import/export CSV and export template available.
- Notes: Share code is 6-digit per (owner, horse, recipient). Transfer locks horse during process. Microchip uniqueness ignores soft-deleted rows. See Technical §17.5-17.7, User Usage §7.6-7.7, §9.2.
- Considerations: Horse ownership is always tied to exactly one rider. A horse with historical signups cannot be hard-deleted. Horse history endpoint provides full audit trail.
- Status Description: Fully implemented. Complete horse lifecycle including ownership sharing, transfers, sold-to-non-rider status, and CSV import/export.

---

### Feature: Rider Signup Flow with Payment Integration

- Status: Implemented
- Description: Riders browse competitions, view rades with prices, select horse (own or shared), choose affiliation club, confirm price, and pay via ZarinPal. Signup flow enforces: pending riders blocked, rade capacity check, payment snapshot captured at signup. Manager/Admin can confirm, reject, or set positions. Bulk confirm/reject available.
- Notes: Rider signup creates signup in pending_payment state. ZarinPal callback transitions to paid. Auto-confirm if competition_rade.auto_confirm=1. Price snapshots are immutable for historical reporting. See Technical §17.1-17.3, User Usage §9.6.
- Considerations: Rider cannot cancel a paid signup (support handles it). If rider is pending verification, signup is blocked with message. Free competitions (amount=0) skip payment.
- Status Description: Fully implemented. Complete rider signup flow from competition browsing through payment and confirmation.

---

### Feature: Results Entry and Standings

- Status: Implemented
- Description: Inline editable results per signup (position, is_winner, result_notes). Results flow: draft → confirmed → published. Published results generate rider notifications. Admin can reopen published results (published → confirmed). Barrage flag and notes per rade. Print standings per rider, per horse, per rider-horse pair. Per-competition results page with per-rade collapsible sections.
- Notes: Results entered per signup. Once published, only Admin can reopen. Standings available for individual rider, horse, and rider-horse combinations. See Technical §17.9, User Usage §7.17, §13.3.
- Considerations: Results must be confirmed before publishing. Publishing sends notifications to all riders of the competition. Published results are audit-critical.
- Status Description: Fully implemented. Complete results management from draft entry through confirmation and publication, with print views for standings.

---

### Feature: Report Engine with Grid and Export

- Status: Implemented
- Description: Unified report engine with 8 presets (signups, revenue, results, horses, riders, clubs, payments, bans). Server-side sorted/paged grid, whitelist-driven SQL queries (no raw user input in SQL), Shamsi date filter conversion, column visibility/order/filter/sort persisted in localStorage per user per report, CSV export via signed URLs (1-hour expiry), report sharing (live filter state sharing between users), and summary KPI tiles per report.
- Notes: Role scoping applied transparently. Export is generated server-side (`ReportEngine::toCsv()`, BOM-prefixed and culture-aware), so it needs no client-side spreadsheet library. Only CSV is produced — Excel, WPS and Sheets all open it directly, and the panel already prints any report as a formatted A4 document. Signed URL: HMAC-signed, expires in 1 hour. See Technical §18, User Usage §12. Filtering is two-layered: a simple bar (search, date-range presets, status chips, page size, clear-all) plus an advanced drawer with every declared filter column and operator (`eq`, `ne`, `contains`, `gt`, `gte`, `lt`, `lte`, `in`, `between`). Full state lives in the query string, and the result grid has a custom print layout.
- Considerations: Grid state persists in localStorage. Page number always resets to 1 when filters change. Riders can share reports only with Managers/Admins.
- Status Description: Fully implemented. Complete reporting system with 8 report types, filtering, export, sharing, and role-based scoping.

---

### Feature: Notification Center and Broadcast Messaging

- Status: Implemented
- Description: In-panel notification center with bell icon and unread count in topbar. Notifications grouped by day, unread marked with dot, click to open entity. Actions: mark as read, mark all as read. Broadcast messages: Managers/Admins compose with scope (global, competition, rade, selected users), with read receipts and SMS delivery status tracking. SMS delivery via MelyPayamak for notifications when enabled and configured.
- Notes: Notification types include signup, payment, verification, confirmation, results, transfer, ban, and broadcast. SMS gated by per-type settings (sms.notify_on_*). Deduplicated by (user, type, ref) within 5 minutes. See Technical §20, User Usage §14.
- Considerations: SMS notifications are disabled by default. Failed SMS deliveries are logged but never block the calling action.
- Status Description: Fully implemented for in-panel notifications and broadcast messaging. SMS delivery depends on MelyPayamak configuration (see Partially Implemented features).

---

### Feature: Settings Registry and System Configuration

- Status: Implemented
- Description: Comprehensive settings page with 15 tabs: General, Whitelabel, Auth, Uploads, Clubs, Horses, Competitions, Payments, SMS, Reports, Cache, Logs, Backup, Security, UI. Each setting stored in settings table with dot-notation key, type, label, help text. Settings cached with namespace-based invalidation. Admin can clear all cache. SMS settings configurable (credentials, pattern, OTP params, notification toggles). Payment settings configurable.
- Notes: Settings are cached under 'settings' namespace. All configuration lives in the settings table (P11). Cache cleared on write for affected namespace. See Technical §15, §22, Blueprint §15.
- Considerations: SMS settings require valid MelyPayamak credentials to function. All money amounts in settings are integer IRT.
- Status Description: Fully implemented. All system configuration through a single settings interface with 15 tabs, caching, and per-namespace invalidation.

---

### Feature: Backup, Restore, Reset, and Demo Data

- Status: Implemented
- Description: Admin-accessible backup management: create backup (zip of app.sqlite, logs.sqlite, uploads/, storage/shares/), download backup, restore from backup (with maintenance mode, safety snapshot, session revoke, cache clear), reset system (wipes all data except settings + current admin, typed confirmation), seed demo data (idempotent, rich dataset with ≈52 competitions, ≈1,800 signups), clear demo data.
- Notes: Backup naming: backup-YYYY-MM-DD_HHMMSS-{suffix}.zip. Restore includes: maintenance mode on, pre-restore snapshot, DB+uploads replace, re-inject app key, upsert admin, revoke sessions, clear cache. See Technical §24, User Usage §7.23.
- Considerations: Always take a backup before upgrade or restore. Demo data is tagged is_demo=1 for identification and removal.
- Status Description: Fully implemented. Complete data lifecycle management with backup, restore, reset, and demo seed/clear capabilities.

---

### Feature: Profile Management with Avatar and Password

- Status: Implemented
- Description: User profile editing (name, phone, email, national ID, insurance number, birth date, gender, address, city, province, bio, emergency contact). Avatar upload. Password change (requires current password, rotates all sessions). Session management: view active sessions, revoke individual session, revoke all sessions. Available for all authenticated roles.
- Notes: Riders can edit profile even in pending verification state. Profile page tabs: Profile, Avatar, Password, Sessions (rider) or Profile, Avatar, Password, Sessions (club). See User Usage §9.8, §10.2.
- Considerations: Username and role cannot be changed from profile. Avatar stored in uploads/avatars/{user_id}.{ext}.
- Status Description: Fully implemented. Complete profile management including avatar, password, and session control for all roles.

---

### Feature: Print Views with QR Codes for 8 Entity Types

- Status: Implemented
- Description: Server-rendered A4 print views (print.css, no JavaScript dependency) for 8 entity types: Competition (single + list), Horse (single + list), Rider (single), Club (single + list), Payment (single + list + selected), Signup sheet (per competition), Standings (per rider, per horse, per rider-horse pair), Payment list. Each print view includes branded header (brand + entity title + Jalali date), QR code, and footer (page number + federation name). QR codes are auth-gated.
- Notes: Print header includes Shamsi date. QR encodes current panel URL + query state. Print styles optimized for A4 portrait with 12mm margins. See Technical §27, User Usage §13. The SPA grids and detail pages have their own print path: a header print button on every page (`pageHead()`), `UI.preparePrint()` injecting a federation letterhead with the document title, active filter chips and row count, and an `@media print` block in `app.css` that repeats table headers across pages, zebra-strips rows, avoids breaking records and hides the app chrome.
- Considerations: QR codes require authenticated session (not usable by unauthenticated visitors). Print views are desktop-oriented but accessible on mobile.
- Status Description: Fully implemented. Print views and QR codes available for all 8 entity types with A4 formatting and authenticated QR security.

---

### Feature: QR Code Generation and Sharing

- Status: Implemented
- Description: QR codes generated client-side via qrcode.js and server-side via /panel/qr?data=... (cached under cache/). QR appears on printed competition papers, signup sheets, report pages, and entity headers. QR encodes authenticated panel URL + query state. Server endpoint cached with file-based storage.
- Notes: Client-side QR for instant display on pages. Server-side QR via GET /panel/qr (Admin only, auth required). See Technical §27, User Usage §13.4.
- Considerations: QR codes are auth-gated for data protection. Enable QR endpoint in settings if needed.
- Status Description: Fully implemented. QR codes generated both client-side and server-side, cached for performance, with auth-gated access.

---

### Feature: Captcha and CSRF Protection

- Status: Implemented
- Description: Server-generated captcha via GD with ambiguous glyph exclusion, signed token in session (TTL 3 minutes, single-use). Applied to login, signup, OTP request, password reset request. CSRF protection: 32 random bytes hex-encoded, stored in session payload, echoed in every JSON envelope and HTML form, verified with hash_equals, rotated on login/logout/privilege change/impersonation.
- Notes: Captcha character set excludes ambiguous glyphs. CSRF token included in envelope csrf field and hidden form input _csrf. Sent via X-CSRF-Token header for AJAX. See Technical §14, §11.5.
- Considerations: Captcha is configurable per route via settings (auth.captcha_on_login, auth.captcha_on_otp_request).
- Status Description: Fully implemented. Both captcha and CSRF protection are complete with proper rotation and verification.

---

### Feature: Maintenance Mode and Security Headers

- Status: Implemented
- Description: Maintenance mode (app.maintenance=1) returns 423 for all panel routes except /install, /payment/callback, /auth/logout. Security headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP (configurable), HSTS when HTTPS. Uploads directory protected via .htaccess (disables PHP execution). Sensitive directories protected via .htaccess + Nginx sample config.
- Notes: Payment callbacks remain accessible during maintenance (ZarinPal needs to reach the server). Maintenance page shown for panel access. See Technical §29, §34.2-34.3.
- Considerations: HSTS only applied when HTTPS is configured. CSP is configurable via settings.
- Status Description: Fully implemented. Maintenance mode and security headers protect the application during maintenance and against common web vulnerabilities.

---

### Feature: File Uploads with Security Controls

- Status: Implemented
- Description: Media upload system with MIME type validation, UUID filename replacement, server-side MIME sniffing, extension coercion from MIME, SVG sanitization via whitelist, .htaccess protection in uploads/ (no PHP execution), per-kind size limits (image 10MB, doc 25MB), per-user quota (500MB), horse gallery max 5 images. Avatars, horse images, club media, documents all supported.
- Notes: The horse gallery is a real gallery on the horse page, not a strip of thumbnails: `galleryHtml()` in `pages-horses.js` renders responsive tiles, marks the first as "تصویر اصلی" (cover), numbers the rest, hides the add tile at the `horses.max_images` (5) cap, reports an "n of 5" counter, and opens a keyboard-navigable lightbox (Esc / ← / →, backdrop click). Multi-file selection uploads sequentially and reports how many succeeded, so a partial failure still refreshes the grid.
- Notes: The horse grid also filters by gender, race, colour, owner, microchip and page size — the list endpoint supported all of them, the grid simply never exposed them. Because `horses.gender|race|color` store the *label* while `horse_genders` / `horse_races` / `horse_colors` are id-keyed, `HorseService::list()` resolves a numeric filter value to its label before comparing; sending the lookup id used to match zero rows.
- Notes: Original filename stored as metadata only. Downloads served via authenticated routes (no direct path leakage). See Technical §21, Blueprint §19.
- Considerations: Max dimension configurable (default 2560). Quality configurable (default 82). Format: original or webp. GD first, Imagick if present.
- Status Description: Fully implemented. Complete media upload system with MIME validation, UUID renaming, size limits, and PHP execution prevention.

---

### Feature: Persian Localization (fa-IR) with Jalali Calendar

- Status: Implemented
- Description: Full Persian language interface (fa-IR primary, en-US secondary), RTL layout using CSS logical properties, Jalali (Shamsi) calendar for all date inputs and displays via morilog/jalali, Persian digit formatting (۰۱۲۳۴۵۶۷۸۹), Toman currency display (تومان suffix), Tehran timezone (Asia/Tehran), Saturday week start, Friday weekend, Vazirmatn self-hosted font. Culture system supports adding languages via cultures table and JSON files.
- Notes: Date filters in reports auto-convert Shamsi input to UTC SQL ranges. Both Persian and Latin digits accepted on input and normalized. Week start: Saturday. Weekend: Friday. See Technical §15, User Usage §4, cultures/fa-IR.json.
- Considerations: Persian and Latin digit normalization happens before validation. All user-facing strings translated in fa-IR. en-US available as secondary culture.
- Status Description: Fully implemented. Complete Persian localization with Jalali dates, Persian digits, Toman currency, RTL layout, and Tehran timezone matching Iranian standards.

---

### Feature: Impersonation and Audit Trail

- Status: Implemented
- Description: Admin impersonation of any user with persistent yellow banner. All writes during impersonation tagged with impersonated_by. Impersonated users cannot change passwords, initiate transfers, delete accounts, access settings, or revoke sessions. Audit log viewer with filters (actor, action, entity, date range, result) showing timestamp, actor, action, target, result, details.
- Notes: Session rotation on impersonation start/end. CSRF rotation on impersonation change. Audit entries include diff and result. See Technical §11.8-11.11, §17.11, User Usage §7.22.
- Considerations: Audit log retention: 180 days in logs.sqlite.audit_logs. Raw JSON-lines also in logs/audit/YYYY-MM/.
- Status Description: Fully implemented. Admin impersonation with full tagging and audit trail for accountability.

---

### Feature: Horse Shares Inbox

- Status: Implemented
- Description: Riders can view horses shared to them by other owners at /panel/horse-shares. Each shared horse shows owner name with accept/reject actions. Share status tracked: pending, accepted, rejected, revoked. Response timestamp recorded. See User Usage §9.3, routes /panel/horse-shares and /panel/horse-shares/{id}/accept|reject.
- Notes: Shares are per (owner, horse, recipient). Removing a share deactivates the row (no hard delete). See Technical §17.7.
- Considerations: Shared horses are accessible to the recipient rider for signup purposes.
- Status Description: Fully implemented. Riders receive and respond to horse share requests through a dedicated inbox page.

---

### Feature: Club Bans and Rider Bans

- Status: Implemented
- Description: Club bans: Club owners can ban riders or horses from selecting their club as affiliation (forward-looking). Admin/Manager bans: global (rider-wide), competition-level, or rade-level. Priority: Rider > Competition > Rade. Ban reasons recorded. Expiry dates supported. Ban management on Club detail page (Bans tab). See User Usage §10.5, §7.5, routes /panel/clubs/{id}/bans.
- Notes: Bans are forward-looking only. A ban on a rider applies everywhere. A ban on a competition applies to all rades of that competition. See Technical §17.8, Blueprint §12.
- Considerations: Club bans are managed by the club owner. Admin/Manager bans override club-level decisions.
- Status Description: Fully implemented. Complete ban system at club, competition, and rade levels with priority resolution.

---

### Feature: Dashboard KPIs and Charts per Role

- Status: Implemented
- Description: Role-aware dashboards: Admin sees 6 KPI cards (total riders, total horses, total clubs, active competitions, revenue this month, pending verifications) plus charts (signups/revenue over 30 days, rade popularity 90 days, recent changelog, quick actions). Manager dashboard with 6 different KPIs (pending riders, pending signups, today's competitions, revenue, today's signups, pending payments) plus charts. Rider dashboard with 4 KPIs (my horses, my signups, my wins, upcoming competitions) plus horse list and upcoming competitions. Club dashboard with 4 KPIs (competitions at venue, affiliated riders, revenue from venue, active bans).
- Notes: KPIs are implemented in KpiService. Dashboard data loaded via /panel/dashboard/data JSON endpoint. See User Usage §7.1, §8, §9.1, §10.1, Technical §13.3.
- Considerations: Each role sees different KPIs and layout on the same /panel route.
- Status Description: Fully implemented. All four roles have distinct dashboards with KPIs, charts, and quick actions.

---

### Feature: Payment Order Lifecycle Management

- Status: Implemented
- Description: Complete payment order tracking: list with filters (competition, rider, status, date range, ref ID), bulk operations (mark pending refund, mark refunded, unmark refund, export CSV), individual view, verify order (manual), print order, reconciliation (upload ZarinPal CSV, compare orders, flag mismatches). Payment order statuses: pending, paid, failed, pending_refund, refunded.
- Notes: Orders permanently recorded. Refunds are manual two-step process. Reconciliation compares local orders by authority/ref_id against ZarinPal report. See Technical §19.5-19.7, User Usage §7.16.
- Considerations: Manual refund requires two actions: mark pending refund, then mark refunded. Toggle back supported. Authority is unique when not null.
- Status Description: Fully implemented. Payment order tracking with verification, refund workflow, reconciliation, and print capabilities.

---

### Feature: Capacity Enforcement and Price Snapshots

- Status: Implemented
- Description: Rade capacity enforcement: signup blocked if capacity reached (excluding cancelled/withdrawn). Capacity reduction below current count blocked. Price snapshots on signup creation: payment_id_snapshot, payment_name_snapshot, payment_amount_irt_snapshot — immutable after creation. Reports use snapshot columns, not live payments table. Payment template changes affect future signups only.
- Notes: See Technical §17.2-17.4. Price snapshots protect historical report accuracy even if payment amounts change.
- Considerations: Price snapshots are critical for financial reporting integrity. Changing payment amounts is non-destructive to existing signups.
- Status Description: Fully implemented. Capacity enforcement prevents over-subscription and price snapshots preserve historical accuracy.

---

### Feature: SMS Service Infrastructure (Requires MelyPayamak Activation)

- Status: Partially Implemented
- Description: SMS infrastructure is fully coded: SmsService with MelyPayamak API client (SendSMS, SendSMSWithPattern), OTP request/verify lifecycle, notification SMS with per-type toggles (sms.notify_on_*), rate limiting, SMS logging, phone number formatting. However, SMS is disabled by default and requires Admin to configure MelyPayamak credentials (username, password, sender number, OTP pattern) in settings. Without valid credentials and credits, no SMS functionality is active.
- Notes: MelyPayamak is Iran's domestic SMS platform. OTP codes stored hashed. SMS failures logged but non-blocking. See Technical §20, SmsService.php.
- Considerations: This is partially implemented because the code is complete but the feature cannot function without external MelyPayamak credentials and credits, which must be purchased and configured by an Admin.
- Status Description: Partially implemented. Code infrastructure is complete and production-ready, but functional only after MelyPayamak credentials are configured and SMS is enabled by an Admin.

---

### Feature: Payment Order Reconciliation (Manual CSV Upload)

- Status: Partially Implemented
- Description: Reconciliation feature allows admins to compare local payment orders against ZarinPal transaction reports. Currently supports only manual CSV upload of ZarinPal reports, with mismatch flagging. Automated daily sync or API-based reconciliation with ZarinPal is not implemented. Manual reconciliation is required after each settlement period.
- Notes: Page: /panel/payment-orders/reconciliation. CSV upload compares authority and ref_id fields. Flags mismatches for review. See Technical §19.7.
- Considerations: Manual CSV upload requires admin effort after each ZarinPal settlement cycle. Automated reconciliation would reduce manual work significantly.
- Status Description: Partially implemented. Manual CSV upload reconciliation works, but automated reconciliation with ZarinPal's API or scheduled sync is not available.

---

<!-- ============ NOT IMPLEMENTED FEATURES ============ -->

### Feature: Competition Calendar View

- Status: Implemented
- Description: Visual calendar display (monthly/weekly) showing all upcoming competitions with their dates, venues, and statuses. Admin/Manager can click a date to see competition details or create a new competition for that date. Specified in the User Usage spec (§7.12 header action "Calendar view").
- Notes: Implemented as `GET /panel/competitions/calendar` (month grid, venue/status filters, month navigation, deadline countdowns) rendered by the `/calendar` SPA route.
- Considerations: Especially useful in Iran where competitions follow Jalali calendar dates and seasonal schedules (indoor winter, outdoor spring/summer).
- Status Description: Fully implemented. Month grid with Jalali dates, venue and status filters, month navigation and registration-deadline countdowns.

---

### Feature: Global Entity Search

- Status: Implemented
- Description: Unified search across all entities — competitions, riders, horses, clubs, signups — by name, phone, microchip, or other identifiers, reachable from the topbar (spec §5.3 "Global search (spotlight)").
- Notes: `GET /api/search?q=` (`SearchController`) returns grouped results for the five entity types, each capped at 10 rows. The topbar's `Ctrl+K` command palette (`#cmdk`) queries it live and deep-links every hit into the matching detail page; `/search` renders the same groups as a full page. The query must be at least 3 characters, and matching uses an anchored prefix (`term%`, not `%term%`) so the scan stays index-friendly; microchip numbers keep a contains match because operators search by trailing digits. No FTS5 index — plain `LIKE` queries remain fast enough at this data size (audit finding #10).
- Considerations: A global search dramatically improves usability for admins and managers who work across all entity types daily.
- Status Description: Fully implemented as a `Ctrl+K` spotlight over `/api/search`, with a full `/search` page behind the topbar's advanced-search button.

---

### Feature: PDF Invoice Generation for Payments

- Status: Not Implemented
- Description: Generate formal PDF invoices for ZarinPal payment transactions. In Iran, businesses need PDF invoices for tax reporting and financial record-keeping. Each invoice would include: order ID, rider info, competition details, amount in Toman, payment date, ZarinPal authority and ref ID, and federation details. Currently the panel offers HTML print views and CSV exports.
- Notes: Would require a PDF generation library (TCPDF or mPDF, both PHP-native). Could use a simple HTML-to-PDF approach with wkhtmltopdf if available on the server.
- Considerations: PDF invoices are standard in Iranian business practice. This would replace manual invoice creation and improve financial tracking for the federation.
- Status Description: Not implemented. Currently only HTML print views and CSV exports are available. PDF invoice generation is needed for Iranian tax and accounting requirements.

---

### Feature: OpenStreetMap Venue Location Display

- Status: Not Implemented
- Description: Display competition venue locations on OpenStreetMap (free, open-source, accessible in Iran). Each venue club would have GPS coordinates (latitude, longitude) stored, shown on a map on the club detail page and competition detail page. Google Maps is blocked/unreliable in Iran, making OpenStreetMap the only viable web mapping option.
- Notes: Would need to add lat/lng columns to clubs table, add OSM embed or Leaflet.js integration (vendored). OSM tiles are accessible from Iran.
- Considerations: Iranian cities in Golestan province and other regions would benefit from visible venue locations. Especially useful for riders traveling to competitions.
- Status Description: Not implemented. No GPS coordinate fields, map views, or mapping libraries exist. OpenStreetMap is the natural choice for Iran where Google Maps is blocked.

---

### Feature: Automatic Scheduled Backups

- Status: Implemented
- Description: Configurable automatic backup scheduling (daily, weekly, monthly) instead of a manual-only backup system. Admins set a backup frequency and optional retention count; backups are created automatically and listed on the backups page with creation time and size.
- Notes: Implemented by `SchedulerService` (settings `scheduler.auto_backup`, `scheduler.backup_keep`), driven by `cron.php` or `POST /panel/cron?key=…`. Jobs are idempotent per day, so running the scheduler frequently is safe.
- Considerations: Automatic backups are critical for data safety. Request-triggered scheduling is the only viable approach in this architecture (no cron, no daemon).
- Status Description: Fully implemented. `scheduler.auto_backup` + `scheduler.backup_keep` drive `SchedulerService::autoBackup()`, run by `cron.php` or `POST /panel/cron`; manual backups from `/panel/backups` still work alongside it.

---

### Feature: Rider Cumulative Performance Ranking

- Status: Implemented
- Description: Cross-competition ranking system for riders based on cumulative results (wins, placements, points). Riders see their ranking in the federation standings, updated as results are published.
- Notes: Implemented as `KpiService::riderRanking()` served by `GET /panel/standings/ranking` (JSON + CSV) and the `/ranking` page. Points: 1st = 10, 2nd = 6, 3rd = 4, other placements = 2.
- Considerations: Rider rankings are a key motivator in equestrian sports. This would create federation-wide competition beyond individual events.
- Status Description: Fully implemented. `/panel/standings/ranking` aggregates published results across every competition (1st = 10, 2nd = 6, 3rd = 4, other placements = 2 points) and is exported as JSON or CSV from the `/ranking` page.

---

### Feature: Server-Side Grid Ordering and Pagination

- Status: Implemented
- Description: Every grid whose result set can outgrow one screen orders and pages **in SQL**, not in the browser. `GET /panel/signups` and `GET /panel/standings` accept `sort`, `dir` (asc|desc), `page` and `per_page`, and both return the `{rows, total, page, per_page}` envelope the SPA pager needs.
- Notes: `SignupService::list()` whitelists sort keys through `SignupService::SORTABLE`; `ResultService::standings()` paginates (default 50, capped at 200) through `ResultService::STANDINGS_SORTABLE`. Both resolve to a fixed `ORDER BY <column> <dir>, s.id <dir>` — an unknown key falls back to the default column and a client-side sort of the loaded page is impossible, so a pager and a sort header can never disagree. `ResultController::printStandings` previously returned a flat array of every confirmed signup in the province in one response; it now returns the paginated envelope. The SPA flattens its nested `filters.sort={key,dir}` state into the two query params through `apiParams()` in `pages-events.js`.
- Considerations: The SPA previously sorted only the rows already on screen, which silently reordered one page of a 6,000-row grid while the pager still claimed to be showing the first 50.

---

### Feature: In-Page Competition Signups Grouped by Rade

- Status: Implemented
- Description: Staff manage a competition's entries on the competition page itself, grouped by "rade" with a per-rade capacity bar, signup count and remaining places, plus a toggle to a single flat sortable list. The header link to `/signups?competition_id=…` is gone (one "full signups page" link remains inside the section).
- Notes: `compSignupsSection()` in `pages-events.js` fetches `GET /panel/signups?competition_id=…&per_page=250`, groups by `rade_id`, renders one collapsible `<details class="rade-acc">` per rade in `competition_rades.sort_order`, and collects rows whose rade is not in the list under a "سایر رده‌ها" group so nothing disappears. Approve / reject / position actions stay in place and refresh both the section and the per-rade counts. View state (`?su_view=&su_status=&su_q=`) lives in the URL so a filtered view can be shared.
- Considerations: Grouped-per-rade is the jury-sheet view; the flat list stays available for bulk administration.

---

### Feature: Share Codes Are Secrets

- Status: Implemented
- Description: A horse `share_code` and a rider `my_share_code` authorise handing a horse to somebody else. They are masked everywhere and never reach paper, a spreadsheet or a copied DOM.
- Notes: `UI.secret()` renders the value blurred behind a reveal + copy control, so the digits are not written into the document until a user asks for them. `.secret` is `display:none` under `@media print` (a print stylesheet that only greys the code still prints it). Server-side, `ReportEngine::isSecretColumn()` redacts any share-code column from `toCsv()` and is reused by `HorseService::exportCsv()`, which is already a fixed whitelist that never carried the column.
- Considerations: A share code is a bearer token for ownership transfer; a CSV is forwarded by email far more freely than the panel is.

---

### Feature: Lightweight Rich Content Editor

- Status: Implemented
- Description: Competition description / rules / announcement are authored as rich content by non-technical staff: headings, lists, links, quotes and drag-and-drop images.
- Notes: Quill 2.0.3 is vendored as a plain file at `public/views/assets/vendor/quill.js` (UMD, BSD-3-Clause) and loaded lazily by `ensureQuill()`, so pages with no editor never download it. Images dropped on, pasted into, or picked for the field upload to `POST /panel/media` (staff-only, images only, else the file is deleted and the request rejected) and are embedded as `/media/{id}` URLs, keeping uploads outside the web root and served through the authenticated media route. Storage uses Quill's `getSemanticHTML()`, so no `ql-*` classes reach the database, and the whitelist stays aligned on both ends (`HtmlSanitizer::ALLOWED_TAGS` server-side, `RICH_TAGS` in `richHtml()`). Quill's CSS is not vendored — `quill.snow.css` is LTR-first — so a small RTL theme under `.ql-` selectors applies the panel tokens. The previous `contenteditable` editor remains as a fallback if the vendor file is ever missing.
- Toolset: undo/redo · paragraph and three heading levels · bold, italic, underline, strikethrough, subscript, superscript, inline code · blockquote, code block · bullet and numbered lists with indent/outdent · divider · link · image · clear formatting · HTML source view · an in-editor Persian help modal · live word/character count. Every control carries a Persian tooltip and Quill's own tooltip module is switched off (`tooltip:false`) so they are not overwritten with English labels.
- Deliberately **not** offered: font colour, size, alignment, font family, text direction, checklists, video and embedded tables. Those all serialise to inline `style`/`class`/`data-*` attributes that `HtmlSanitizer` strips, so the button would appear to work and the formatting would vanish on save — and the public page would look inconsistent. The toolbar only offers what survives the whitelist.
- Considerations: CKEditor-class editors are far heavier and would need a build step, which this project forbids.

---

### Feature: Vendored Front-end Fonts

- Status: Implemented
- Description: The panel and the public competition page render in Vazirmatn (variable, SIL OFL 1.1), served from `public/views/assets/fonts/` as two subset woff2 files (~80 KB together).
- Notes: Both `app.css` and `public.css` had always asked for `Vazirmatn` in their font stacks while nothing declared it, so every screen fell back to Tahoma. The Arabic subset is preloaded by `public/views/index.html` and `layout()`; both `@font-face` rules use `font-display:swap` and a `unicode-range`, so the Latin file is fetched only when needed and text paints immediately.
- Considerations: Vendored rather than CDN-linked so a host with no outbound network still shows the intended typeface.

---

### Feature: Competition Banner Image

- Status: Implemented
- Description: A competition carries at most one wide hero image, shown on the panel competition page and as the hero of the public page.
- Notes: `competitions.banner_media_id` (migration `competitions.banner_media_id`, also in `schema.sql`). `CompetitionService::setBanner()` / `clearBanner()` are exposed as `POST` / `DELETE /panel/competitions/{id}/banner` and replace (and delete) the previous media row so re-saves never orphan uploads. `clone` deliberately does **not** copy the banner: the clone would otherwise share one media row with its source, and clearing one banner would delete the other's image. Because uploads live outside the web root and `GET /media/{id}` requires auth, the image is streamed to anonymous visitors by the dedicated guest route `GET /c/{slug}/banner`, which resolves only `competitions.banner_media_id` and re-checks the path stays under `uploads/`.
- Considerations: One image, landscape. Multi-image galleries belong to horses.

---

### Feature: Offline Results Entry Mode

- Status: Not Implemented
- Description: Allow judges or administrators to enter competition results on tablets or phones without internet connectivity, storing results locally in the browser (IndexedDB/LocalStorage), and syncing to the server when connectivity returns. Particularly valuable at outdoor competition venues in Golestan province where internet connectivity may be unreliable.
- Notes: Would need client-side storage, sync queue, conflict resolution, and a dedicated offline results entry form. Service worker could enable basic caching.
- Considerations: Iranian competition venues, especially in rural Golestan, often lack reliable internet. This feature enables result entry regardless of connectivity.
- Status Description: Not implemented. Results entry currently requires an active internet connection to the panel server. No offline capability exists for any feature.

---

### Feature: Competition Entry Deadline Alerts

- Status: Implemented
- Description: Automated alerts (in-panel notifications and optional SMS) sent when competition registration is approaching its deadline. Admins and managers receive reminders; riders are notified that registration is closing.
- Notes: Implemented by the `deadline_alerts` job in `SchedulerService` (window from `scheduler.deadline_alert_days`), surfaced on the dashboard "needs attention" queue (`KpiService::attention()`).
- Considerations: Registration deadlines are critical for competition organization. In Iran where planning can be last-minute, advance warnings help ensure full participation.
- Status Description: Fully implemented. `SchedulerService::deadlineAlerts()` (`scheduler.deadline_alert_days`) sends in-panel notifications once per day for competitions inside the window, and the dashboard "needs attention" queue lists them with their countdowns.

---

### Feature: Horse Health and Veterinary Records

- Status: Implemented
- Description: Track veterinary records, vaccination schedules, health certificates, and insurance documentation for each horse. Essential for federation compliance in Iran where equestrian sports require up-to-date health documentation. Fields include: vet visit date, diagnosis, treatment, next vaccination date, health certificate expiry, insurance policy number and expiry.
- Notes: Implemented with a `horse_health_records` table (created by `database/migrations.php`), `HorseHealthService`, `/panel/horses/{id}/health` routes, and a health section on the horse detail page.
- Considerations: Health certificates are mandatory for competition participation in Iran. This feature would help clubs and managers ensure horses are competition-ready.
- Status Description: Fully implemented. Records are created, listed and deleted from the health section of the horse detail page; nothing about the horse's health status is silently dropped.

---

### Feature: SMS Delivery Performance Report

- Status: Partially Implemented
- Description: Historical report of SMS delivery performance showing: total SMS sent, delivered, failed, and skipped over time. Cost tracking per message. Delivery rate per notification type. Per-recipient delivery status.
- Notes: Partially covered by the `/sms-log` page: delivery success rate, error breakdown, per-message status and CSV export of the delivery log. It is a dedicated page rather than a `ReportEngine` report type; per-recipient status and cost tracking are still missing.
- Considerations: SMS costs money via MelyPayamak credits. A delivery report helps admins control costs and understand notification effectiveness.
- Status Description: Partially implemented. Delivery rates and per-message errors are visible and exportable on `/sms-log`; per-recipient delivery status and credit-cost tracking are still missing.

---

### Feature: PDF Report Export

- Status: Not Implemented
- Description: Export any report as a formatted PDF document (currently only CSV and the browser's print-to-PDF are available). PDF would preserve the report layout with column headers, data rows, summary tiles, and report title. Useful for official federation submissions and meetings where printed reports are preferred.
- Notes: Would require a PHP PDF library (TCPDF, mPDF, or wkhtmltopdf integration). Could leverage the existing print.css styles for PDF formatting. See Technical §27 (Print & QR section).
- Considerations: Iranian federation meetings and regulatory submissions often require printed/PDF documents. PDF export bridges the gap between digital reports and physical documentation.
- Status Description: Not implemented. Reports export as CSV, or as a formatted A4 document through the browser's print dialog. A server-rendered PDF file is still missing for official documentation workflows.

---

### Feature: Multi-Day Competition Result Tracking

- Status: Not Implemented
- Description: Support for competitions spanning multiple days (e.g., 3-day show jumping events), with daily result tracking and display. Each day would have its own results section, and cumulative results would aggregate across all days. Currently competitions have a single start_at and end_at with results entered per-signup without day-level granularity.
- Notes: Would need a competition_days table or date_range breakdown, modified result entry UI (day selector), and cumulative standings calculation.
- Considerations: Many Iranian competitions span multiple days, especially province-level and federation championship events. Current single-day result tracking requires separate competitions per day.
- Status Description: Not implemented. Multi-day competitions require creating separate competitions per day, which complicates management and reporting.

---

### Feature: Mandatory Announcement with Read Receipt

- Status: Not Implemented
- Description: Critical announcements (rule changes, schedule changes, safety notices) that require explicit acknowledgment from recipients before they can continue using the panel. A badge counter shows unacknowledged mandatory announcements. Admins can see who has and hasn't acknowledged. Currently broadcast messages exist but have no acknowledgment requirement or tracking.
- Notes: Would need a mandatory_announcements table, acknowledgment tracking, and UI blocking mechanism (banner or modal until acknowledged).
- Considerations: In Iranian sports federations, mandatory rule acknowledgments are standard practice before competitions. This formalizes that process digitally.
- Status Description: Not implemented. Broadcast messages can be sent but recipients can ignore them. No acknowledgment tracking or enforcement exists.

---

### Feature: Bulk Competition Import from CSV

- Status: Not Implemented
- Description: Import multiple competitions from a CSV file with columns for title, venue club, dates, rades, payments, and capacities. Currently only horse import exists (POST /panel/horses/import). Bulk competition creation through the UI is limited to cloning one competition at a time.
- Notes: Would need a CSV parser, column mapping, validation, and import service. Similar pattern to existing horse import. See User Usage §7.12 (no import action on competitions page).
- Considerations: Federations often need to create many competitions at once (e.g., annual calendar import). CSV import would save significant admin time.
- Status Description: Not implemented. Competitions must be created individually or cloned one at a time. No CSV import functionality exists for competitions.

---

### Feature: Audit Log Export for Regulatory Compliance

- Status: Partially Implemented
- Description: Export audit logs in a standardized, portable format (PDF or CSV) for submission to federation authorities or regulatory bodies. Filters by date range, actor, action type, and result.
- Notes: `GET /panel/audit?format=csv` exports the currently filtered audit log (same filters as the grid) plus a print view. PDF output is still not generated server-side.
- Considerations: Iranian sports federations may be required to provide audit trails to governing bodies. Export capability ensures compliance with regulatory requirements.
- Status Description: Partially implemented. `/panel/audit` exports the currently filtered log as CSV and has a print view; a server-rendered PDF is still missing (see "PDF Report Export").

---

### Feature: Automatic Rider Verification After Competition

- Status: Implemented
- Description: Automatically verify riders who have successfully completed at least one competition (signed up, paid, participated), so a rider does not have to wait for the time-based rule after their first event.
- Notes: `SchedulerService::autoVerifyRiders()` (`scheduler.auto_verify_on_participation`) promotes pending riders who already hold a confirmed signup, alongside the existing 48-hour and manual verification paths.
- Considerations: This rewards active riders by fast-tracking verification and reduces Manager verification workload. New riders can still be pending before their first competition.
- Status Description: Fully implemented as a scheduler job, so verification happens without a Manager's intervention.

---

### Feature: Venue GPS Coordinates and Distance Calculator

- Status: Not Implemented
- Description: Store GPS coordinates (latitude, longitude) for each venue club. Calculate and display distances between clubs for riders planning travel. Useful in Golestan province where clubs may be spread across rural areas with varying distances between venues.
- Notes: Would need lat/lng columns on clubs table, distance calculation service, and a map or distance display UI. OpenStreetMap integration (see separate feature) would complement this.
- Considerations: Iranian equestrian clubs in provinces like Golestan, Kordestan, and Azerbaijan are geographically dispersed. Distance information helps riders plan travel.
- Status Description: Not implemented. No GPS coordinates or distance calculations exist for venues or clubs.

---

### Feature: Kurdish (Sorani) Language Interface

- Status: Not Implemented
- Description: Kurdish (Sorani) language interface for Iran's Kurdish population in western provinces (Kordestan, Kermanshah, West Azerbaijan). Kurdish is the second most spoken language in Iran after Persian. Adding Kurdish language support would make the panel accessible to a significant population that may not be comfortable reading Persian technical interfaces.
- Notes: Would need a new culture file (ku-IR.json) with Kurdish translations, add it to cultures table, and ensure RTL handling for Kurdish Latin script.
- Considerations: Iran's constitution recognizes Kurdish as a regional language. Providing Kurdish support promotes inclusivity in western Iranian provinces where horse riding is popular.
- Status Description: Not implemented. Only fa-IR and en-US cultures exist. Kurdish, Balochi, and other Iranian languages are not supported.

---

### Feature: Registration Countdown Timer

- Status: Implemented
- Description: Visual countdown timer on competition pages showing days, hours, and minutes remaining until registration closes. Visible to staff in the panel and to the public on the competition's shareable page. When registration closes, the timer displays "Registration Closed".
- Notes: Implemented as `UI.countdown()` (dependency-free interval timer, Tehran time, fa-IR digits) on the competition detail page, the calendar cells and the attention queue.
- Considerations: Registration deadlines are critical for riders to plan their participation. A visible countdown creates urgency and reduces missed deadlines.
- Status Description: Fully implemented. `UI.countdown()` ticks in Tehran time with fa-IR digits on the panel competition page, the calendar cells, the attention queue and the public competition page (where it is server-rendered so it is correct before JavaScript runs).

---

### Feature: Certificate and Document Generation

- Status: Not Implemented
- Description: Generate participation certificates, awards, rankings, and competition completion documents as formatted PDFs for individual riders or clubs. Templates would include: federation logo, rider name, competition name, date, result/placement, and signature fields for federation officials. Common in Iranian equestrian federations where physical certificates are distributed at award ceremonies.
- Notes: Would need a certificate template system (HTML-to-PDF with editable fields), a certificate generation controller, and template management in settings. Could reuse the print infrastructure (print.css, A4 formatting).
- Considerations: Iranian federations traditionally award physical certificates at closing ceremonies. A digital generation system with print capability streamlines this process. Rider-specific data (name, competition, result) would merge into templates.
- Status Description: Not implemented. No certificate, award, or document generation capability exists. All certificates must be created manually outside the panel.

---

<!-- ============ FEATURE COUNT SUMMARY ============ -->
<!-- Implemented: 40 | Partially Implemented: 4 | Not Implemented: 10 | Total: 54 -->
