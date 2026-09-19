#!/bin/bash
set -e
export OPENHANDS_PROJECT_DIR="$PWD"

if [ ! -f "public/index.php" ]; then
  echo "[setup] WARNING: public/index.php not found. Expected PHP project root."
fi

if [ ! -f "docs/Features.md" ] && [ ! -f "documents/Features.md" ]; then
  echo "[setup] WARNING: Features.md not found in docs/ or documents/"
fi

if [ ! -f "AGENTS.md" ]; then
  echo "[setup] WARNING: AGENTS.md not found."
fi

if [ ! -d "vendor" ]; then
  echo "[setup] NOTE: vendor/ not found. Run composer install if needed."
fi

chmod +x .openhands/hooks/*.sh 2>/dev/null || true

echo "[setup] Setup complete."