#!/bin/bash

# FieldWire API - Automatic Production Deployment Script
# This script automatically deploys the project to fwapi.medicalcontractor.ca

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SERVER_HOST="medicalcontractor.ca"
SERVER_USER="yjyhtqh8_fieldwire"
SERVER_PATH="/home/yjyhtqh8/fwapi.medicalcontractor.ca"
DOMAIN="fwapi.medicalcontractor.ca"

echo -e "${BLUE}🚀 FieldWire API - Automatic Production Deployment${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Check if we're in the project root
if [ ! -f "composer.json" ]; then
    echo -e "${RED}❌ Error: composer.json not found. Please run this script from the project root.${NC}"
    exit 1
fi

# Check if SSH key is available
if [ ! -f ~/.ssh/id_rsa ]; then
    echo -e "${YELLOW}⚠️  SSH key not found. Please ensure you have SSH access to the server.${NC}"
    echo -e "${YELLOW}   You may need to set up SSH keys or use password authentication.${NC}"
fi

# Step 1: Prepare the project
echo -e "${BLUE}📦 Step 1: Preparing project for deployment...${NC}"

# Install production dependencies
echo -e "${BLUE}   Installing production dependencies...${NC}"
composer install --no-dev --optimize-autoloader

# Create production archive
echo -e "${BLUE}   Creating production archive...${NC}"
tar --exclude='.git' --exclude='node_modules' --exclude='tests' --exclude='*.log' --exclude='.env' --exclude='fieldwire-api-production.tar.gz' -czf fieldwire-api-production.tar.gz .

echo -e "${GREEN}✅ Project prepared successfully${NC}"
echo ""

# Step 2: Upload to server
echo -e "${BLUE}📤 Step 2: Uploading to server...${NC}"

# Create remote directory if it doesn't exist
echo -e "${BLUE}   Creating remote directory...${NC}"
ssh ${SERVER_USER}@${SERVER_HOST} "mkdir -p ${SERVER_PATH}"

# Upload archive
echo -e "${BLUE}   Uploading project archive...${NC}"
scp fieldwire-api-production.tar.gz ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/

echo -e "${GREEN}✅ Upload completed successfully${NC}"
echo ""

# Step 3: Deploy on server
echo -e "${BLUE}🔧 Step 3: Deploying on server...${NC}"

# Execute deployment commands on server
ssh ${SERVER_USER}@${SERVER_HOST} << 'ENDSSH'
cd /home/yjyhtqh8_fieldwire/public_html/fwapi

echo "📦 Extracting project files..."
tar -xzf fieldwire-api-production.tar.gz

echo "🔧 Setting up configuration..."
cp env.production .env

echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "📁 Creating required directories..."
mkdir -p logs public/uploads

echo "🔒 Setting permissions..."
chmod 644 .env
chmod 755 logs public/uploads scripts/*.sh

echo "🗄️ Setting up database..."
composer db:setup

echo "🧹 Cleaning up..."
rm fieldwire-api-production.tar.gz

echo "✅ Deployment completed on server!"
ENDSSH

echo -e "${GREEN}✅ Server deployment completed successfully${NC}"
echo ""

# Step 4: Test deployment
echo -e "${BLUE}🧪 Step 4: Testing deployment...${NC}"

# Wait a moment for server to process
sleep 5

# Test endpoints
echo -e "${BLUE}   Testing health endpoint...${NC}"
if curl -s -f "https://${DOMAIN}/api/v1/health" > /dev/null; then
    echo -e "${GREEN}✅ Health endpoint working${NC}"
else
    echo -e "${YELLOW}⚠️  Health endpoint not responding (may need SSL setup)${NC}"
fi

echo -e "${BLUE}   Testing database endpoint...${NC}"
if curl -s -f "https://${DOMAIN}/api/v1/database/tables" > /dev/null; then
    echo -e "${GREEN}✅ Database endpoint working${NC}"
else
    echo -e "${YELLOW}⚠️  Database endpoint not responding${NC}"
fi

echo ""
echo -e "${GREEN}🎉 Automatic deployment completed!${NC}"
echo ""
echo -e "${BLUE}📋 Deployment Summary:${NC}"
echo -e "   • Server: ${SERVER_HOST}"
echo -e "   • Domain: ${DOMAIN}"
echo -e "   • Path: ${SERVER_PATH}"
echo ""
echo -e "${BLUE}🌐 Test your API:${NC}"
echo -e "   • Health: https://${DOMAIN}/api/v1/health"
echo -e "   • Tables: https://${DOMAIN}/api/v1/database/tables"
echo -e "   • Docs: https://${DOMAIN}/api/docs"
echo ""
echo -e "${YELLOW}⚠️  Next steps:${NC}"
echo -e "   1. Set up SSL certificate (if not already done)"
echo -e "   2. Configure web server (Apache/Nginx)"
echo -e "   3. Test CORS with your frontend"
echo ""
echo -e "${BLUE}📞 For troubleshooting, see: PRODUCTION_DEPLOY.md${NC}"
