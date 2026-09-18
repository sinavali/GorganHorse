# User Usage Specification — Gorgan Horse Federation Panel

**Document type:** User Usage & Frontend Build Specification
**Companion documents:** Blueprint, Technical, Proposal
**Status:** Final, production-ready, no phasing, no versioning
**Audience:** Frontend developers, UX reviewers, product stakeholders

---

## Table of Contents

1. Purpose & Audience
2. Personas
3. Global UX Principles
4. Culture, Language & Direction
5. Navigation & Layout
6. Authentication Flows
7. Admin Flows
8. Manager Flows
9. Rider Flows
10. Club Flows
11. Shared Components
12. Reports & Grid UX
13. Print UX
14. Notifications & Messaging UX
15. Bulk Operations UX
16. Forms & Validation UX
17. Error States & Messaging
18. Empty States
19. Tooltips & Help
20. KPIs per Role & per Page
21. Accessibility & RTL
22. Mobile Experience
23. Performance UX
24. Final Notes

---

## 1. Purpose & Audience

This document specifies **how users interact with the panel**. It is the contract for the frontend build. It describes:

- Every page and its purpose
- Every user flow step by step
- What each role sees and can do
- What KPIs appear on each dashboard
- How errors, empty states, and bulk operations behave
- How print, QR, notifications, and messaging work from a UX standpoint

**Audience:**
- **Frontend developers** — build every screen from this document.
- **UX reviewers** — validate flows against real-world use.
- **Product stakeholders** — confirm expectations.

This document does **not** specify implementation details (that is the Technical document) or architecture (that is the Blueprint).

---

## 2. Personas

### 2.1 Admin (مسعود)

Federation IT lead. Manages settings, users, backups, impersonation, audit. Needs: full access, safe actions, clear recovery.

### 2.2 Manager (محمدجواد)

Federation operational manager. Creates competitions, manages clubs, riders, horses, signups, payments, results, reports. Non-technical. Needs: rich reports, quick data entry, bulk operations.

### 2.3 Rider (سینا)

Amateur show-jumping rider. Owns 1–4 horses. Signs up for competitions, pays online, views results. Non-technical. Needs: simple signup, quick status, clear notifications.

### 2.4 Club (باشگاه هیرکان)

Club owner/manager. Views affiliated riders, competitions at their venue, own bans, and generates reports. Needs: report-focused dashboard, forward-looking bans.

---

## 3. Global UX Principles

**G01 — Mobile-first.** Every page works on a phone in portrait mode. Tables scroll horizontally when needed.

**G02 — RTL-first.** All layouts mirror for RTL. Persian is the primary language.

**G03 — Fast feedback.** Every action gives immediate visual feedback (spinner, toast, or inline message).

**G04 — Non-destructive defaults.** Destructive actions require confirmation (typed confirmation for delete, standard confirmation for cancel).

**G05 — Clear status.** Every entity shows its status inline (badges, colors).

**G06 — Consistent layout.** Every list page has the same shape: filters on top, bulk actions, table, pagination. Every edit page has the same shape: form, help tooltips, save/cancel.

**G07 — Tooltips everywhere.** Any non-obvious field has an inline help tooltip.

**G08 — Bulk operations.** Every list supports multi-select + bulk actions.

**G09 — Print-ready.** Every entity detail page has a print button.

**G10 — No dead ends.** Every error state offers a path forward (retry, contact, back).

**G11 — Persian numbers.** Display uses Persian digits per culture.

**G12 — Shamsi dates.** All dates displayed in Jalali.

**G13 — Money in Toman.** All amounts suffixed with "تومان".

**G14 — Accessible.** Keyboard navigation, focus rings, contrast, ARIA labels on interactive elements.

**G15 — Fast.** Pages render under 500 ms on a mid-range phone on 4G.

---

## 4. Culture, Language & Direction

- **Primary:** fa-IR (Persian), RTL.
- **Secondary:** en-US (English), LTR (reserved; switchable via user preference).
- **Dates:** Jalali, formatted per culture JSON.
- **Numbers:** Persian digits, `٫` decimal, `٬` thousands separator.
- **Money:** Toman (`تومان`), suffix with space.
- **Week start:** Saturday. **Weekend:** Friday.
- **Timezone:** Asia/Tehran.
- **Font:** Vazirmatn.

Language switcher visible in the topbar (small globe icon). Default is per user preference; falls back to browser locale; falls back to fa-IR.

---

## 5. Navigation & Layout

### 5.1 Panel layout

```
┌────────────────────────────────────────────────────────────┐
│  TOPBAR                                                     │
│  [Logo] [Breadcrumb]           [Search] [Bell] [Lang] [Me] │
├──────────────┬─────────────────────────────────────────────┤
│              │                                             │
│  SIDEBAR     │  CONTENT                                    │
│              │                                             │
│  • Dashboard │                                             │
│  • Users     │                                             │
│  • Clubs     │                                             │
│  • Horses    │                                             │
│  • Rades     │                                             │
│  • Payments  │                                             │
│  • Competitions                                            │
│  • Signups   │                                             │
│  • Payment Orders                                          │
│  • Reports   │                                             │
│  • Notifications                                           │
│  • Messages  │                                             │
│  • Settings  │                                             │
│  • Audit                                                   │
│  • Backups   │                                             │
│              │                                             │
└──────────────┴─────────────────────────────────────────────┘
```

### 5.2 Sidebar contents per role

**Admin sees:** Dashboard, Users, Clubs, Horses, Rades, Payments, Competitions, Signups, Payment Orders, Reports, Notifications, Messages, Settings, Audit, Backups, Profile.

**Manager sees:** Dashboard, Users (rider + club only), Clubs, Horses, Rades, Payments, Competitions, Signups, Payment Orders, Reports, Notifications, Messages, Profile.

**Rider sees:** Dashboard, My Horses, Horse Shares, Competitions, My Signups, Notifications, Messages, Profile.

**Club sees:** Dashboard, My Club, Affiliated Riders, Competitions, Bans, Reports, Notifications, Messages, Profile.

### 5.3 Topbar

- **Left:** Logo + breadcrumb.
- **Right:** Global search (spotlight, Ctrl/Cmd+K not required — click or tap), notification bell with unread count, language switcher, user menu (profile, logout).

### 5.4 Impersonation banner

When impersonating: a persistent yellow banner at the top of every page: **"You are impersonating [name]. [End impersonation]"**.

### 5.5 Pending verification banner

For pending riders: a persistent orange banner: **"Your account is not yet verified by a Manager. Some actions are disabled."**

### 5.6 Disabled (limited) banner

For limited-disabled users: a persistent red banner: **"Your account is restricted. You can view data but cannot create or modify records."**

### 5.7 Maintenance page

When `app.maintenance = 1`, panel pages show a maintenance page (423). Logout and payment callbacks remain accessible.

---

## 6. Authentication Flows

### 6.1 Login (password)

**URL:** `/auth/login`

**Fields:**
- Username or phone number
- Password
- Captcha (if enabled)
- "Remember me" checkbox (extends session to full 90 days — always 90 days by default)

**Buttons:**
- Login
- "Login with OTP" (visible only if SMS enabled)
- "Sign up" (link to `/auth/signup`)

**Errors:**
- Invalid credentials → "نام کاربری یا رمز عبور نادرست است"
- Rate limited → "تعداد تلاش‌های شما بیش از حد مجاز است. لطفا X دقیقه دیگر تلاش کنید."
- Full-disable → "حساب شما مسدود شده است. لطفا با پشتیبانی تماس بگیرید."
- Pending verification → login succeeds; banner appears.

### 6.2 Login (OTP)

**Step 1 — `/auth/login/otp/request`**
- Field: Phone number
- Captcha (if enabled)
- Button: Send code

**Step 2 — `/auth/login/otp/verify`**
- Field: 5-digit code
- Auto-focus; numeric keypad on mobile
- Timer showing remaining validity (2:00 countdown)
- "Resend code" (disabled until 60s elapsed)
- Button: Verify

**Errors:**
- SMS disabled → OTP button hidden on login page
- Code expired → "کد منقضی شده است. لطفا دوباره درخواست کنید."
- Max attempts → "تعداد تلاش‌ها بیش از حد مجاز. لطفا دوباره درخواست کنید."

### 6.3 Signup

**URL:** `/auth/signup`

**Fields:**
- Username (auto-generated as 6–8 digits; shown read-only)
- Phone number (required, unique)
- Password (min 8)
- Confirm password
- First name
- Last name
- National ID (10 digits, validated)
- Captcha (if enabled)
- Accept terms checkbox

**On submit:**
- Account is created with `verification_status = pending`
- Auto-verification scheduled for +48h
- Rider can log in immediately, sees pending banner
- Cannot add horses, cannot sign up for competitions

### 6.4 Forgot password

**URL:** `/auth/forgot`

**Content:**
- Message: "برای بازیابی رمز عبور با مدیران یا پشتیبانی تماس بگیرید."
- No form.

### 6.5 Logout

**Button:** In user menu → "خروج"

**Behavior:** Revokes current session, redirects to `/auth/login`.

### 6.6 Session expiry

When a session expires (90 days or manual revoke), the next request redirects to login with message: "نشست شما منقضی شده است. لطفا دوباره وارد شوید."

---

## 7. Admin Flows

### 7.1 Dashboard

**URL:** `/panel`

**Layout:**
- Row 1: 6 KPI cards
- Row 2: Two charts (signups over time, revenue over time)
- Row 3: Rade popularity chart + recent changelog
- Row 4: Quick actions

**KPI cards:**
1. کل سوارکاران — with 7-day delta
2. کل اسبان — with 7-day delta
3. کل باشگاه‌ها
4. مسابقات فعال (status=open)
5. درآمد این ماه (IRT)
6. تاییدهای در انتظار (verification + signup confirmations combined)

**Charts:**
- Signups over last 30 days (line)
- Revenue over last 30 days (bar)
- Rade popularity last 90 days (horizontal bar)

**Recent changelog:** Last 20 changelog entries, clickable to entity.

**Quick actions:**
- ایجاد مسابقه
- ایجاد کاربر
- ایجاد باشگاه
- مشاهده گزارش‌ها

### 7.2 Users

**URL:** `/panel/users?role=...`

**Filters:**
- Role (all, admin, manager, rider, club)
- Status (all, pending, verified, rejected, disabled-limited, disabled-full)
- Search (by username, phone, first name, last name, national ID)

**Table columns:** Username, Role, Full name, Phone, Status, Verification, Created at, Actions.

**Actions per row:** View, Edit, Impersonate, Disable, Reset password, Sessions.

**Bulk actions:** Verify, Disable (limited/full), Enable, Export CSV, Delete (Admin only).

**Row click:** Opens user detail.

### 7.3 User detail

**Tabs:** Profile, Rider Profile (if rider), Sessions, Activity, Horses (if rider), Signups (if rider), Audit (Admin only).

**Header actions:**
- Edit
- Impersonate (Admin)
- Disable / Enable
- Reset password
- Revoke all sessions (Admin)
- Delete (Admin, with typed confirmation)

### 7.4 Clubs

**URL:** `/panel/clubs`

**Filters:** Status (active/inactive), City, Search.

**Table columns:** Name, City, Contact person, Phone, Affiliated riders count, Created at, Actions.

**Bulk actions:** Activate, Deactivate, Export CSV.

### 7.5 Club detail

**Tabs:** Profile, Affiliated riders, Bans, Competitions at venue, Reports.

**Header actions:** Edit, Print, Disable / Enable, Delete.

### 7.6 Horses

**URL:** `/panel/horses`

**Filters:** Owner (autocomplete), Status (active / sold / soft-deleted), Gender, Race, Color, Microchip (search).

**Table columns:** Name, Microchip, Owner, Gender, Race, Color, Status, Created at, Actions.

**Bulk actions:** Export CSV, Import CSV, Soft delete, Restore.

**Header actions:** Add Horse, Import CSV, Export Template.

### 7.7 Horse detail

**Tabs:** Profile, Images, Signup history, Shares, Transfers, Performance, Reports.

**Header actions:** Edit, Print, Soft delete / Restore, Sold to non-rider, Initiate transfer, Share to rider.

### 7.8 Rades

**URL:** `/panel/rades`

**Table columns:** Name, Age range, Age enforced, Sort order, Active, Created at, Actions.

**Bulk actions:** Activate, Deactivate, Export CSV.

### 7.9 Rade detail

**Fields:** Name, Slug, Description, Age min/max, Age enforced, Sort order, Active.

**Header actions:** Save, Cancel, Delete.

### 7.10 Payments (templates)

**URL:** `/panel/payments`

**Table columns:** Name, Amount (IRT), Active, Created at, Actions.

**Bulk actions:** Activate, Deactivate, Export CSV, Print selected.

**Header actions:** Add Payment, Print list.

### 7.11 Payment detail

**Fields:** Name, Slug, Description, Amount (IRT), Active.

**Header actions:** Save, Cancel, Delete, Print.

### 7.12 Competitions

**URL:** `/panel/competitions`

**Filters:** Status (draft, open, closed, running, finished, cancelled), Venue club, Date range, Search.

**Table columns:** Title, Venue club, Registration window, Start date, Rade count, Signup count, Status, Results status, Actions.

**Bulk actions:** Publish, Cancel, Export CSV.

**Header actions:** Add Competition, Calendar view.

### 7.13 Competition detail

**Tabs:** Profile, Rades, Signups, Results, Payment orders, Reports, Print preview.

**Header actions:** Edit, Pause registration / Resume registration, Cancel, Clone (opens prefilled create page), Print, Signup sheet print.

### 7.14 Rades tab (per competition)

**Table columns:** Rade name, Payment, Price (IRT), Capacity, Auto-confirm, Barrage, Signup mode, Sort, Actions.

**Header action:** Add Rade (opens a modal to select Rade + Payment + capacity + flags).

### 7.15 Signups

**URL:** `/panel/signups`

**Filters:** Competition, Rade, Rider, Horse, Club, Status, Payment status, Date range.

**Table columns:** Rider, Horse, Competition, Rade, Club, Payment amount, Status, Confirmed, Position, Winner, Created at, Actions.

**Bulk actions:** Confirm, Reject, Export CSV.

### 7.16 Payment orders

**URL:** `/panel/payment-orders`

**Filters:** Competition, Rider, Status (pending, paid, failed, pending_refund, refunded), Date range, Ref ID.

**Table columns:** Order ID, Rider, Competition, Rade, Amount, Status, Authority, Ref ID, Verified at, Actions.

**Bulk actions:** Mark pending refund, Mark refunded, Unmark refund, Export CSV.

**Header action:** Reconciliation.

### 7.17 Results (per competition)

**URL:** `/panel/competitions/{id}/results`

**Layout:**
- Competition header (title, dates, venue, status)
- Results status badge (draft / confirmed / published)
- Per-Rade section (collapsible)
  - Barrage toggle + notes field
  - Grid: Rider, Horse, Club, Position, Winner, Notes
  - Inline editable

**Actions:**
- Save Draft
- Confirm Results (draft → confirmed)
- Publish Results (confirmed → published; notifies riders)
- Reopen Results (Admin only; published → confirmed)

### 7.18 Reports

**URL:** `/panel/reports`

**Layout:** Sidebar of presets + main grid.

**Presets (sidebar):**
- همه ثبت‌نام‌ها (signups)
- درآمد (revenue)
- نتایج (results)
- اسبان (horses)
- سوارکاران (riders)
- باشگاه‌ها (clubs)
- پرداخت‌ها (payments)
- تحریم‌ها (bans)

Each preset pre-fills filters via query params.

### 7.19 Notifications

**URL:** `/panel/notifications`

**List:** Chronological, grouped by day. Unread marked with dot. Click to open related entity.

**Actions:** Mark as read, Mark all as read.

### 7.20 Messages

**URL:** `/panel/messages`

**List:** Sent by Managers/Admins, recipient scope, sent date, delivery rate.

**Compose:** Subject, Body, Scope (global / competition / rade / selected users), Send.

### 7.21 Settings

**URL:** `/panel/settings`

**Tabs:** General, Whitelabel, Auth, Uploads, Clubs, Horses, Competitions, Payments, SMS, Reports, Cache, Logs, Backup, Security, UI.

Each tab is a form with rich help text per field.

### 7.22 Audit

**URL:** `/panel/audit`

**Filters:** Actor, Action, Entity, Date range, Result.

**Table columns:** Timestamp, Actor, Action, Target, Result, Details (expand).

### 7.23 Backups

**URL:** `/panel/backups`

**List:** Name, size, created at, actions (download, restore, delete).

**Actions:**
- Create backup (with optional suffix)
- Restore (typed confirmation)
- Delete
- Clear cache
- Reset (typed confirmation)
- Seed demo
- Clear demo

---

## 8. Manager Flows

Managers use the same pages as Admin **except**:

- Users: only rider + club roles are visible.
- Users: cannot impersonate, cannot delete.
- No Settings, Audit, or Backups in sidebar.
- No session revocation for other users.

Manager **can**:
- Create/edit riders and clubs
- Verify/reject/disable riders
- Full CRUD on clubs, horses, rades, payments, competitions, signups, payment orders
- Confirm/reject signups
- Enter results
- Publish results
- Create/send broadcasts
- Access all reports

Manager dashboard KPIs:

1. سوارکاران در انتظار تایید
2. ثبت‌نام‌های در انتظار تایید
3. مسابقات امروز
4. درآمد این ماه
5. ثبت‌نام‌های امروز
6. پرداخت‌های در انتظار

Charts: signups last 30 days, revenue last 30 days.

Quick actions: ایجاد مسابقه، تایید سوارکاران، تایید ثبت‌نام‌ها.

---

## 9. Rider Flows

### 9.1 Dashboard

**URL:** `/panel`

**KPI cards:**
1. اسبان من
2. ثبت‌نام‌های من (pending/confirmed split)
3. مقام‌های اول
4. مسابقات پیش‌رو

**Sections:**
- My horses (list, add horse button)
- Upcoming competitions (list, sign up button)
- Recent results
- Pending share/transfer requests

Quick actions: افزودن اسب، مشاهده مسابقات، پروفایل من.

### 9.2 My Horses

**URL:** `/panel/horses`

**Filters:** Status.

**Table columns:** Name, Microchip, Gender, Race, Status, Actions.

**Header actions:** Add Horse, Import CSV, Export Template.

Rider can:
- Add a horse (only if verified)
- Edit a horse
- View a horse
- Upload up to 5 images
- Share a horse to another rider
- Initiate a transfer
- Mark as sold to non-rider
- Soft delete a horse

Rider **cannot**:
- View other riders' horses (except shared-to-them horses)
- Hard delete any horse

### 9.3 Horse Shares

**URL:** `/panel/horse-shares`

**List:** Horses shared to me by other riders, with owner name and accept/reject actions.

### 9.4 Competitions

**URL:** `/panel/rider/competitions`

**Filters:** Status (open, closed, upcoming), Date.

**Table columns:** Title, Venue, Registration window, Start date, Rade count, Actions.

### 9.5 Competition detail (rider view)

**URL:** `/panel/rider/competitions/{id}`

**Sections:**
- Competition info
- Rades list with prices
- Sign up button per Rade (if registration open and rider verified)

### 9.6 Signup flow (rider)

1. Click "Sign up" on a Rade.
2. Modal opens:
   - Select Horse (own + shared-to-me)
   - Select affiliation Club
   - Confirm price (read-only)
3. Click "Pay and register" → redirected to ZarinPal.
4. Return via callback.

If rider is pending → show message: "برای ثبت‌نام، حساب شما باید توسط مدیر تایید شود."

If Rade is full → show message: "ظرفیت این رده تکمیل است."

### 9.7 My Signups

**URL:** `/panel/rider/signups`

**Table columns:** Competition, Rade, Horse, Club, Price, Status, Position, Winner, Created at.

Rider cannot cancel a paid signup (support handles it).

### 9.8 Profile

**URL:** `/panel/profile`

**Tabs:** Profile, Avatar, Password, Sessions.

Rider can:
- Update name, phone, email, national ID, insurance number, birth date, gender, address, city, province, bio, emergency contact
- Upload avatar
- Change password
- View own sessions and revoke them
- Revoke all sessions

Rider cannot:
- Change username
- Change verification status
- Change role

---

## 10. Club Flows

### 10.1 Dashboard

**URL:** `/panel`

**KPI cards:**
1. مسابقات در محل باشگاه
2. سوارکاران وابسته
3. درآمد این ماه (از مسابقات محل)
4. تحریم‌های فعال

**Sections:**
- Competitions at venue (list)
- Affiliated riders (list)
- Recent bans

Quick actions: گزارش‌ها، باشگاه من.

### 10.2 My Club

**URL:** `/panel/profile` (extended)

Club can:
- Update name, address, city, province, phone, email, contact person, description
- Upload logo and banner
- Change password
- View own sessions

Club cannot:
- Change username
- Change role
- Delete the club

### 10.3 Affiliated Riders

**URL:** `/panel/club/riders`

**List:** Riders who chose this club as affiliation in any competition.

**Columns:** Name, National ID (masked), Phone (masked), Competitions count, Last competition, Actions.

### 10.4 Competitions at venue

**URL:** `/panel/club/competitions`

**List:** Competitions held at this club.

**Columns:** Title, Dates, Registration window, Signup count, Results status.

### 10.5 Bans

**URL:** `/panel/club/bans`

**Tabs:** Riders, Horses.

**Actions:** Ban a rider or horse (by search), unban.

### 10.6 Reports

**URL:** `/panel/reports` (scoped)

Club sees reports scoped to:
- Their venue competitions
- Their affiliated riders

---

## 11. Shared Components

### 11.1 Breadcrumb

Every page has a breadcrumb in the topbar. Clickable to navigate up.

### 11.2 Page header

Every page has:
- Title (h1)
- Optional subtitle
- Primary action(s) on the left (in RTL: on the right visually)
- Secondary actions

### 11.3 Table

- Sticky header.
- Sortable columns (click header).
- Row hover highlight.
- Multi-select via checkbox column.
- Row actions in a dropdown menu (⋯).
- Pagination at bottom.
- Page size selector (25/50/100/250).

### 11.4 Filter bar

- Above the table.
- Collapsible on mobile.
- "Reset" button.
- "Apply" button (or auto-apply).
- Saved filter state per user (grid state persistence).

### 11.5 Modal

- Centered.
- Close button.
- Confirm/Cancel.
- Escape key closes (with unsaved changes warning).

### 11.6 Toast

- Bottom-right (RTL: bottom-left).
- Success (green), Error (red), Warning (orange), Info (blue).
- Auto-dismiss in 5 seconds. Errors stay until dismissed.

### 11.7 Confirmation dialog

- For destructive actions.
- Shows what will happen.
- Requires explicit click.
- For critical actions (delete user, reset): require typing the entity name.

### 11.8 Status badges

- Green: active, confirmed, published, paid.
- Yellow: pending, draft, warning.
- Red: rejected, failed, cancelled, disabled.
- Gray: closed, inactive, archived.

### 11.9 Empty state

Every list has a friendly empty state with icon, message, and primary action.

### 11.10 Loader

Skeleton screens for lists and cards; spinner for buttons.

---

## 12. Reports & Grid UX

### 12.1 Layout

- Left sidebar (RTL: right): report presets.
- Main area: filter bar + grid.
- Grid: AG Grid with server-side row model.
- Column toggle menu (top-right).
- Export menu (top-right).
- Share button (top-right).

### 12.2 Filters

- Filter bar supports: text search, dropdown select, multi-select, date range (Shamsi), numeric range, boolean.
- Each filter has an operator selector (in, eq, ne, gt, lt, between, contains).
- Active filters appear as chips above the grid.
- Chips are removable.

### 12.3 Columns

- Column picker: checkboxes for visibility.
- Drag to reorder.
- Column widths adjustable; persisted per user.
- State saved in `localStorage` per user per report.
- Page number always resets to 1 when filters change.

### 12.4 Export

- XLSX and CSV.
- Exports current filter state, all pages.
- Signed URL returned; download starts.

### 12.5 Share

- Share button opens modal:
  - Select recipient (user search).
  - Riders can share only with Managers/Admins.
  - Managers/Admins can share with anyone.
- Recipient sees shared report in their Notifications + Reports page.

### 12.6 Print

- Print button on report page renders A4 print view with current filters.

---

## 13. Print UX

### 13.1 Entry points

- Print button on every entity detail page.
- Print list button on list pages.
- Print selected on bulk selection.
- Print preview link in competition detail.

### 13.2 Print page

- Opens in a new tab.
- Server-rendered, no JS needed.
- A4 portrait.
- Header: brand, entity title, Shamsi date, QR code.
- Footer: page number, federation name.
- Clean typography, no navigation.

### 13.3 Printable entities

- Competition (single + list)
- Horse (single + list)
- Rider (single)
- Club (single + list)
- Payment (single + list + selected)
- Signup sheet (per competition)
- Standings (per rider / horse / rider-horse)

### 13.4 QR codes

- QR encodes the current URL + query state.
- Scanned by an authed panel user → opens the same view.
- Not usable by unauthenticated visitors.
- QR appears on printed competition papers, signup sheets, report pages, and entity headers.

---

## 14. Notifications & Messaging UX

### 14.1 Notification bell

- Topbar bell icon with unread count badge.
- Click opens a dropdown list of latest 10.
- "View all" link to `/panel/notifications`.

### 14.2 Notification list

- Chronological, grouped by day.
- Unread marked with a colored dot.
- Click opens related entity.
- Actions: mark as read, mark all as read.

### 14.3 Notification types

- Signup created (to managers)
- Payment received (to rider + managers)
- Payment failed (to rider)
- Payment refunded (to rider)
- Verification pending (to rider)
- Verification verified (to rider)
- Verification rejected (to rider)
- Signup confirmed (to rider)
- Signup rejected (to rider)
- Competition assigned to club (to club)
- Ban applied (to banned user)
- Transfer request received (to owner)
- Transfer accepted (to initiator)
- Transfer rejected (to initiator)
- Horse shared to you (to recipient)
- Results published (to all riders of the competition)
- Admin broadcast (to selected recipients)

### 14.4 Messages

- Managers/Admins can compose broadcast messages.
- Scope: global, competition, competition-rade, selected users.
- Recipient sees message in Notifications and Messages page.
- Read status tracked.

### 14.5 SMS delivery

- If SMS enabled: important notifications also go via SMS.
- SMS content respects `sms.notify_on_*` settings.
- Not delivered if user has no phone on file.

---

## 15. Bulk Operations UX

Every list page supports:

- **Select all on page** (header checkbox).
- **Select all filtered** (link below table).
- **Bulk action menu** (appears when ≥1 selected).
- **Bulk confirmation dialog** with count.
- **Progress feedback** (toast + inline counts).

Available bulk actions per entity:

| Entity | Bulk actions |
|---|---|
| Users | Verify, Disable (limited/full), Enable, Reset password, Export CSV, Delete (Admin) |
| Clubs | Activate, Deactivate, Export CSV |
| Horses | Soft delete, Restore, Export CSV |
| Rades | Activate, Deactivate, Export CSV |
| Payments | Activate, Deactivate, Export CSV, Print selected |
| Competitions | Publish, Cancel, Export CSV |
| Signups | Confirm, Reject, Export CSV |
| Payment Orders | Mark pending refund, Mark refunded, Unmark refund, Export CSV |

---

## 16. Forms & Validation UX

### 16.1 Form layout

- Labels above inputs (RTL: labels on the right).
- Required fields marked with red asterisk.
- Help tooltips as small "?" icons next to labels.
- Inline errors below fields.
- Save / Cancel at the bottom.

### 16.2 Validation timing

- Client-side validation on blur.
- Server-side validation on submit.
- Server errors mapped to fields.

### 16.3 Field types

- Text, textarea, number, date (Shamsi picker), select, multi-select, checkbox, radio, file upload, tag input, autocomplete.

### 16.4 Shamsi date picker

- Persian calendar.
- Today highlighted.
- Keyboard navigation.
- Populates Gregorian equivalent in hidden field for backend.

### 16.5 Persian digit input

- Accepts both Persian and Latin digits.
- Normalizes to Latin for backend.

### 16.6 File uploads

- Drag and drop.
- File size shown.
- Progress bar.
- Thumbnail preview for images.
- Remove button.

### 16.7 Autosave

- Not implemented. Explicit save only.
- Warning on navigating away with unsaved changes.

---

## 17. Error States & Messaging

### 17.1 Error codes → messages (fa-IR)

| Code | Message |
|---|---|
| `AUTH_INVALID` | نام کاربری یا رمز عبور نادرست است |
| `AUTH_RATE_LIMITED` | تعداد تلاش‌های شما بیش از حد مجاز است |
| `AUTH_SESSION_EXPIRED` | نشست شما منقضی شده است |
| `USER_DISABLED_FULL` | حساب شما مسدود شده است |
| `USER_DISABLED_LIMITED` | حساب شما محدود شده است |
| `SMS_DISABLED` | سرویس پیامک فعال نیست |
| `CAPTCHA_INVALID` | کد امنیتی نادرست است |
| `VALIDATION_FAILED` | لطفا خطاهای فرم را برطرف کنید |
| `NOT_FOUND` | موردی یافت نشد |
| `FORBIDDEN` | شما به این بخش دسترسی ندارید |
| `RATE_LIMITED` | تعداد درخواست‌های شما بیش از حد مجاز است |
| `SERVER_ERROR` | خطای سرور. لطفا بعدا تلاش کنید |
| `MAINTENANCE` | سیستم در حال تعمیر است |

### 17.2 Network errors

- Show retry button.
- Preserve form data.

### 17.3 Server errors

- Show request ID.
- Provide contact link.

---

## 18. Empty States

Every list page has an empty state:

- Icon.
- Friendly message in Persian.
- Primary action (e.g. "افزودن اسب").

Examples:
- No horses: "هنوز اسبی ثبت نکرده‌اید." → "افزودن اسب"
- No competitions: "مسابقه‌ای یافت نشد."
- No signups: "ثبت‌نامی وجود ندارد."

---

## 19. Tooltips & Help

### 19.1 Field tooltips

Every non-obvious field has a "?" icon. Hover or tap shows a small tooltip with:
- What the field means.
- Example values.
- Any constraints.

### 19.2 Page help

Some pages have a "راهنما" link in the header that opens a modal with brief guidance.

### 19.3 Contextual help

Complex flows (signup, transfer, results entry) have a step-by-step help panel that can be toggled.

---

## 20. KPIs per Role & per Page

### 20.1 Admin dashboard

- Total riders (with 7-day delta)
- Total horses (with 7-day delta)
- Total clubs
- Active competitions
- Revenue this month
- Pending verifications + signup confirmations

### 20.2 Manager dashboard

- Pending rider verifications
- Pending signup confirmations
- Today's competitions
- Revenue this month
- Today's signups
- Pending payments

### 20.3 Rider dashboard

- My horses count
- My signups (pending / confirmed)
- My wins
- Upcoming competitions

### 20.4 Club dashboard

- Competitions at venue count
- Affiliated riders count
- Revenue this month (from venue)
- Active bans

### 20.5 Report page KPIs

Each report shows summary tiles above the grid:
- Signups report: total signups, confirmed, pending, cancelled
- Revenue report: total revenue, avg per signup, top club, top competition
- Results report: total winners, avg position, top rider, top horse
- Horses report: active horses, top performer, most entered
- Riders report: active riders, top by wins, top by revenue
- Clubs report: total clubs, top by revenue, top by signups
- Payments report: total paid, pending, refunded
- Bans report: active bans, most banned rider

---

## 21. Accessibility & RTL

- All interactive elements keyboard-accessible.
- Focus rings visible.
- Contrast ratios ≥ 4.5:1.
- ARIA labels on icons and non-text buttons.
- Skip-to-content link.
- RTL layouts use logical properties.
- Screen reader announcements for toasts and errors.
- Font size respects browser settings.

---

## 22. Mobile Experience

- Sidebar collapses to a hamburger menu.
- Topbar condenses: logo + bell + menu.
- Tables scroll horizontally; primary columns sticky.
- Forms stack vertically.
- Modals become full-screen sheets.
- Filters collapse into a drawer.
- Bulk actions in a bottom sheet.
- Print views are desktop-oriented (mobile users can still view).

---

## 23. Performance UX

- Skeleton screens on first load.
- Lazy load images.
- Grid pagination and virtual scroll.
- Optimistic UI updates for simple toggles.
- Toast feedback within 200 ms of action.

---

## 24. Final Notes

- **Every screen** in this document is required for the production build.
- **Every flow** is the minimum acceptable path.
- **Every KPI** is required where specified.
- **Every error** has a Persian message.
- **Every print view** is A4 and branded.
- **Every list** supports bulk operations.
- **Every form** has help tooltips.
- **Every page** works on mobile.
- **Every action** gives immediate feedback.

This is the finalized User Usage specification.
