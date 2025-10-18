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

echo "=== Testing Forgot Password Email ===\n\n";

// Test data
$email = 'serg.kostyuk@gmail.com';
$userName = 'Mike Davis';
$code = '123456';
$resetToken = 'test.token.here';
$frontendUrl = 'http://localhost:3000';
$resetLink = $frontendUrl . '/reset-password?token=' . $resetToken;

echo "Email: $email\n";
echo "Reset Link: $resetLink\n";
echo "Code: $code\n\n";

// Prepare email content
$subject = 'Password Reset Request';

$htmlMessage = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Password Reset</title>
</head>
<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
    <div style='background: #f8f9fa; padding: 30px; border-radius: 10px;'>
        <h2 style='color: #2c3e50;'>Password Reset Request</h2>
        <p>Hello {$userName},</p>
        <p>You requested to reset your password. Click the button below:</p>
        <div style='text-align: center; margin: 30px 0;'>
            <a href='{$resetLink}' style='background-color: #3498db; color: #ffffff; text-decoration: none; padding: 15px 40px; border-radius: 5px;'>Reset Password</a>
        </div>
        <p>Or enter this code: <strong>{$code}</strong></p>
        <p>This link expires in 10 minutes.</p>
    </div>
</body>
</html>";

$textMessage = "Hello {$userName}!\n\n";
$textMessage .= "Reset link: {$resetLink}\n";
$textMessage .= "Code: {$code}\n";
$textMessage .= "Expires in 10 minutes.\n";

echo "Sending email...\n";

$emailService = new EmailService($logger);
$result = $emailService->sendEmail($email, $subject, $textMessage, $htmlMessage);

if ($result) {
    echo "\n✅ Email sent successfully!\n";
} else {
    echo "\n❌ Failed to send email\n";
}

echo "\nDone!\n";

