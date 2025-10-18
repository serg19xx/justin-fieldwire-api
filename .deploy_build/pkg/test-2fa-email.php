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

echo "=== Testing 2FA Verification Email ===\n\n";

// Test data
$email = 'serg.kostyuk@gmail.com';
$userName = 'Mike Davis';
$code = '123456';

echo "Email: $email\n";
echo "User: $userName\n";
echo "Code: $code\n\n";

echo "Sending 2FA verification email...\n";

$emailService = new EmailService($logger);
$result = $emailService->sendVerificationCode($email, $code, $userName);

if ($result) {
    echo "\n✅ 2FA email sent successfully!\n";
    echo "\nCheck email inbox (including spam folder) for: $email\n";
} else {
    echo "\n❌ Failed to send 2FA email\n";
}

echo "\nDone!\n";

