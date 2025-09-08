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

// Test data for real email
$testData = [
    'firstName' => 'Serg',
    'lastName' => 'Kostyuk',
    'email' => 'serg.kostyuk@gmail.com',
    'jobTitle' => 'Software Developer',
    'tempPassword' => 'TempPass123!',
    'registrationUrl' => 'https://fieldwire.medicalcontractor.ca/register?token=test123',
    'expiryHours' => 24,
    'expiryDate' => date('Y-m-d H:i:s', strtotime('+24 hours')),
    'attemptNumber' => 1,
    'appUrl' => 'https://fieldwire.medicalcontractor.ca'
];

echo "=== TESTING HTML EMAIL SEND ===\n\n";

// Render HTML template
try {
    $htmlTemplate = $twig->load('invitation.html.twig');
    $htmlContent = $htmlTemplate->render($testData);
    
    echo "✅ HTML template rendered successfully!\n";
    echo "Content length: " . strlen($htmlContent) . " characters\n\n";
    
} catch (\Exception $e) {
    echo "❌ Error rendering HTML template: " . $e->getMessage() . "\n";
    exit(1);
}

// Test email sending using PHPMailer
echo "=== TESTING HTML EMAIL SEND WITH PHPMAILER ===\n\n";

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
    $mail->isHTML(true); // Set to HTML
    $mail->CharSet = 'UTF-8'; // Set UTF-8 encoding for Android compatibility
    $mail->Subject = 'HTML Test Invitation - FieldWire';
    $mail->Body = $htmlContent;
    
    // Add plain text alternative for better compatibility
    $textTemplate = $twig->load('invitation.txt.twig');
    $textContent = $textTemplate->render($testData);
    $mail->AltBody = $textContent;

    // Send email
    $mail->send();
    echo "✅ HTML Email sent successfully to serg.kostyuk@gmail.com!\n";
    echo "Subject: HTML Test Invitation - FieldWire\n";
    echo "Content type: HTML with plain text alternative\n";
    echo "Template: invitation.html.twig\n";
    echo "Alternative: invitation.txt.twig\n\n";
    
} catch (PHPMailerException $e) {
    echo "❌ PHPMailer Error: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->errorMessage() . "\n\n";
} catch (\Exception $e) {
    echo "❌ General Error: " . $e->getMessage() . "\n\n";
}

// Show environment check
echo "=== ENVIRONMENT CHECK ===\n";
echo "SMTP_HOST: " . ($_ENV['SMTP_HOST'] ?? 'NOT SET') . "\n";
echo "SMTP_USERNAME: " . ($_ENV['SMTP_USERNAME'] ?? 'NOT SET') . "\n";
echo "SMTP_PASSWORD: " . (empty($_ENV['SMTP_PASSWORD']) ? 'NOT SET' : 'SET (' . strlen($_ENV['SMTP_PASSWORD']) . ' chars)') . "\n";
echo "SMTP_FROM_EMAIL: " . ($_ENV['SMTP_FROM_EMAIL'] ?? 'NOT SET') . "\n";
echo "SMTP_FROM_NAME: " . ($_ENV['SMTP_FROM_NAME'] ?? 'NOT SET') . "\n";
echo "SMTP_PORT: " . ($_ENV['SMTP_PORT'] ?? 'NOT SET') . "\n\n";

echo "=== HTML CONTENT PREVIEW ===\n";
echo substr(strip_tags($htmlContent), 0, 300) . "...\n\n";

echo "=== TEST COMPLETED ===\n";
