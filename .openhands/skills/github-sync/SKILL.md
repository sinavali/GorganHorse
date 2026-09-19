---
name: github-sync
description: |
  How to keep GitHub issues, branches, PRs, and labels in sync with the
  local state. Use this skill for every GitHub-related operation.
license: MIT
---

# GitHub Sync

## Live Sync Rules
- Every state change on the local side must be reflected on GitHub.
- Issues <-> Branches <-> PRs <-> Reviews must stay in sync at all times.
- The owner monitors the GitHub repo page. Do not batch updates.

## Operations

| Local action | GitHub action |
|---|---|
| New gap found | Open Issue with labels + milestone |
| Task started | Create branch `feat/fix/audit/chore/<issue-slug>` |
| Code committed | Push branch, reference issue in commit |
| Implementation done | Open PR (draft if tests not yet added) |
| Tests added | Convert PR to ready-for-review |
| Review started | Post PR review with findings |
| Review passed | Merge PR, close issue, delete branch |
| Audit finding | Open Issue with `role:auditor`, `type:audit` |

## Offline Resilience
- If the GitHub API is unreachable, continue working locally.
- Queue sync operations in `.openhands/sync_queue.json`.
- The `heartbeat` hook will flush the queue when connectivity returns.
- Never block work on network availability.

## Labels (standard set)
`spec:SSX` - `role:architect|auditor|coder|reviewer` - `type:feature|fix|audit|chore`
`priority:blocker|critical|major|minor` - `status:in-progress|in-review|blocked`