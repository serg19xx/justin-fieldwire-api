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
