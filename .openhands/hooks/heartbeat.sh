#!/bin/bash
# SessionStart hook: check connectivity and signal the sync queue flush.
cd "${OPENHANDS_PROJECT_DIR:-$PWD}"
SYNC_QUEUE=".openhands/sync_queue.json"

if curl -s -o /dev/null -w "%{http_code}" --max-time 5 https://api.github.com/rate_limit 2>/dev/null | grep -q "200"; then
  if [ -f "$SYNC_QUEUE" ] && [ -s "$SYNC_QUEUE" ]; then
    echo "[heartbeat] GitHub reachable. Flushing sync queue..."
  fi
  echo "[heartbeat] GitHub reachable."
else
  echo "[heartbeat] GitHub unreachable. Working offline. Queue will flush on reconnect."
fi

exit 0