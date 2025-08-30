#!/bin/bash

# FieldWire API - FTP Deployment Script
# This script automatically deploys the project via FTP

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# FTP Configuration (will be prompted if not set)
FTP_HOST="ftp.medicalcontractor.ca"
FTP_USER="fw-api@medicalcontractor.ca"
FTP_PASS="Medeli@AKX10"
FTP_PATH="/home/yjyhtqh8/fwapi.medicalcontractor.ca"

echo -e "${BLUE}🚀 FieldWire API - FTP Deployment${NC}"
echo -e "${BLUE}===============================${NC}"
echo ""

# Check if we're in the project root
if [ ! -f "composer.json" ]; then
    echo -e "${RED}❌ Error: composer.json not found. Please run this script from the project root.${NC}"
    exit 1
fi

# Function to prompt for FTP credentials
prompt_ftp_credentials() {
    echo -e "${BLUE}📡 FTP Configuration${NC}"
    echo -e "${YELLOW}Please provide your FTP credentials:${NC}"
    
    read -p "FTP Host (e.g., medicalcontractor.ca): " FTP_HOST
    read -p "FTP Username: " FTP_USER
    read -s -p "FTP Password: " FTP_PASS
    echo ""
    
    # Save credentials for this session
    export FTP_HOST
    export FTP_USER
    export FTP_PASS
}

# Check if credentials are provided as environment variables
if [ -z "$FTP_HOST" ] || [ -z "$FTP_USER" ] || [ -z "$FTP_PASS" ]; then
    prompt_ftp_credentials
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

# Step 3: Upload via FTP
echo -e "${BLUE}📤 Step 3: Uploading files via FTP...${NC}"

# Upload files using curl
echo -e "${BLUE}   Uploading project archive...${NC}"
curl -T fieldwire-api-production.tar.gz ftp://${FTP_HOST}/ --user ${FTP_USER}:${FTP_PASS}

echo -e "${BLUE}   Uploading deployment script...${NC}"
curl -T deploy-on-server.sh ftp://${FTP_HOST}/ --user ${FTP_USER}:${FTP_PASS}

echo -e "${BLUE}   Uploading configuration...${NC}"
curl -T env.production ftp://${FTP_HOST}/ --user ${FTP_USER}:${FTP_PASS}

echo -e "${GREEN}✅ Files uploaded successfully${NC}"
echo ""

# Step 4: Create SSH deployment instructions
echo -e "${BLUE}📋 Step 4: Creating deployment instructions...${NC}"

cat > DEPLOY_NEXT_STEPS.txt << 'EOF'
🚀 FieldWire API - Next Steps for Deployment
============================================

✅ Files uploaded successfully via FTP!

🔧 Next steps on server:

1. SSH to your server:
   ssh yjyhtqh8_fieldwire@medicalcontractor.ca

2. Navigate to project directory:
   cd /home/yjyhtqh8_fieldwire/public_html/fwapi

3. Run deployment script:
   ./deploy-on-server.sh

4. Test your API:
   curl https://fwapi.medicalcontractor.ca/api/v1/health

🌐 Your API will be available at:
   • https://fwapi.medicalcontractor.ca/api/v1/health
   • https://fwapi.medicalcontractor.ca/api/v1/version
   • https://fwapi.medicalcontractor.ca/api/v1/database/tables
   • https://fwapi.medicalcontractor.ca/api/docs

📞 For help, see: PRODUCTION_DEPLOY.md
EOF

echo -e "${GREEN}✅ Deployment instructions created${NC}"
echo ""

echo -e "${GREEN}🎉 FTP deployment completed!${NC}"
echo ""
echo -e "${BLUE}📋 Summary:${NC}"
echo -e "   • Files uploaded to: ${FTP_HOST}${FTP_PATH}"
echo -e "   • Next step: SSH to server and run ./deploy-on-server.sh"
echo ""
echo -e "${YELLOW}📤 Next steps:${NC}"
echo -e "   1. SSH to server: ssh yjyhtqh8_fieldwire@medicalcontractor.ca"
echo -e "   2. Navigate: cd /home/yjyhtqh8_fieldwire/public_html/fwapi"
echo -e "   3. Run: ./deploy-on-server.sh"
echo -e "   4. Test your API endpoints"
echo ""
echo -e "${BLUE}📞 See DEPLOY_NEXT_STEPS.txt for detailed instructions${NC}"
