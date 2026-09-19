#!/bin/bash
set -e
export OPENHANDS_PROJECT_DIR="$PWD"

if [ -d "core" ]; then
  (cd core && make setup 2>/dev/null || true)
fi

if [ -d "frontends" ] && command -v pnpm >/dev/null; then
  (cd frontends && pnpm install --frozen-lockfile 2>/dev/null || true)
fi

if [ -d "mobile" ] && command -v flutter >/dev/null; then
  (cd mobile && flutter pub get 2>/dev/null || true)
fi

chmod +x .openhands/hooks/*.sh 2>/dev/null || true

if [ ! -f "docs/Features.md" ]; then
  echo "[setup] WARNING: docs/Features.md not found."
fi

if [ ! -f "docs/INDEX.md" ]; then
  echo "[setup] WARNING: docs/INDEX.md not found."
fi

echo "[setup] Setup complete."