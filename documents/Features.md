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

### Feature: Offline-First SQLite Architecture

- Status: Implemented
- Description: The entire application runs on SQLite with zero external database dependencies. Two SQLite files (app.sqlite, logs.sqlite) store all operational and audit data. No MySQL, PostgreSQL, Redis, or cloud database service is required, making deployment possible on any basic Iranian shared hosting or local machine without network-dependent infrastructure.
- Notes: WAL mode enabled for concurrent reads. Two DB connections keep audit logs separate for performance. No foreign DBaaS (e.g., ElephantSQL, PlanetScale, Amazon RDS) is reachable or needed in Iran. See Technical §7, Blueprint §28.
- Considerations: SQLite suits the data volume (≈52 competitions/year, ≈1,800 signups/year). For high-concurrency scenarios, ensure hosting provider supports SQLite file locking. No connection pool needed.
- Status Description: Fully implemented. The application is completely operational without any remote database server, which is essential in Iran where managed database services from foreign providers are inaccessible.

---

### Feature: Zero-CDN Vendored Library System

- Status: Implemented
- Description: All frontend and backend libraries (Alpine.js, AG Grid, SheetJS, qrcode.js, Vazirmatn font, Tailwind CSS, PHP packages) are vendored as plain static files under vendor/ and public/assets/js/vendor/. No CDN, no npm registry, no composer packagist access is needed at runtime. The app works entirely offline after initial deployment, avoiding reliance on Google Fonts, unpkg, jsdelivr, or any foreign resource that may be filtered in Iran.
- Notes: Tailwind CSS is built once during development and committed; no build step at runtime. No Node.js or Composer required on the production server. See Technical §2.2, §33.
- Considerations: Library updates require manual vendor replacement. No automatic security patches from package managers. A periodic manual review of vendored libraries is recommended.
- Status Description: Fully implemented. Every library is served from local files, ensuring the panel functions correctly even when Iranian ISPs filter or throttle access to foreign CDNs and registries.

---

### Feature: ZarinPal Domestic Payment Gateway Integration

- Status: Implemented
- Description: Full ZarinPal payment integration for competition registration fees. Riders pay via ZarinPal's gateway in IRT (Toman), receive a callback verification, and their signup is automatically confirmed (if auto-confirm is enabled) or enters manual confirmation queue. Payment orders are permanently recorded with authority, ref_id, card pan, and timestamps for full reconciliation. Manual refund workflow is supported.
- Notes: Uses ZarinPal POST request/verify endpoints. Callback endpoint is public (no auth) for gateway compatibility. Idempotent processing locked per authority. Reconciliation page compares local orders against ZarinPal CSV reports. See Technical §19, Blueprint §14.
- Considerations: ZarinPal is Iran's primary payment gateway; Stripe, PayPal, and foreign gateways are entirely inaccessible to Iranian merchants. This is the only payment integration and is required for paid competitions. Test with ZarinPal sandbox before going live.
- Status Description: Fully implemented. The integration uses ZarinPal's standard REST API and is the sole payment method, which is the only online payment option available for Iranian businesses.

---

### Feature: SMS-Based OTP Authentication via MelyPayamak

- Status: Implemented
- Description: Optional OTP login sent via Iran's MelyPayamak SMS gateway. Users enter their phone number, receive a 5-digit code via SMS, and verify within 120 seconds (3 attempts max). Rate-limited per phone and IP. Also used for notification delivery (signup confirmations, payment alerts, results publishing, transfer requests, bans). SMS is disabled by default and must be explicitly enabled by an Admin with valid MelyPayamak credentials.
- Notes: MelyPayamak is Iran's domestic SMS platform. WhatsApp, Telegram bots, and foreign messaging APIs are unreliable or restricted for official/organizational use in Iran. OTP codes stored hashed in logs.sqlite. See Technical §20, User Usage §6.2, §14.5.
- Considerations: SMS costs money per message via MelyPayamak credits. Failed SMS do not block the calling action (fire-and-log). If SMS is disabled, OTP login returns 403 SMS_DISABLED. Email delivery is dropped entirely per project scope.
- Status Description: Fully implemented. OTP and notification SMS work through Iran's domestic MelyPayamak API, providing a reliable authentication and notification channel that does not depend on foreign messaging services like WhatsApp or Telegram.

---

### Feature: Self-Hosted Persian Font (Vazirmatn) with Persian Digit Rendering

- Status: Implemented
- Description: Vazirmatn font is self-hosted locally under public/assets/css/fonts/, ensuring Persian text renders correctly without fetching fonts from Google Fonts or any foreign CDN. All numeric displays use Persian digits (۰۱۲۳۴۵۶۷۸۹), amounts shown in Toman (تومان suffix), dates in Jalali calendar, and RTL layout using CSS logical properties. No foreign font or CDN resource is loaded at runtime.
- Notes: Vazirmatn covers all required Persian glyphs. Cultural formatting (numbers, dates, currency) is driven by cultures/fa-IR.json and resolved per user preference. See Technical §2.2, §15, User Usage §4, §15.
- Considerations: Self-hosted fonts must be updated manually when new Vazirmatn versions are released. Font files add to deployment size but eliminate the #1 foreign dependency for text rendering.
- Status Description: Fully implemented. All text, numbers, dates, and currency display in native Persian/Iranian format without any external font or localization service, which is critical since Google Fonts and foreign localization APIs are blocked or unreliable in Iran.

---

### Feature: Print-Ready A4 Documents with QR Codes for Official Workflows

- Status: Implemented
- Description: Every entity (competition, horse, rider, club, payment, signup sheet, standings) has a server-rendered A4 print view with branded header (federation logo + entity title + Jalali date), QR code, and footer (page number + federation name). QR codes encode the authenticated panel URL + query state, enabling paper-based documents to link back to digital records. Designed for Iranian regulatory and federation administrative workflows where paper documentation is required.
- Notes: Print views are pure HTML/CSS (print.css) with no JavaScript dependency. QR generated client-side via qrcode.js or server-side via /panel/qr?data=... (cached). Printable entities cover all 8 types per Blueprint §17. See Technical §27, User Usage §13.
- Considerations: QR codes are auth-gated — scanning requires an authenticated session, protecting sensitive data on printed materials. Print styles optimized for A4 portrait with 12mm margins. Iranian federations often require physical signup sheets and competition papers.
- Status Description: Fully implemented. Print views and QR codes are available for all entities, supporting the paper-based administrative workflows common in Iranian sports federations while maintaining data security through authenticated QR codes.

---

### Feature: Self-Contained Deployment with No External Service Dependencies

- Status: Implemented
- Description: The entire application deploys as a self-contained folder copy. No external services are required: no email server, no cloud storage (S3/Firebase), no cache servers (Redis/Memcached), no message queues (RabbitMQ/SQS), no container orchestration, no load balancer, no CDN, no foreign API keys. Core functionality works with only PHP 8.1+, SQLite, and Apache/Nginx. Optional MelyPayamak SMS requires local Iranian SMS credits but is disabled by default.
- Notes: Installer self-locks after setup. Backup/Restore/Reset all work locally. No cron, no queue workers, no daemons needed. See Technical §34, Blueprint §28, User Usage §7.23.
- Considerations: Email is explicitly dropped — all communication goes through in-panel notifications and optional SMS. No AWS, Google Cloud, Azure, or any foreign cloud provider is used, which is critical since Iranian developers cannot reliably access these platforms for API keys, hosting, or service provisioning.
- Status Description: Fully implemented. The panel requires zero foreign cloud services, API keys, or external infrastructure for full operation, making it fully deployable and operational within Iran's internet restrictions on local hosting.

---

### Feature: Persian (fa-IR) Complete Localization with Jalali Calendar

- Status: Implemented
- Description: Full Persian language interface with RTL layout, Jalali (Shamsi) calendar for all date inputs and displays, Persian digit formatting, Tehran timezone (Asia/Tehran), Iranian week schedule (Saturday–Sunday weekend), and Toman currency display. All user-facing strings are translated in fa-IR. Culture system supports adding additional languages via cultures table and JSON files. Friday is the weekly rest day, matching Iranian work calendars.
- Notes: morilog/jalali handles Gregorian↔Jalali conversion. Date filters in reports are converted from Shamsi input to UTC SQL ranges automatically. Persian and Latin digits both accepted on input and normalized. See Technical §15, User Usage §4, cultures/fa-IR.json.
- Considerations: This is foundational for Iran use — no Iranian user can adopt a system using Gregorian dates, Latin digits, or LTR layout. The culture system is extensible for diaspora users who may need en-US alongside fa-IR.
- Status Description: Fully implemented. The entire interface is built for Iranian users with Jalali dates, Persian digits, Toman currency, RTL layout, and Tehran timezone, which is non-negotiable for any system used in Iran where the Jalali calendar and Persian number formatting are standard.

---

### Feature: QR Code Information Sharing for Offline Competition Coordination

- Status: Implemented
- Description: QR codes generated on competition papers, signup sheets, report pages, and entity headers encode authenticated panel URLs with query state. Event organizers can print QR codes on physical materials at competition venues. Attendees scan with a phone to instantly view competition details, entries, results, or standings in the panel — no need to remember URLs, search, or have direct server access beyond the initial scan. QR codes are cached server-side for performance.
- Notes: QR appears on: competition paper, signup sheet, report page, entity page header. Client-side generation via qrcode.js for instant display; server-side via /panel/qr?data=... with file-based caching under cache/. See Technical §27, User Usage §13.4.
- Considerations: At Iranian competition venues where Wi-Fi or cellular data may be weak, QR codes on printed materials allow organizers and participants to reference the panel later when connectivity is available. QR codes are auth-gated for data protection. This replaces foreign QR services that may be inaccessible.
- Status Description: Fully implemented. QR codes enable offline-to-online information flow at competition venues, reducing dependence on foreign QR services and supporting Iran's connectivity variability at sports events.

---

### Feature: Broadcast SMS Notifications for Critical Event Alerts

- Status: Implemented
- Description: Managers and Admins can compose broadcast messages sent to selected users via in-panel notifications and optionally via SMS (MelyPayamak). Notifications cover all critical lifecycle events: signup created, payment received/failed/refunded, verification pending/verified/rejected, signup confirmed/rejected, results published, transfer requests, bans applied, and admin broadcasts. Each notification type is individually togglable via sms.notify_on_* settings. Delivery failures are logged but never block the originating action.
- Notes: Message recipients tracked with read receipts and SMS delivery status. SMS delivery gated by per-type settings. Deduplicated by (user, type, ref) within 5 minutes. Failures logged but non-blocking. See Technical §20, User Usage §7.19, §14, Blueprint §18.
- Considerations: In Iran where email deliverability to Iranian addresses is unreliable and foreign messaging platforms vary in accessibility, SMS and in-panel notifications are the two reliable communication channels. The per-type toggles prevent notification fatigue and allow admins to control SMS costs by enabling only critical alerts.
- Status Description: Fully implemented. The notification system provides both in-panel and SMS delivery for all critical events, using only Iran-compatible channels (MelyPayamak SMS + local panel), with no dependency on foreign email or messaging services.

---