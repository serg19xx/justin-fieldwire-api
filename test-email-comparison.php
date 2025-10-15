<?php

require_once 'vendor/autoload.php';
use App\Services\EmailService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Create logger
$logger = new Logger('email-comparison');
$logger->pushHandler(new StreamHandler('logs/app.log', Logger::INFO));

// Create EmailService
$emailService = new EmailService($logger);

echo "=== Email Service Comparison Test ===\n\n";

// Test 1: SendGrid API
echo "1. Testing SendGrid API...\n";
$startTime = microtime(true);
$sendgridResult = $emailService->sendVerificationCode(
    'serg.kostyuk@gmail.com',
    'SENDGRID123',
    'SendGrid Test',
    'sendgrid'
);
$sendgridTime = microtime(true) - $startTime;

echo "   Result: " . ($sendgridResult ? "✅ Success" : "❌ Failed") . "\n";
echo "   Time: " . round($sendgridTime * 1000, 2) . "ms\n\n";

// Test 2: PHPMailer SMTP
echo "2. Testing PHPMailer SMTP...\n";
$startTime = microtime(true);
$smtpResult = $emailService->sendVerificationCode(
    'serg.kostyuk@gmail.com',
    'SMTP123456',
    'SMTP Test',
    'phpmailer'
);
$smtpTime = microtime(true) - $startTime;

echo "   Result: " . ($smtpResult ? "✅ Success" : "❌ Failed") . "\n";
echo "   Time: " . round($smtpTime * 1000, 2) . "ms\n\n";

// Test 3: Auto (default)
echo "3. Testing Auto (default)...\n";
$startTime = microtime(true);
$autoResult = $emailService->sendVerificationCode(
    'serg.kostyuk@gmail.com',
    'AUTO789',
    'Auto Test',
    'auto'
);
$autoTime = microtime(true) - $startTime;

echo "   Result: " . ($autoResult ? "✅ Success" : "❌ Failed") . "\n";
echo "   Time: " . round($autoTime * 1000, 2) . "ms\n\n";

// Summary
echo "=== Summary ===\n";
echo "SendGrid API: " . ($sendgridResult ? "✅" : "❌") . " (" . round($sendgridTime * 1000, 2) . "ms)\n";
echo "PHPMailer SMTP: " . ($smtpResult ? "✅" : "❌") . " (" . round($smtpTime * 1000, 2) . "ms)\n";
echo "Auto (default): " . ($autoResult ? "✅" : "❌") . " (" . round($autoTime * 1000, 2) . "ms)\n";

echo "\n=== Test completed ===\n";
