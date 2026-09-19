#!/bin/bash
# Stop hook: enforce tests, lint, and docblocks before the agent can finish.
cd "${OPENHANDS_PROJECT_DIR:-$PWD}"

FAILED=0

find app/ public/ -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors" || true

if [ -f "phpunit.xml.dist" ]; then
  if command -v phpunit >/dev/null 2>&1; then
    phpunit --configuration phpunit.xml.dist || FAILED=1
  elif [ -f "vendor/bin/phpunit" ]; then
    vendor/bin/phpunit --configuration phpunit.xml.dist || FAILED=1
  fi
fi

if [ "$FAILED" -eq 1 ]; then
  echo '{"decision":"deny","reason":"Quality gates failed. Fix syntax/lint/tests before finishing."}'
  exit 2
fi

exit 0