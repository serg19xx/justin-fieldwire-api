<?php

require_once 'vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "Testing environment variables...\n\n";

echo "APP_ENV: " . ($_ENV['APP_ENV'] ?? 'NOT_SET') . "\n";
echo "TWILIO_ACCOUNT_SID: " . ($_ENV['TWILIO_ACCOUNT_SID'] ?? 'NOT_SET') . "\n";
echo "TWILIO_AUTH_TOKEN: " . ($_ENV['TWILIO_AUTH_TOKEN'] ?? 'NOT_SET') . "\n";
echo "TWILIO_PHONE_NUMBER: " . ($_ENV['TWILIO_PHONE_NUMBER'] ?? 'NOT_SET') . "\n\n";

// Check if Twilio credentials are complete
$accountSid = $_ENV['TWILIO_ACCOUNT_SID'] ?? '';
$authToken = $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
$fromNumber = $_ENV['TWILIO_PHONE_NUMBER'] ?? '';

echo "Validation:\n";
echo "- Account SID length: " . strlen($accountSid) . "\n";
echo "- Auth Token length: " . strlen($authToken) . "\n";
echo "- Phone Number: " . $fromNumber . "\n";
echo "- All credentials present: " . (empty($accountSid) || empty($authToken) || empty($fromNumber) ? 'NO' : 'YES') . "\n";

if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
    echo "\n❌ ERROR: Some Twilio credentials are missing!\n";
    exit(1);
} else {
    echo "\n✅ All Twilio credentials are present!\n";
}
