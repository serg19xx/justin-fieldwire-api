<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\EmailService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Setup logger
$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

echo "=== Testing Worker Invitation Email ===\n\n";

// Check environment
echo "Environment: " . ($_ENV['APP_ENV'] ?? 'not set') . "\n";
echo "SendGrid API Key: " . (isset($_ENV['SENDGRID_API_KEY']) ? 'SET (' . strlen($_ENV['SENDGRID_API_KEY']) . ' chars)' : 'NOT SET') . "\n";
echo "SendGrid From Email: " . ($_ENV['SENDGRID_FROM_EMAIL'] ?? 'NOT SET') . "\n";
echo "Frontend URL: " . ($_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'NOT SET') . "\n\n";

// Test data
$email = 'serg.kostyuk@gmail.com';
$firstName = 'Test';
$lastName = 'Worker';
$invitationToken = 'test-token-' . time();
$tempPassword = 'TestPass123';

echo "Sending invitation to: $email\n";
echo "Name: $firstName $lastName\n";
echo "Token: $invitationToken\n";
echo "Temp Password: $tempPassword\n\n";

echo "Initializing EmailService...\n";
$emailService = new EmailService($logger);

echo "Calling sendWorkerInvitation...\n";
$result = $emailService->sendWorkerInvitation(
    $email,
    $firstName,
    $lastName,
    $invitationToken,
    'auto', // provider
    $tempPassword
);

if ($result) {
    echo "\n✅ Invitation email sent successfully!\n";
    echo "\nCheck email inbox (including spam folder) for: $email\n";
} else {
    echo "\n❌ Failed to send invitation email\n";
    echo "\nCheck logs above for error details\n";
}

echo "\nDone!\n";

