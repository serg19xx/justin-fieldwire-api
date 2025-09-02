#!/bin/bash

# FieldWire API Deployment Script
# This script prepares the project for production deployment

set -e

echo "🚀 FieldWire API - Production Deployment Preparation"
echo "=================================================="

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

echo -e "${BLUE}📦 Installing production dependencies...${NC}"
composer install --no-dev --optimize-autoloader

echo -e "${BLUE}🔧 Setting up production environment...${NC}"
if [ -f "env.production" ]; then
    echo -e "${GREEN}✅ Production configuration found${NC}"
    if [ ! -f ".env" ]; then
        cp env.production .env
        echo -e "${GREEN}✅ Production configuration loaded${NC}"
        echo -e "${YELLOW}⚠️  Please edit .env file with your production settings${NC}"
    else
        echo -e "${YELLOW}⚠️  .env already exists. Please manually update with production settings${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  env.production not found. Please create it or edit .env manually${NC}"
fi

echo -e "${BLUE}📁 Creating required directories...${NC}"
mkdir -p logs
mkdir -p public/uploads/avatars
chmod 755 logs
chmod 755 public/uploads
chmod 755 public/uploads/avatars

echo -e "${BLUE}🔒 Setting proper permissions...${NC}"
chmod 644 .env
chmod 755 scripts/*.sh

echo -e "${BLUE}🧪 Running tests...${NC}"
if composer test > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Tests passed${NC}"
else
    echo -e "${YELLOW}⚠️  Some tests failed, but continuing deployment${NC}"
fi

echo -e "${BLUE}📋 Checking code style...${NC}"
if composer cs-check > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Code style check passed${NC}"
else
    echo -e "${YELLOW}⚠️  Code style issues found, but continuing deployment${NC}"
fi

echo -e "${GREEN}🎉 Production deployment preparation completed!${NC}"
echo ""
echo -e "${BLUE}📋 Next steps for production:${NC}"
echo "1. Upload files to your production server"
echo "2. Configure nginx with the provided nginx.conf"
echo "3. Update .env with production database settings (DB_HOST=localhost)"
echo "4. Run: composer db:setup"
echo "5. Test your endpoints"
echo ""
echo -e "${BLUE}🌐 Production endpoints:${NC}"
echo "- Health check: https://fieldwire.medicalcontractor.ca/api/v1/health"
echo "- API docs: https://fieldwire.medicalcontractor.ca/api/docs"
echo "- Swagger UI: https://fieldwire.medicalcontractor.ca/docs"
echo "- Swagger JSON: https://fieldwire.medicalcontractor.ca/swagger.json"
echo ""
echo -e "${YELLOW}⚠️  Remember: Production uses localhost database!${NC}"
echo -e "${YELLOW}⚠️  Make sure nginx is configured with the provided nginx.conf${NC}"
