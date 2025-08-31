<?php

require_once 'vendor/autoload.php';

use Twilio\Rest\Client;

// Load environment variables
$envFile = 'env.development';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

echo "Testing Twilio configuration...\n";

$accountSid = $_ENV['TWILIO_ACCOUNT_SID'] ?? '';
$authToken = $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
$fromNumber = $_ENV['TWILIO_PHONE_NUMBER'] ?? '';

echo "Account SID: " . substr($accountSid, 0, 10) . "...\n";
echo "Auth Token: " . substr($authToken, 0, 10) . "...\n";
echo "From Number: $fromNumber\n";

if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
    echo "ERROR: Twilio credentials are missing!\n";
    exit(1);
}

try {
    echo "Creating Twilio client...\n";
    $client = new Client($accountSid, $authToken);
    echo "Twilio client created successfully!\n";
    
    // Test sending a message
    $toNumber = '+16477012491';
    $message = "Test message from FieldWire API at " . date('Y-m-d H:i:s');
    
    echo "Sending test message to $toNumber...\n";
    
    $twilioMessage = $client->messages->create(
        $toNumber,
        [
            'from' => $fromNumber,
            'body' => $message
        ]
    );
    
    echo "Message sent successfully!\n";
    echo "Message SID: " . $twilioMessage->sid . "\n";
    echo "Status: " . $twilioMessage->status . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
}
