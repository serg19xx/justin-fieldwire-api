#!/bin/bash

# FieldWire API - Simple Deployment Script
# This script prepares everything for manual upload

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 FieldWire API - Simple Deployment Preparation${NC}"
echo -e "${BLUE}==============================================${NC}"
echo ""

# Check if we're in the project root
if [ ! -f "composer.json" ]; then
    echo -e "${RED}❌ Error: composer.json not found. Please run this script from the project root.${NC}"
    exit 1
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

# Step 2: Create deployment script for server
echo -e "${BLUE}📝 Step 2: Creating server deployment script...${NC}"

cat > deploy-on-server.sh << 'EOF'
#!/bin/bash

# FieldWire API - Server Deployment Script
# Run this script on the server after uploading files

set -e

echo "🚀 FieldWire API - Server Deployment"
echo "===================================="

# Extract project files
echo "📦 Extracting project files..."
tar -xzf fieldwire-api-production.tar.gz

# Set up configuration
echo "🔧 Setting up configuration..."
cp env.production .env

# Install dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Create required directories
echo "📁 Creating required directories..."
mkdir -p logs public/uploads

# Set permissions
echo "🔒 Setting permissions..."
chmod 644 .env
chmod 755 logs public/uploads scripts/*.sh

# Set up database
echo "🗄️ Setting up database..."
composer db:setup

# Clean up
echo "🧹 Cleaning up..."
rm fieldwire-api-production.tar.gz

echo "✅ Deployment completed successfully!"
echo ""
echo "🌐 Test your API:"
echo "   • Health: https://fwapi.medicalcontractor.ca/api/v1/health"
echo "   • Tables: https://fwapi.medicalcontractor.ca/api/v1/database/tables"
echo "   • Docs: https://fwapi.medicalcontractor.ca/api/docs"
EOF

chmod +x deploy-on-server.sh

echo -e "${GREEN}✅ Server deployment script created${NC}"
echo ""

# Step 3: Create instructions
echo -e "${BLUE}📋 Step 3: Creating deployment instructions...${NC}"

cat > DEPLOY_INSTRUCTIONS.txt << 'EOF'
🚀 FieldWire API - Deployment Instructions
==========================================

📦 Files ready for upload:
1. fieldwire-api-production.tar.gz (project archive)
2. deploy-on-server.sh (deployment script)
3. env.production (configuration file)

📤 Upload to server:
1. Connect to your server via FTP/SFTP
2. Navigate to: /home/yjyhtqh8_fieldwire/public_html/fwapi
3. Upload all three files

🔧 Deploy on server:
1. SSH to your server
2. Navigate to: /home/yjyhtqh8_fieldwire/public_html/fwapi
3. Run: ./deploy-on-server.sh

🌐 Test after deployment:
- Health: https://fwapi.medicalcontractor.ca/api/v1/health
- Tables: https://fwapi.medicalcontractor.ca/api/v1/database/tables
- Docs: https://fwapi.medicalcontractor.ca/api/docs

📞 For help, see: PRODUCTION_DEPLOY.md
EOF

echo -e "${GREEN}✅ Deployment instructions created${NC}"
echo ""

echo -e "${GREEN}🎉 Simple deployment preparation completed!${NC}"
echo ""
echo -e "${BLUE}📦 Files ready for upload:${NC}"
echo -e "   • fieldwire-api-production.tar.gz"
echo -e "   • deploy-on-server.sh"
echo -e "   • env.production"
echo -e "   • DEPLOY_INSTRUCTIONS.txt"
echo ""
echo -e "${YELLOW}📤 Next steps:${NC}"
echo -e "   1. Upload files to server via FTP/SFTP"
echo -e "   2. SSH to server and run: ./deploy-on-server.sh"
echo -e "   3. Test your API endpoints"
echo ""
echo -e "${BLUE}📞 For automated deployment, use: ./scripts/auto-deploy.sh${NC}"
