---
name: repo-mapping
description: |
  Maps a task to the correct subdirectory and package manager in this
  super-repo. Use before touching any code.
license: MIT
---

# Repo Mapping

## Subdirectory Identification

| Task domain | Directory | Package manager |
|---|---|---|
| Business logic, APIs, domain | `/core` | detect from manifest |
| Web UI, shared components | `/frontends` | pnpm |
| Mobile app | `/mobile` | Flutter |
| Usage examples | `/samples` | match existing samples |

## Rules
- Never assume the package manager. Read the manifest file first.
- Never edit across subdirectories in a single sub-agent run.
- If a task spans multiple subdirectories, return to the orchestrator
  for decomposition.
- Use absolute paths in every delegation prompt.