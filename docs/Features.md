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
- Description: All frontend and backend libraries (Alpine.js 3, AG Grid Community, SheetJS, qrcode.js, Vazirmatn font, Tailwind CSS, morilog/jalali, libphonenumber, HTMLPurifier, Intervention Image, Parsedown) are vendored as plain static files under vendor/ and public/assets/js/vendor/. No CDN, no npm registry, no composer packagist access is needed at runtime.
- Notes: Tailwind CSS is built once during development and committed; no build step at runtime. No Node.js or Composer required on the production server. See Technical §2.2, §33.
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
- Description: Unified report engine with 8 presets (signups, revenue, results, horses, riders, clubs, payments, bans). AG Grid with server-side row model, whitelist-driven SQL queries (no raw user input in SQL), Shamsi date filter conversion, column visibility/order/filter/sort persisted in localStorage per user per report, XLSX and CSV export via signed URLs (1-hour expiry), report sharing (live filter state sharing between users), and summary KPI tiles per report.
- Notes: Role scoping applied transparently. Export: XLSX via SheetJS on frontend, CSV server-generated. Signed URL: HMAC-signed, expires in 1 hour. See Technical §18, User Usage §12.
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
- Notes: Print header includes Shamsi date. QR encodes current panel URL + query state. Print styles optimized for A4 portrait with 12mm margins. See Technical §27, User Usage §13.
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

- Status: Not Implemented
- Description: Visual calendar display (monthly/weekly) showing all upcoming competitions with their dates, venues, and statuses. Admin/Manager can click a date to see competition details or create a new competition for that date. This feature was specified in the User Usage spec (§7.12 header action "Calendar view") but no route, controller, view, or JavaScript component exists.
- Notes: Would need a new controller method, route, and calendar component (Alpine.js or vanilla JS calendar). Could integrate with Jalali calendar rendering.
- Considerations: Especially useful in Iran where competitions follow Jalali calendar dates and seasonal schedules (indoor winter, outdoor spring/summer).
- Status Description: Not implemented. No route, controller, view, or calendar component exists despite being specified in the User Usage document as a header action on the competitions page.

---

### Feature: Global Entity Search

- Status: Not Implemented
- Description: Unified search bar in the topbar for searching across all entities — competitions, riders, horses, clubs, signups — by name, phone, microchip, or other identifiers. Currently the topbar describes a "Global search (spotlight)" in the spec (§5.3) but no search route, controller, or view exists. Users must navigate to individual entity pages to use their specific search filters.
- Notes: Would need a unified search controller, route, and search index. Could use SQLite FTS5 for performance.
- Considerations: A global search would dramatically improve usability for admins and managers who work across all entity types daily.
- Status Description: Not implemented. The topbar spec mentions global search but no search endpoint, controller, or view exists. Users must use entity-specific filters.

---

### Feature: PDF Invoice Generation for Payments

- Status: Not Implemented
- Description: Generate formal PDF invoices for ZarinPal payment transactions. In Iran, businesses need PDF invoices for tax reporting and financial record-keeping. Each invoice would include: order ID, rider info, competition details, amount in Toman, payment date, ZarinPal authority and ref ID, and federation details. Currently only HTML print views and XLSX/CSV exports exist.
- Notes: Would require a PDF generation library (TCPDF or mPDF, both PHP-native). Could use a simple HTML-to-PDF approach with wkhtmltopdf if available on the server.
- Considerations: PDF invoices are standard in Iranian business practice. This would replace manual invoice creation and improve financial tracking for the federation.
- Status Description: Not implemented. Currently only HTML print views and XLSX/CSV exports are available. PDF invoice generation is needed for Iranian tax and accounting requirements.

---

### Feature: OpenStreetMap Venue Location Display

- Status: Not Implemented
- Description: Display competition venue locations on OpenStreetMap (free, open-source, accessible in Iran). Each venue club would have GPS coordinates (latitude, longitude) stored, shown on a map on the club detail page and competition detail page. Google Maps is blocked/unreliable in Iran, making OpenStreetMap the only viable web mapping option.
- Notes: Would need to add lat/lng columns to clubs table, add OSM embed or Leaflet.js integration (vendored). OSM tiles are accessible from Iran.
- Considerations: Iranian cities in Golestan province and other regions would benefit from visible venue locations. Especially useful for riders traveling to competitions.
- Status Description: Not implemented. No GPS coordinate fields, map views, or mapping libraries exist. OpenStreetMap is the natural choice for Iran where Google Maps is blocked.

---

### Feature: Automatic Scheduled Backups

- Status: Not Implemented
- Description: Configurable automatic backup scheduling (daily, weekly, monthly) instead of the current manual-only backup system. Admins would set a backup frequency and optional retention count. Backups would be created automatically at scheduled times and listed on the backups page with creation time and size. Currently all backups require manual Admin action via /panel/backups.
- Notes: Since no cron or daemon is allowed per spec, this would use request-triggered scheduling (1-in-N requests triggers a check and creates backup if due). See Technical §3 (No cron, no queue, no daemon).
- Considerations: Automatic backups are critical for data safety. Request-triggered scheduling is the only viable approach in this architecture (no cron, no daemon).
- Status Description: Not implemented. All backups are currently manual. No scheduling mechanism exists for automatic backup creation.

---

### Feature: Rider Cumulative Performance Ranking

- Status: Not Implemented
- Description: Cross-competition ranking system for riders based on cumulative results (wins, placements, points). Riders would see their ranking in the federation standings, updated in real-time as results are published. Ranking could be by total wins, average position, or a custom points system. Currently results are only viewable per-competition with no federation-wide ranking.
- Notes: Would need a ranking service computing scores from confirmed/published results. Could be cached with invalidation on result changes. See User Usage §9.1 (my wins shown as KPI but no ranking).
- Considerations: Rider rankings are a key motivator in equestrian sports. This would create federation-wide competition beyond individual events.
- Status Description: Not implemented. Results exist only at the individual competition level. No cross-competition ranking or federation standings system exists.

---

### Feature: Offline Results Entry Mode

- Status: Not Implemented
- Description: Allow judges or administrators to enter competition results on tablets or phones without internet connectivity, storing results locally in the browser (IndexedDB/LocalStorage), and syncing to the server when connectivity returns. Particularly valuable at outdoor competition venues in Golestan province where internet connectivity may be unreliable.
- Notes: Would need client-side storage, sync queue, conflict resolution, and a dedicated offline results entry form. Service worker could enable basic caching.
- Considerations: Iranian competition venues, especially in rural Golestan, often lack reliable internet. This feature enables result entry regardless of connectivity.
- Status Description: Not implemented. Results entry currently requires an active internet connection to the panel server. No offline capability exists for any feature.

---

### Feature: Competition Entry Deadline Alerts

- Status: Not Implemented
- Description: Automated alerts (in-panel notifications and optional SMS) sent when competition registration is approaching its deadline — for example, 48 hours before, 24 hours before, and 1 hour before. Admins and managers would receive reminders; riders would be notified that registration is closing. Currently no deadline tracking or automated alerting exists.
- Notes: Would need a deadline check mechanism (request-triggered: check on each panel visit if a deadline is within alert window). Could add settings for alert timing intervals.
- Considerations: Registration deadlines are critical for competition organization. In Iran where planning can be last-minute, advance warnings help ensure full participation.
- Status Description: Not implemented. Registration deadlines are visible on competition pages but no automated alerts or countdown timers exist.

---

### Feature: Horse Health and Veterinary Records

- Status: Not Implemented
- Description: Track veterinary records, vaccination schedules, health certificates, and insurance documentation for each horse. Essential for federation compliance in Iran where equestrian sports require up-to-date health documentation. Fields would include: vet visit date, diagnosis, treatment, next vaccination date, health certificate expiry, insurance policy number and expiry.
- Notes: Would need new tables (horse_health_records, horse_documents), views, and controller endpoints. Could leverage the existing media upload system for document attachments.
- Considerations: Health certificates are mandatory for competition participation in Iran. This feature would help clubs and managers ensure horses are competition-ready.
- Status Description: Not implemented. No health, veterinary, or insurance tracking features exist for horses despite being required for federation compliance.

---

### Feature: SMS Delivery Performance Report

- Status: Not Implemented
- Description: Historical report of SMS delivery performance showing: total SMS sent, delivered, failed, and skipped over time. Cost tracking per message. Delivery rate per notification type. Per-recipient delivery status. Currently SMS logs exist in logs.sqlite.sms_logs but no reporting or analytics interface exists for SMS performance.
- Notes: Would need a report type for SMS, aggregation queries on logs.sqlite.sms_logs, and a view with date filters and status breakdown. See Technical §23.4 (SMS retention: 90 days).
- Considerations: SMS costs money via MelyPayamak credits. A delivery report helps admins control costs and understand notification effectiveness.
- Status Description: Not implemented. SMS logs are stored but no reporting interface exists. Admins cannot review SMS delivery rates or costs.

---

### Feature: PDF Report Export

- Status: Not Implemented
- Description: Export any report as a formatted PDF document (currently only XLSX and CSV are available). PDF would preserve the report layout with column headers, data rows, summary tiles, and report title. Useful for official federation submissions and meetings where printed reports are preferred.
- Notes: Would require a PHP PDF library (TCPDF, mPDF, or wkhtmltopdf integration). Could leverage the existing print.css styles for PDF formatting. See Technical §27 (Print & QR section).
- Considerations: Iranian federation meetings and regulatory submissions often require printed/PDF documents. PDF export bridges the gap between digital reports and physical documentation.
- Status Description: Not implemented. Reports support XLSX and CSV export only. PDF export is needed for official documentation workflows.

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

- Status: Not Implemented
- Description: Export audit logs in a standardized, portable format (PDF or CSV) for submission to federation authorities or regulatory bodies. Filters by date range, actor, action type, and result. Currently audit logs are viewable in the panel (/panel/audit) but no export functionality exists.
- Notes: Would add export button to the audit page, generating CSV or PDF from logs.sqlite.audit_logs. Could support date range filters and format selection.
- Considerations: Iranian sports federations may be required to provide audit trails to governing bodies. Export capability ensures compliance with regulatory requirements.
- Status Description: Not implemented. Audit logs are viewable but cannot be exported. No portable audit trail format exists for regulatory submission.

---

### Feature: Automatic Rider Verification After Competition

- Status: Not Implemented
- Description: Automatically verify riders who have successfully completed at least one competition (signed up, paid, participated). Currently pending riders must wait 48 hours for auto-verification or be manually verified by a Manager. After a rider participates in a competition, their verification status could be automatically upgraded to verified.
- Notes: Would need a service method triggered after competition results are confirmed or after signup completion. Could be configured as a setting (auto_verify_on_participation).
- Considerations: This rewards active riders by fast-tracking verification and reduces Manager verification workload. New riders could still be pending for first competition.
- Status Description: Not implemented. Rider verification is time-based (48 hours) or manual only. No event-driven verification from competition participation exists.

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

- Status: Not Implemented
- Description: Visual countdown timer on competition detail pages showing days, hours, and minutes remaining until registration closes. Visible to all authenticated users (riders, managers, admins). Timer updates in real-time via Alpine.js. When registration closes, timer displays "Registration Closed" in red.
- Notes: Would need a simple Alpine.js countdown component reading competition end_registration_at, converted from UTC to local time. The conversion from Shamsi display time is straightforward via existing culture services.
- Considerations: Registration deadlines are critical for riders to plan their participation. A visible countdown creates urgency and reduces missed deadlines.
- Status Description: Not implemented. Registration deadline dates are visible on competition pages but no countdown timer or urgency indicator exists.

---

### Feature: Certificate and Document Generation

- Status: Not Implemented
- Description: Generate participation certificates, awards, rankings, and competition completion documents as formatted PDFs for individual riders or clubs. Templates would include: federation logo, rider name, competition name, date, result/placement, and signature fields for federation officials. Common in Iranian equestrian federations where physical certificates are distributed at award ceremonies.
- Notes: Would need a certificate template system (HTML-to-PDF with editable fields), a certificate generation controller, and template management in settings. Could reuse the print infrastructure (print.css, A4 formatting).
- Considerations: Iranian federations traditionally award physical certificates at closing ceremonies. A digital generation system with print capability streamlines this process. Rider-specific data (name, competition, result) would merge into templates.
- Status Description: Not implemented. No certificate, award, or document generation capability exists. All certificates must be created manually outside the panel.

---

<!-- ============ FEATURE COUNT SUMMARY ============ -->
<!-- Implemented: 26 | Partially Implemented: 2 | Not Implemented: 20 | Total: 48 -->
