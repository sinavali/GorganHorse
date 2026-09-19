---
name: features-policy
description: |
  The exact rules for reading and editing /docs/Features.md. Use this
  skill whenever the task involves feature backlog management.
license: MIT
---

# Features.md Policy

## File Location
`/docs/Features.md` - the ONLY file under `/docs` that agents may edit.

## Edit Policy (absolute)
- Agents may edit ONLY the `Status` and `Notes` fields of existing entries.
- Agents must NOT add, remove, reorder, or rename feature entries.
- Agents must NOT edit `Title`, `Description`, `Considerations`, or
  `Status Description`.
- Reviewer/Refinery may edit `Considerations` and `Status Description`
  only on the same PR.
- Reviewer/Refinery may also edit `Status` and `Notes` on the same PR.
- All other edits require owner approval.

## Status Values
- `Implemented`
- `Partially Implemented`
- `Not Implemented`

## Entry Structure

```markdown
### Feature: <Title>
- Status: <Implemented | Partially Implemented | Not Implemented>
- Description: <describe the feature>
- Notes: <implementation notes, links, caveats, follow-ups>
- Considerations: <review/refinery considerations>
- Status Description: <why this status>
```

## Reading Order
1. If `Features.md` exists and the owner has no new instructions, read it.
2. Sort features by implementation cost: least -> most.
3. Pick the next `Not Implemented` or `Partially Implemented` feature.
4. Implement it. Update Status + Notes. Open PR.
5. Reviewer updates Considerations + Status Description on the same PR.