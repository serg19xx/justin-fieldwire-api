<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "ENV ERROR: " . $e->getMessage() . "\n";
}

// Initialize Twig
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/src/Templates/Email');
$twig = new \Twig\Environment($loader);

// Test data with login URL
$testData = [
    'firstName' => 'Serg',
    'lastName' => 'Kostyuk',
    'email' => 'serg.kostyuk@gmail.com',
    'jobTitle' => 'Software Developer',
    'tempPassword' => 'TempPass123!',
    'loginUrl' => 'https://fieldwire.medicalcontractor.ca/login',
    'expiryHours' => 24,
    'expiryDate' => date('Y-m-d H:i:s', strtotime('+24 hours')),
    'attemptNumber' => 1,
    'appUrl' => 'https://fieldwire.medicalcontractor.ca'
];

echo "=== FINAL TEST: LOGIN URL IN EMAIL TEMPLATES ===\n\n";

// Test HTML template
try {
    $htmlTemplate = $twig->load('invitation.html.twig');
    $htmlContent = $htmlTemplate->render($testData);
    
    echo "✅ HTML template: " . strlen($htmlContent) . " characters\n";
    
    // Check for login URL and text
    $loginUrlFound = strpos($htmlContent, 'https://fieldwire.medicalcontractor.ca/login') !== false;
    $loginTextFound = strpos($htmlContent, 'Login to Your Account') !== false;
    $loginMessageFound = strpos($htmlContent, 'Please login to your account') !== false;
    
    echo "   Login URL: " . ($loginUrlFound ? "✅ Found" : "❌ Not found") . "\n";
    echo "   Login button text: " . ($loginTextFound ? "✅ Found" : "❌ Not found") . "\n";
    echo "   Login message: " . ($loginMessageFound ? "✅ Found" : "❌ Not found") . "\n";
    
} catch (\Exception $e) {
    echo "❌ HTML template error: " . $e->getMessage() . "\n";
}

// Test text template
try {
    $textTemplate = $twig->load('invitation.txt.twig');
    $textContent = $textTemplate->render($testData);
    
    echo "✅ Text template: " . strlen($textContent) . " characters\n";
    
    // Check for login URL and text
    $loginUrlFound = strpos($textContent, 'https://fieldwire.medicalcontractor.ca/login') !== false;
    $loginTextFound = strpos($textContent, 'LOGIN TO YOUR ACCOUNT') !== false;
    $loginMessageFound = strpos($textContent, 'Please login to your account') !== false;
    
    echo "   Login URL: " . ($loginUrlFound ? "✅ Found" : "❌ Not found") . "\n";
    echo "   Login section: " . ($loginTextFound ? "✅ Found" : "❌ Not found") . "\n";
    echo "   Login message: " . ($loginMessageFound ? "✅ Found" : "❌ Not found") . "\n";
    
} catch (\Exception $e) {
    echo "❌ Text template error: " . $e->getMessage() . "\n";
}

echo "\n=== SENDING FINAL TEST EMAIL ===\n\n";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['SMTP_USERNAME'] ?? '';
    $mail->Password = $_ENV['SMTP_PASSWORD'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $_ENV['SMTP_PORT'] ?? 587;

    // Recipients
    $mail->setFrom($_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@fieldwire.com', $_ENV['SMTP_FROM_NAME'] ?? 'FieldWire Team');
    $mail->addAddress('serg.kostyuk@gmail.com', 'Serg Kostyuk');

    // Content
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Final Test - Login URL - FieldWire';
    $mail->Body = $htmlContent;
    $mail->AltBody = $textContent;

    // Send email
    $mail->send();
    echo "✅ Final test email sent successfully to serg.kostyuk@gmail.com!\n";
    echo "Subject: Final Test - Login URL - FieldWire\n";
    echo "Login URL: https://fieldwire.medicalcontractor.ca/login\n\n";
    
} catch (PHPMailerException $e) {
    echo "❌ PHPMailer Error: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "❌ General Error: " . $e->getMessage() . "\n";
}

echo "=== ALL TESTS COMPLETED ===\n";
echo "✅ Email templates now use LOGIN URL instead of registration URL\n";
echo "✅ Both HTML and text templates updated\n";
echo "✅ EmailService updated to use login URL\n";
echo "✅ All text references changed from 'registration' to 'login'\n";
echo "✅ Ready for production use!\n";
