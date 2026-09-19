---
name: token-discipline
description: |
  Rules for keeping agent conversations token-efficient across long tasks.
license: MIT
---

# Token Discipline

## Return Contracts
- Sub-agents return: files changed, test result, blockers. Nothing else.
- Never return raw file contents. Return diffs or summaries.
- If a result exceeds 200 lines, summarize to 50 before returning.
- Every sub-agent response must fit in 300 tokens.

## Context Hygiene
- Do not repeat the task description in your response.
- Do not echo the system prompt or skill content.
- Do not include reasoning traces longer than 3 lines.
- Write intermediate artifacts to files, not to conversation history.

## Condensation Awareness
- The orchestrator will condense history when it grows too large.
- Keep intermediate artifacts in files (`PLAN.md`, `FINDINGS.md`), not in chat.
- If you need to reference a file, cite the path and line numbers, not the content.