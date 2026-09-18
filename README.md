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

All project documentation lives in `/documents/`. Read in this order depending on your role.

| Document | Audience | Purpose |
|---|---|---|
| [`documents/README.md`](./documents/README.md) | Everyone | Documents index and reading guide. |
| [`documents/Project Proposal — Gorgan Horse Federation Panel.md`](./documents/Project%20Proposal%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Client, stakeholders | Executive summary, deliverables, KPIs, success metrics. |
| [`documents/Backend Blueprint — Gorgan Horse Federation Panel.md`](./documents/Backend%20Blueprint%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Architects, reviewers | Master blueprint: principles, scope, schema, flows, routes, KPIs, integrations, glossary, error codes, implementation checklist, risk register. |
| [`documents/Technical — Gorgan Horse Federation Panel.md`](./documents/Technical%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Implementers, maintainers | Stack, implementation rules, auth internals, validations, deployment. |
| [`documents/User Usage — Gorgan Horse Federation Panel.md`](./documents/User%20Usage%20—%20Gorgan%20Horse%20Federation%20Panel.md) | Frontend developers, UX | Every screen, flow, KPI, empty state, error message, print view. |

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
├── documents/                   ← all specification docs
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
