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

echo "=== TESTING LOGIN URL IN TEMPLATES ===\n\n";

// Test HTML template
try {
    $htmlTemplate = $twig->load('invitation.html.twig');
    $htmlContent = $htmlTemplate->render($testData);
    
    echo "✅ HTML template rendered successfully!\n";
    echo "Content length: " . strlen($htmlContent) . " characters\n";
    
    // Check for login URL in HTML
    if (strpos($htmlContent, 'https://fieldwire.medicalcontractor.ca/login') !== false) {
        echo "✅ Login URL found in HTML template\n";
    } else {
        echo "❌ Login URL NOT found in HTML template\n";
    }
    
} catch (\Exception $e) {
    echo "❌ HTML template error: " . $e->getMessage() . "\n";
}

// Test text template
try {
    $textTemplate = $twig->load('invitation.txt.twig');
    $textContent = $textTemplate->render($testData);
    
    echo "✅ Text template rendered successfully!\n";
    echo "Content length: " . strlen($textContent) . " characters\n";
    
    // Check for login URL in text
    if (strpos($textContent, 'https://fieldwire.medicalcontractor.ca/login') !== false) {
        echo "✅ Login URL found in text template\n";
    } else {
        echo "❌ Login URL NOT found in text template\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Text template error: " . $e->getMessage() . "\n";
}

echo "\n=== TESTING EMAIL SEND WITH LOGIN URL ===\n\n";

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
    $mail->Subject = 'Login URL Test - FieldWire';
    $mail->Body = $htmlContent;
    $mail->AltBody = $textContent;

    // Send email
    $mail->send();
    echo "✅ Email sent successfully to serg.kostyuk@gmail.com!\n";
    echo "Subject: Login URL Test - FieldWire\n";
    echo "Login URL: https://fieldwire.medicalcontractor.ca/login\n\n";
    
} catch (PHPMailerException $e) {
    echo "❌ PHPMailer Error: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "❌ General Error: " . $e->getMessage() . "\n";
}

echo "=== TEST COMPLETED ===\n";
