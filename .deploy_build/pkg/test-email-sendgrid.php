<?php

require_once 'vendor/autoload.php';
use App\Services\EmailService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create logger
$logger = new Logger('email-test');
$logger->pushHandler(new StreamHandler('logs/app.log', Logger::INFO));

// Create EmailService
$emailService = new EmailService($logger);

echo "=== Testing SendGrid API ===\n";

// Test SendGrid API
$result = $emailService->sendVerificationCode(
    'serg.kostyuk@gmail.com',
    '123456',
    'Test User',
    'sendgrid'  // Force SendGrid API
);

if ($result) {
    echo "✅ SendGrid API: Email sent successfully!\n";
} else {
    echo "❌ SendGrid API: Failed to send email\n";
}

echo "\n=== Testing PHPMailer SMTP ===\n";

// Test PHPMailer SMTP
$result = $emailService->sendVerificationCode(
    'serg.kostyuk@gmail.com',
    '654321',
    'Test User',
    'phpmailer'  // Force PHPMailer SMTP
);

if ($result) {
    echo "✅ PHPMailer SMTP: Email sent successfully!\n";
} else {
    echo "❌ PHPMailer SMTP: Failed to send email\n";
}

echo "\n=== Test completed ===\n";
