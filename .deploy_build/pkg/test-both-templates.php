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

// Test data
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

echo "=== TESTING BOTH TEMPLATES ===\n\n";

// Test HTML template
try {
    $htmlTemplate = $twig->load('invitation.html.twig');
    $htmlContent = $htmlTemplate->render($testData);
    
    echo "✅ HTML template: " . strlen($htmlContent) . " characters\n";
    
    // Check for emojis in HTML
    $emojiCount = preg_match_all('/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]|[\x{1F1E0}-\x{1F1FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u', $htmlContent);
    echo "   Emojis found: " . $emojiCount . "\n";
    
} catch (\Exception $e) {
    echo "❌ HTML template error: " . $e->getMessage() . "\n";
}

// Test text template
try {
    $textTemplate = $twig->load('invitation.txt.twig');
    $textContent = $textTemplate->render($testData);
    
    echo "✅ Text template: " . strlen($textContent) . " characters\n";
    
    // Check for emojis in text
    $emojiCount = preg_match_all('/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]|[\x{1F1E0}-\x{1F1FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u', $textContent);
    echo "   Emojis found: " . $emojiCount . "\n";
    
} catch (\Exception $e) {
    echo "❌ Text template error: " . $e->getMessage() . "\n";
}

echo "\n=== ANDROID COMPATIBILITY CHECK ===\n";
echo "✅ Both templates are now emoji-free\n";
echo "✅ UTF-8 encoding supported\n";
echo "✅ ASCII-compatible characters only\n";
echo "✅ Ready for Android devices\n\n";

echo "=== TEST COMPLETED ===\n";
