#!/bin/bash

# FieldWire API Server Start Script
# Automatically detects environment and loads appropriate configuration

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default port
PORT=${1:-8000}

# Check if we're in the project root
if [ ! -f "composer.json" ]; then
    echo -e "${RED}❌ Error: composer.json not found. Please run this script from the project root.${NC}"
    exit 1
fi

echo -e "${BLUE}🚀 Starting FieldWire API Server...${NC}"

# Environment detection
if [ -f ".env" ]; then
    echo -e "${GREEN}✅ Using existing .env configuration${NC}"
elif [ -f "env.development" ]; then
    echo -e "${YELLOW}⚠️  No .env found, copying development configuration${NC}"
    cp env.development .env
    echo -e "${GREEN}✅ Development configuration loaded${NC}"
else
    echo -e "${YELLOW}⚠️  No .env found, copying example configuration${NC}"
    cp env.example .env
    echo -e "${GREEN}✅ Example configuration loaded${NC}"
    echo -e "${YELLOW}⚠️  Please edit .env file with your database settings${NC}"
fi

# Create logs directory if it doesn't exist
if [ ! -d "logs" ]; then
    mkdir -p logs
    echo -e "${GREEN}✅ Created logs directory${NC}"
fi

# Check if port is available
if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null ; then
    echo -e "${RED}❌ Port $PORT is already in use${NC}"
    echo -e "${YELLOW}💡 Try: ./scripts/start-server.sh 8080${NC}"
    exit 1
fi

echo -e "${GREEN}🌐 Server starting on http://localhost:$PORT${NC}"
echo -e "${BLUE}📋 Available endpoints:${NC}"
echo -e "   • http://localhost:$PORT/api/v1/health${NC}"
echo -e "   • http://localhost:$PORT/api/v1/version${NC}"
echo -e "   • http://localhost:$PORT/api${NC}"
echo -e "   • http://localhost:$PORT/api/docs${NC}"
echo -e "${YELLOW}🛑 Press Ctrl+C to stop the server${NC}"

# Start PHP development server (listen on both IPv4 and IPv6)
# Use public/php.ini so fax/document uploads respect 52M upload_max_filesize.
php -c public/php.ini -S [::]:$PORT -t public
