#!/bin/bash
# PreToolUse hook: block destructive commands and writes to /docs.
INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""')

if echo "$COMMAND" | grep -qE 'rm -rf /|mkfs|dd if=.*of=/dev/'; then
  echo '{"decision":"deny","reason":"Destructive command blocked."}'
  exit 2
fi

if echo "$COMMAND" | grep -qE '>.*\/docs\/|tee.*\/docs\/'; then
  echo '{"decision":"deny","reason":"/docs is read-only. Do not modify product documentation."}'
  exit 2
fi

exit 0