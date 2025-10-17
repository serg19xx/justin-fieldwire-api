#!/bin/bash
cd /Users/justinkearney/Documents/Projects/Justin/fieldwire-api

echo "Killing PHP server..."
pkill -9 php

echo "Waiting..."
sleep 2

echo "Starting PHP server..."
php -S localhost:8000 -t public > /dev/null 2>&1 &

echo "Server restarted with PID $!"
echo "Ready to test!"

