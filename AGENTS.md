# Project Context

This is a product-agnostic repository. The `/docs` directory contains
the feature backlog and product specifications. The `/docs` directory contains
exhaustive technical documentation. Both are managed by the owner.

## Repository Map
- `/core`        - business backend (service layer, domain logic, APIs)
- `/frontends`   - pnpm monorepo containing all web frontends
- `/mobile`      - Flutter cross-platform mobile application
- `/samples`     - usage examples and integration references
- `/docs`   - feature backlog and specifications (Features.md editable only)

## Non-Negotiable Rules
1. Before touching any code, load the `repo-mapping` skill and identify the
   correct working directory and package manager.
2. Before planning any feature, load the `docs-navigation` skill and read the
   relevant `/docs` files. Do not guess product behavior.
3. Every sub-agent delegation must include the absolute path of the target
   directory and a self-contained instruction.
4. Never write to `/docs`. Never write to `/docs` except `Features.md`
   under the exact rules in the `features-policy` skill.
5. Every code change must include in-code documentation (file/class/method
   docblocks) in the SAME commit.
6. Every PR must include Unit + Integration tests for business logic as the
   LAST step. Tests validate BEHAVIOR, not implementation correctness.
7. Every PR must be reviewed in multiple passes before merge.
8. GitHub issues, branches, PRs, and labels must stay in sync at all times.
9. Cite the relevant spec section in every decision, commit message, and PR.
10. Use the `token-discipline` skill for all context management.

## Authority Order
Blueprint > Technical Spec > User Usage > Proposal.
When documents conflict, the higher authority wins. Cite the section.

## Current Task
The owner will provide the task. If no task is provided and
`/docs/Features.md` exists, load the `features-policy` skill and begin
the next feature from the least implementation cost to the most.