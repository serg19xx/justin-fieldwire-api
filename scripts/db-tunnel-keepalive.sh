#!/usr/bin/env bash
# Keep local 3307 → hosting MySQL 3306 open for DBeaver / local API.
# Used by LaunchAgent (foreground). Do not use -f here.
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
