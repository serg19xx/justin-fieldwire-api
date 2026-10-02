#!/usr/bin/env bash
# Optional one-shot tunnel. Prefer LaunchAgent (install once):
#   ./scripts/install-db-tunnel-launchagent.sh
set -euo pipefail
PORT="${1:-3307}"
if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Tunnel already listening on :$PORT"
  exit 0
fi
if ssh -G fwapi-hosting >/dev/null 2>&1; then
  ssh -f -N \
    -o BatchMode=yes \
    -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 \
    -o ServerAliveCountMax=3 \
    -L "${PORT}:127.0.0.1:3306" \
    fwapi-hosting
else
  ssh -f -N \
    -o BatchMode=yes \
    -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 \
    -o ServerAliveCountMax=3 \
    -p 27 \
    -L "${PORT}:127.0.0.1:3306" \
    yjyhtqh8@173.209.33.163
fi
echo "SSH DB tunnel up: 127.0.0.1:${PORT} -> hosting:3306"
echo "Tip: ./scripts/install-db-tunnel-launchagent.sh — starts at every login."
