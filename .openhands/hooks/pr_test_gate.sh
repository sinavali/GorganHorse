#!/bin/bash
# Stop hook: if a PR is open, require that the last commit contains tests.
cd "${OPENHANDS_PROJECT_DIR:-$PWD}"

BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")
if [ -z "$BRANCH" ] || [ "$BRANCH" = "main" ] || [ "$BRANCH" = "master" ]; then
  exit 0
fi

LAST_COMMIT_FILES=$(git diff-tree --no-commit-id --name-only -r HEAD 2>/dev/null || echo "")
HAS_TESTS=$(echo "$LAST_COMMIT_FILES" | grep -cE 'test|spec|_test|\.test\.' || echo "0")

if [ "$HAS_TESTS" -eq 0 ]; then
  CODE_FILES=$(echo "$LAST_COMMIT_FILES" | grep -vE 'test|spec|\.md$' | head -1)
  if [ -n "$CODE_FILES" ]; then
    echo '{"decision":"deny","reason":"PR must include Unit + Integration tests for business logic as the LAST step. Add tests before finishing."}'
    exit 2
  fi
fi

exit 0