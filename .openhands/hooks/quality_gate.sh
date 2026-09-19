#!/bin/bash
# Stop hook: enforce tests, lint, and docblocks before the agent can finish.
cd "${OPENHANDS_PROJECT_DIR:-$PWD}"

FAILED=0

if [ -d "core" ]; then
  (cd core && make test 2>&1) || FAILED=1
  (cd core && make lint 2>&1) || FAILED=1
fi

if [ -d "frontends" ]; then
  (cd frontends && pnpm -r test 2>&1) || FAILED=1
  (cd frontends && pnpm -r lint 2>&1) || FAILED=1
fi

if [ -d "mobile" ]; then
  (cd mobile && flutter test 2>&1) || FAILED=1
  (cd mobile && flutter analyze 2>&1) || FAILED=1
fi

if [ "$FAILED" -eq 1 ]; then
  echo '{"decision":"deny","reason":"Quality gates failed. Fix tests/lint before finishing."}'
  exit 2
fi

exit 0