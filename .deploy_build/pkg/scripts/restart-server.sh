#!/bin/bash

# FieldWire API - Restart Server Script
# Usage: ./scripts/restart-server.sh [port]

set -e

# Default port
PORT=${1:-8000}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔄 Restarting FieldWire API Server...${NC}"

# Get the directory of this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Change to project directory
cd "$PROJECT_DIR"

# Stop the server
echo -e "${YELLOW}📥 Stopping server...${NC}"
if [ -f "scripts/stop-server.sh" ]; then
    ./scripts/stop-server.sh $PORT
else
    echo -e "${RED}❌ stop-server.sh not found!${NC}"
    exit 1
fi

# Wait a moment
sleep 2

# Start the server
echo -e "${YELLOW}📤 Starting server...${NC}"
if [ -f "scripts/start-server.sh" ]; then
    ./scripts/start-server.sh $PORT
else
    echo -e "${RED}❌ start-server.sh not found!${NC}"
    exit 1
fi
