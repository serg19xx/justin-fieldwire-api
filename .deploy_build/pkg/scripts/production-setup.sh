#!/bin/bash

# FieldWire API Production Server Setup Script
# Run this script on your production server after uploading files

set -e

echo "🚀 FieldWire API - Production Server Setup"
echo "=========================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check if we're in the project root
if [ ! -f "composer.json" ]; then
    echo -e "${RED}❌ Error: composer.json not found. Please run this script from the project root.${NC}"
    exit 1
fi

echo -e "${BLUE}🔧 Setting up production environment...${NC}"

# Check if .env exists
if [ ! -f ".env" ]; then
    if [ -f "env.production" ]; then
        cp env.production .env
        echo -e "${GREEN}✅ Production configuration loaded${NC}"
        echo -e "${YELLOW}⚠️  Please edit .env file with your production database settings${NC}"
    else
        echo -e "${RED}❌ No .env or env.production file found${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✅ .env file already exists${NC}"
fi

echo -e "${BLUE}📦 Installing production dependencies...${NC}"
composer install --no-dev --optimize-autoloader

echo -e "${BLUE}📁 Creating required directories...${NC}"
mkdir -p logs
mkdir -p public/uploads/avatars
chmod 755 logs
chmod 755 public/uploads
chmod 755 public/uploads/avatars

echo -e "${BLUE}🔒 Setting proper permissions...${NC}"
chmod 644 .env
chmod 755 scripts/*.sh

echo -e "${BLUE}🗄️  Setting up database...${NC}"
if [ -f "scripts/setup-database.php" ]; then
    php scripts/setup-database.php
    echo -e "${GREEN}✅ Database setup completed${NC}"
else
    echo -e "${YELLOW}⚠️  Database setup script not found${NC}"
fi

echo -e "${BLUE}🧪 Testing API endpoints...${NC}"
echo -e "${YELLOW}Testing health endpoint...${NC}"
if curl -s http://localhost/api/v1/health > /dev/null; then
    echo -e "${GREEN}✅ Health endpoint working${NC}"
else
    echo -e "${RED}❌ Health endpoint failed${NC}"
fi

echo -e "${GREEN}🎉 Production server setup completed!${NC}"
echo ""
echo -e "${BLUE}📋 Next steps:${NC}"
echo "1. Configure nginx with the provided nginx.conf"
echo "2. Set up SSL certificate (Let's Encrypt recommended)"
echo "3. Test all endpoints from external domain"
echo "4. Monitor logs for any errors"
echo ""
echo -e "${BLUE}🌐 Production endpoints:${NC}"
echo "- Health check: https://fieldwire.medicalcontractor.ca/api/v1/health"
echo "- API docs: https://fieldwire.medicalcontractor.ca/api/docs"
echo "- Swagger UI: https://fieldwire.medicalcontractor.ca/docs"
echo "- Swagger JSON: https://fieldwire.medicalcontractor.ca/swagger.json"
echo ""
echo -e "${YELLOW}⚠️  Important: Make sure nginx is configured correctly!${NC}"
echo -e "${YELLOW}⚠️  Check nginx error logs if endpoints don't work${NC}"
