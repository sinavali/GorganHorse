# README — Documents

This folder contains the complete specification for the **Gorgan Horse Federation Panel**. The specification is final, production-ready, and not phased. Every architectural decision is locked.

---

## Reading guide

Read in this order depending on your role.

| # | Document | Audience | Length | Purpose |
|---|---|---|---|---|
| 1 | [Project Proposal — Gorgan Horse Federation Panel.md](./Project%20Proposal%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Client, stakeholders | Short | Executive summary, problem, solution, deliverables, KPIs, success metrics. |
| 2 | [Backend Blueprint — Gorgan Horse Federation Panel.md](./Backend%20Blueprint%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Architects, reviewers, implementers | Long | Master blueprint. Principles, scope, schema, flows, routes, KPIs, integrations, glossary, naming, error codes, implementation checklist, risk register. |
| 3 | [Technical — Gorgan Horse Federation Panel.md](./Technical%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Implementers, maintainers | Long | Stack, implementation rules, auth internals, validations, deployment, code standards. |
| 4 | [User Usage — Gorgan Horse Federation Panel.md](./User%20Usage%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Frontend developers, UX reviewers | Long | Every screen, flow, KPI, empty state, error message, print view. The frontend build contract. |

---

## Document summaries

### 1. Project Proposal

The client-facing summary. Written for a non-technical audience.

**Covers:**
- The problem with the current fragmented tools
- The proposed solution: one self-hosted panel
- Who it's for (Admin, Manager, Rider, Club)
- What it delivers per role
- Key capabilities (competitions, signups, payments, transfers, results, reports, notifications, printing)
- KPIs the panel delivers (operational, financial, performance, administrative)
- Delivery principles (monolithic, no SaaS, no per-user fees, Persian-first)
- Integrations (ZarinPal, MelyPayamak)
- Security and compliance summary
- Deployment and ownership
- What is explicitly not included
- Success metrics

**When to read:** first, if you are the client or a stakeholder.

---

### 2. Backend Blueprint

The master specification. Every architectural decision is final.

**Covers:**
- **§1 Overview** — what the panel is, non-negotiable facts
- **§2 Principles** — 30 mandatory principles every line of code must follow
- **§3 Scope** — in scope / out of scope
- **§4 Roles & Permissions** — Admin, Manager, Rider, Club; disable states; ban types
- **§5 Folder Structure** — target layout with file merging
- **§6 Domain Glossary** — every Persian term, English equivalent, definition
- **§7 Database Schema** — main DB and logs DB table lists with purpose
- **§8 Domain Model & Flows** — signup, transfer, share, results, ban, disable, verification, cancellation
- **§9 HTTP Envelope** — JSON response contract
- **§10 Routes** — every route with method, path, purpose
- **§11 Middleware Pipeline** — 9 middleware in execution order
- **§12 Authorization Matrix** — who can do what
- **§13 Reports & KPIs** — unified report engine, dashboards per role, KPI formulas
- **§14 Integrations** — ZarinPal, MelyPayamak, email (dropped)
- **§15 Settings Registry** — every setting key with default and purpose
- **§16 Seed Data Principles** — volume, validity, variety, structure
- **§17 Printing & QR** — printable entities, print styles, QR behavior
- **§18 Notifications & Messaging** — events, broadcast, SMS list
- **§19 File Uploads & Media** — storage, processing, limits, security
- **§20 Caching & Logging** — namespaces, log DB tables, retention
- **§21 Backup / Restore / Reset / Demo** — flows
- **§22 Installer** — steps
- **§23 Security** — implementation summary
- **§24 Naming Conventions** — DB, settings, routes, classes, error codes, files, assets
- **§25 Error Codes** — every code with HTTP status, fa/en messages, trigger
- **§26 Implementation Checklist** — linear task list linked to sections
- **§27 Risk Register** — client prerequisites, operational risks, out-of-scope risks
- **§28 Deployment & Portability**
- **§29 Closing Notes**

**When to read:** end-to-end if you are an architect, reviewer, or implementer.

---

### 3. Technical Specification

The implementation contract. Specifies **how** the Blueprint is realized.

**Covers:**
- **§1 Purpose & Audience**
- **§2 Tech Stack** — PHP 8.1+, SQLite, no framework, Alpine.js, Tailwind, AG Grid, SheetJS, QR
- **§3 Runtime Requirements**
- **§4 Bootstrapping & Lifecycle** — container, file merging policy
- **§5 Routing & HTTP Layer** — route definition, matching, request/response
- **§6 Envelope & Response Rules**
- **§7 Database Layer** — two connections, pragmas, transactions, whitelist enforcement
- **§8 Models Layer** — thin data structures, no business logic
- **§9 Services Layer** — business logic, documentation requirements
- **§10 Controllers Layer** — HTTP ↔ services, documentation requirements
- **§11 Authentication** — identity, password login, OTP login, sessions, captcha, rate limiting, password reset, pending rider, disable states
- **§12 Authorization** — middleware-driven, scoping, record-level rules
- **§13 Middleware Pipeline**
- **§14 CSRF**
- **§15 Culture, Calendar & Numbers** — fa-IR defaults, date handling, number handling
- **§16 Validation Rules** — common and entity-specific
- **§17 Business Rules & Invariants** — signups, capacity, price snapshots, ownership, transfers, shares, bans, results, disable, impersonation, idempotency
- **§18 Report Engine** — class contract, query building, columns, filters, exports, grid state, sharing
- **§19 Payment Integration** — ZarinPal endpoints, payloads, callback flow, refunds, reconciliation
- **§20 SMS Integration** — MelyPayamak client, OTP, notifications
- **§21 File Uploads & Media**
- **§22 Caching**
- **§23 Logging**
- **§24 Backup / Restore / Reset**
- **§25 Installer**
- **§26 Frontend Architecture** — templates, CSS, JS, grid contract, persistence, accessibility, tooltips
- **§27 Print & QR**
- **§28 Error Handling**
- **§29 Security Implementation**
- **§30 Performance**
- **§31 Code Style & Standards**
- **§32 Documentation Standard** — mandatory docblocks at every level
- **§33 Third-Party Libraries**
- **§34 Deployment**

**When to read:** after the Blueprint, end-to-end, if you are implementing or maintaining.

---

### 4. User Usage Specification

The frontend build contract. Specifies **how users interact** with the panel.

**Covers:**
- **§1 Purpose & Audience**
- **§2 Personas** — Admin, Manager, Rider, Club with characteristics
- **§3 Global UX Principles** — 15 mandatory UX rules
- **§4 Culture, Language & Direction** — fa-IR, RTL, Jalali, Persian digits, Toman
- **§5 Navigation & Layout** — panel layout, sidebar per role, topbar, banners
- **§6 Authentication Flows** — password login, OTP login, signup, forgot, logout, session expiry
- **§7 Admin Flows** — dashboard, users, clubs, horses, rades, payments, competitions, signups, payment orders, results, reports, notifications, messages, settings, audit, backups
- **§8 Manager Flows** — what Manager sees differently from Admin
- **§9 Rider Flows** — dashboard, horses, shares, competitions, signup, signups, profile
- **§10 Club Flows** — dashboard, my club, affiliated riders, competitions at venue, bans, reports
- **§11 Shared Components** — breadcrumb, page header, table, filter bar, modal, toast, confirmation, badges, empty state, loader
- **§12 Reports & Grid UX** — layout, filters, columns, export, share, print
- **§13 Print UX** — entry points, print page, printable entities, QR codes
- **§14 Notifications & Messaging UX** — bell, list, types, messages, SMS delivery
- **§15 Bulk Operations UX** — select, actions per entity
- **§16 Forms & Validation UX** — layout, timing, field types, date picker, Persian digits, uploads, autosave
- **§17 Error States & Messaging** — error codes → messages, network errors, server errors
- **§18 Empty States**
- **§19 Tooltips & Help** — field, page, contextual
- **§20 KPIs per Role & per Page** — every dashboard KPI, every report summary tile
- **§21 Accessibility & RTL**
- **§22 Mobile Experience**
- **§23 Performance UX**
- **§24 Final Notes**

**When to read:** before building any frontend screen, if you are a frontend developer or UX reviewer.

---

## How the four documents relate

```
Project Proposal          ← client-facing summary
     │
     ▼
Backend Blueprint         ← architecture, schema, flows, principles, KPIs
     │
     ├──▶ Technical       ← how to implement (stack, rules, validations, auth)
     │
     └──▶ User Usage      ← how users interact (screens, flows, UX)
```

- The **Proposal** answers "what does the client get?"
- The **Blueprint** answers "what is the system, architecturally?"
- The **Technical** answers "how do I build it?"
- The **User Usage** answers "how do users interact with it?"

Every document is standalone but cross-references the others by section number.

---

## Conventions used in the documents

- **Persian terms** are written in Persian script followed by the English equivalent in parentheses where first introduced.
- **Section references** use `§N` (e.g. `§2`, `§13.4`).
- **Cross-document references** name the document and section (e.g. "Technical §4.3").
- **Mandatory principles** are prefixed `P01`, `P02`, ..., `P30` in the Blueprint.
- **Global UX principles** are prefixed `G01`, `G02`, ..., `G15` in the User Usage document.
- **Error codes** are `UPPER_SNAKE` with domain prefix (e.g. `AUTH_INVALID`, `USER_DISABLED_FULL`).
- **Setting keys** are dot-notation (e.g. `auth.session_absolute_days`, `sms.otp_ttl_seconds`).
- **Route paths** are lowercase, kebab-case (e.g. `/panel/payment-orders/reconciliation`).

---

## Glossary, naming, error codes, checklist, risk register

These topics are **not** separate documents. They are merged into the Backend Blueprint to reduce file count and keep the specification cohesive:

- **Glossary** → Blueprint §6
- **Naming Conventions** → Blueprint §24
- **Error Codes** → Blueprint §25
- **Implementation Checklist** → Blueprint §26
- **Risk Register** → Blueprint §27

If you are looking for one of these and can't find it, check the Blueprint.

---

## What is NOT in these documents

The following are explicitly out of scope and documented as such:

- WordPress site (landing pages, blog, SEO) — handled separately
- WordPress API — developed separately
- Email sending — dropped entirely
- PWA — not implemented
- Dark mode — not implemented
- CLI — not implemented
- Multi-tenancy — not implemented
- Migrations from old WordPress data — not implemented
- Column parity with old Excel plugin — richer by default
- Bulk SMS — not implemented
- Signup waitlists — not implemented

See the Blueprint §3.2 for the full list.

---

## Status of each document

| Document | Status |
|---|---|
| Project Proposal | Final |
| Backend Blueprint | Final |
| Technical Specification | Final |
| User Usage Specification | Final |

No versioning. No phasing. Every document is production-ready.

---

## Feedback and corrections

If you find an inconsistency between two documents, the **Backend Blueprint** is the authority on architecture, domain, and scope. The **Technical** document is the authority on implementation details. The **User Usage** document is the authority on frontend behavior. The **Project Proposal** is a summary and defers to the others.

Report inconsistencies to the project architect before implementation begins.

---

## Support

- **Specification questions:** refer to the specific document and section number.
- **Implementation questions:** check the Technical document first, then the Blueprint.
- **UX questions:** check the User Usage document first.
- **Domain questions:** check the Blueprint §6 (Glossary) and §8 (Flows).
- **Architecture decisions:** check the Blueprint §2 (Principles).
