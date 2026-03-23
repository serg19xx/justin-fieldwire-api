#!/bin/bash
cd /Users/justinkearney/Documents/Projects/Justin/fieldwire-api

echo "Copying updated files to .deploy_build..."
cp -f ./src/Middleware/AuthMiddleware.php ./.deploy_build/pkg/src/Middleware/AuthMiddleware.php
cp -f ./src/Controllers/AuthController.php ./.deploy_build/pkg/src/Controllers/AuthController.php
cp -f ./src/Routes/ApiRoutes.php ./.deploy_build/pkg/src/Routes/ApiRoutes.php
cp -f ./src/Controllers/TwoFactorController.php ./.deploy_build/pkg/src/Controllers/TwoFactorController.php
cp -f ./src/Controllers/TaskTemplateController.php ./.deploy_build/pkg/src/Controllers/TaskTemplateController.php
cp -f ./src/Services/EmailService.php ./.deploy_build/pkg/src/Services/EmailService.php

echo "Regenerating autoload..."
composer dump-autoload -q

echo "Restarting server..."
pkill -9 php
sleep 2
php -S localhost:8000 -t public > /dev/null 2>&1 &

echo "Done! Server restarted with PID $!"
echo "Wait 3 seconds and refresh the page"

