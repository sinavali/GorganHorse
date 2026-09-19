# PROJECT OPERATING PROCEDURE

STEP 1 — GAP ANALYSIS
Map every spec requirement to exactly one of: Implemented | Partially Implemented | Task | Out-of-scope (Blueprint §3.2). Produce a table with spec refs and file evidence. No guessing.

STEP 2 — ISSUE GENERATION
Open GitHub Issues for every gap. Each issue must include: title, labels (spec:§X, role, type, priority), assignee, milestone, spec refs, acceptance criteria, files likely affected, required tests, required docs, and be ≤1 day of work. Split larger items.

STEP 3 — PRIORITIZATION
Rank by critical path first, then by Features.md implementation cost (least→most). If Features.md is missing, ask me for it.

STEP 4 — DELEGATION
Assign to Auditor (audit) and Implementer (implementation). Each task must be assigned before work starts.

STEP 5 — EXECUTION LOOP
For each task, enforce:
- PR rule: Unit+Integration tests for business logic as the LAST step of the PR (behavior, not code correctness).
- In-code docs: file/class/method/endpoint docblocks + schema/settings/report comments in the same PR.
- Features.md: only Status + Notes of existing entries may be edited, by Reviewer/Implementer on the same PR.
- GitHub live-sync: issues ↔ branches ↔ PRs ↔ reviews stay in sync at all times.
- Review: every PR reviewed in multiple passes before merge.

STEP 6 — LOOP
Done → Audit (correctness→completeness→perfection; no over-audit) → Fix → Re-audit → Repeat till zero → Demand new instructions OR start next Features.md feature (least→most cost) → Review multi-pass → back to Done.

CONSTRAINTS
- Do not write production code until you have cited the relevant spec sections, stated your plan, and opened the issues.
- Do not edit any file under "/docs" except Features.md (Status + Notes only).
- Do not add dependencies. Do not re-architect. Do not implement out-of-scope items.
- Cite spec sections in every decision.