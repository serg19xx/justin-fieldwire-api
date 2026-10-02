#!/usr/bin/env bash
# Install LaunchAgent so FieldWire MySQL tunnel (127.0.0.1:3307) starts at login
# and restarts if SSH drops — no manual ./start-db-tunnel.sh.
#
# Script lives under ~/Library/Application Support/fieldwire/ (not Documents):
# macOS often blocks LaunchAgents from executing files under Documents/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_SUPPORT="$HOME/Library/Application Support/fieldwire"
KEEPALIVE="$APP_SUPPORT/db-tunnel-keepalive.sh"
LABEL="ca.medicalcontractor.fieldwire.db-tunnel"
PLIST="$HOME/Library/LaunchAgents/${LABEL}.plist"
LOG_DIR="$HOME/Library/Logs/fieldwire"
mkdir -p "$APP_SUPPORT" "$LOG_DIR"

chmod +x "$ROOT/scripts/start-db-tunnel.sh" 2>/dev/null || true

# Stable copy outside Documents (LaunchAgent-safe)
cat >"$KEEPALIVE" <<'EOF'
#!/bin/bash
# Keep local 3307 → hosting MySQL 3306 open (LaunchAgent-safe path).
set -euo pipefail
PORT="${FW_DB_TUNNEL_PORT:-3307}"
HOST_ALIAS="${FW_DB_SSH_HOST:-fwapi-hosting}"
exec /usr/bin/ssh \
  -N \
  -o BatchMode=yes \
  -o ExitOnForwardFailure=yes \
  -o ServerAliveInterval=30 \
  -o ServerAliveCountMax=3 \
  -o StrictHostKeyChecking=accept-new \
  -L "${PORT}:127.0.0.1:3306" \
  "$HOST_ALIAS"
EOF
chmod +x "$KEEPALIVE"

# Stop any one-shot background tunnel so LaunchAgent owns :3307
if lsof -nP -iTCP:3307 -sTCP:LISTEN >/dev/null 2>&1; then
  PIDS=$(lsof -nP -iTCP:3307 -sTCP:LISTEN -t 2>/dev/null || true)
  if [[ -n "${PIDS:-}" ]]; then
    echo "Stopping existing listeners on :3307: $PIDS"
    # shellcheck disable=SC2086
    kill $PIDS 2>/dev/null || true
    sleep 1
  fi
fi

cat >"$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>${LABEL}</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>${KEEPALIVE}</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
  <key>ThrottleInterval</key>
  <integer>10</integer>
  <key>StandardOutPath</key>
  <string>${LOG_DIR}/db-tunnel.log</string>
  <key>StandardErrorPath</key>
  <string>${LOG_DIR}/db-tunnel.err.log</string>
</dict>
</plist>
EOF

launchctl bootout "gui/$(id -u)/${LABEL}" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$PLIST"
launchctl enable "gui/$(id -u)/${LABEL}" 2>/dev/null || true
launchctl kickstart -k "gui/$(id -u)/${LABEL}" 2>/dev/null || true

sleep 2
if lsof -nP -iTCP:3307 -sTCP:LISTEN >/dev/null 2>&1; then
  echo "OK: FieldWire DB tunnel auto-starts (LaunchAgent ${LABEL})"
  echo "Listening: 127.0.0.1:3307 → hosting MySQL"
  echo "DBeaver: use yjyhtqh8_easyrx (MySQL 127.0.0.1:3307)"
  echo "Logs: ${LOG_DIR}/db-tunnel.err.log"
else
  echo "WARN: LaunchAgent loaded but :3307 not listening yet — check SSH key / network"
  echo "  tail -20 ${LOG_DIR}/db-tunnel.err.log"
  exit 1
fi
