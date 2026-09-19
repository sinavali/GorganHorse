#!/bin/bash
# Stop hook: if a PR is open, require that the last commit includes non-documentation changes.
cd "${OPENHANDS_PROJECT_DIR:-$PWD}"

BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || "")
if [ -z "$BRANCH" ] || [ "$BRANCH" = "main" ] || [ "$BRANCH" = "master" ]; then
  exit 0
fi

LAST_COMMIT_FILES=$(git diff-tree --no-commit-id --name-only -r HEAD 2>/dev/null || echo "")

PHP_FILES=$(echo "$LAST_COMMIT_FILES" | grep -cE '\.php$|\.yml$|\.yaml$|\.json$' || echo "0")

if [ "$PHP_FILES" -eq 0 ]; then
  MD_FILES=$(echo "$LAST_COMMIT_FILES" | grep -cE '\.md$' || echo "0")
  if [ "$MD_FILES" -gt 0 ]; then
    TOTAL=$(echo "$LAST_COMMIT_FILES" | wc -l)
    if [ "$TOTAL" -eq "$MD_FILES" ]; then
      echo '{"decision":"deny","reason":"PR must include at least one non-documentation file (PHP, YAML, or JSON). Documentation-only PRs are not accepted."}'
      exit 2
    fi
  fi
fi

exit 0
