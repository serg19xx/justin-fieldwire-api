#!/bin/bash

# FieldWire API - Stop Server Script
# Usage: ./scripts/stop-server.sh [port]

set -e

# Default port
PORT=${1:-8000}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🛑 Stopping FieldWire API Server...${NC}"

# Find and kill PHP server processes
PIDS=$(lsof -ti:$PORT 2>/dev/null || true)

if [ -z "$PIDS" ]; then
    echo -e "${YELLOW}⚠️  No server found running on port $PORT${NC}"
    exit 0
fi

# Kill the processes
for PID in $PIDS; do
    echo -e "${YELLOW}🔫 Killing process $PID...${NC}"
    kill -TERM $PID 2>/dev/null || true
done

# Wait a moment and force kill if necessary
sleep 2
PIDS=$(lsof -ti:$PORT 2>/dev/null || true)

if [ ! -z "$PIDS" ]; then
    echo -e "${YELLOW}⚠️  Force killing remaining processes...${NC}"
    for PID in $PIDS; do
        kill -KILL $PID 2>/dev/null || true
    done
fi

# Verify server is stopped
if lsof -ti:$PORT >/dev/null 2>&1; then
    echo -e "${RED}❌ Failed to stop server on port $PORT${NC}"
    exit 1
else
    echo -e "${GREEN}✅ Server stopped successfully on port $PORT${NC}"
fi
