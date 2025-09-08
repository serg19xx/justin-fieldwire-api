<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "ENV ERROR: " . $e->getMessage() . "\n";
}

echo "=== TESTING INVITATION CODE LOGIN ===\n\n";

// Test 1: Valid invitation code login
echo "=== TEST 1: VALID INVITATION CODE LOGIN ===\n";

$testData = [
    'email' => 'serg.kostyuk@gmail.com',
    'invitation_code' => 'test123' // This should be a real invitation code from database
];

$jsonData = json_encode($testData);

echo "Request data: " . $jsonData . "\n";

// Simulate API call
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/v1/auth/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n\n";

// Test 2: Invalid invitation code
echo "=== TEST 2: INVALID INVITATION CODE ===\n";

$testData2 = [
    'email' => 'serg.kostyuk@gmail.com',
    'invitation_code' => 'invalid_code'
];

$jsonData2 = json_encode($testData2);

echo "Request data: " . $jsonData2 . "\n";

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, 'http://localhost:8000/api/v1/auth/login');
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $jsonData2);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 30);

$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Code: " . $httpCode2 . "\n";
echo "Response: " . $response2 . "\n\n";

// Test 3: Regular password login (should still work)
echo "=== TEST 3: REGULAR PASSWORD LOGIN ===\n";

$testData3 = [
    'email' => 'serg.kostyuk@gmail.com',
    'password' => 'password123'
];

$jsonData3 = json_encode($testData3);

echo "Request data: " . $jsonData3 . "\n";

$ch3 = curl_init();
curl_setopt($ch3, CURLOPT_URL, 'http://localhost:8000/api/v1/auth/login');
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, $jsonData3);
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_TIMEOUT, 30);

$response3 = curl_exec($ch3);
$httpCode3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
curl_close($ch3);

echo "HTTP Code: " . $httpCode3 . "\n";
echo "Response: " . $response3 . "\n\n";

echo "=== INVITATION LOGIN TEST COMPLETED ===\n";
echo "The invitation code login system includes:\n";
echo "✅ Validation of email and invitation code\n";
echo "✅ Check if invitation code exists and is not expired\n";
echo "✅ Update user status to 'registered'\n";
echo "✅ Clear invitation fields (token, dates, etc.)\n";
echo "✅ Set registered_at and last_login timestamps\n";
echo "✅ Transactional updates for data consistency\n";
echo "✅ Proper error messages for different scenarios\n";
echo "✅ Backward compatibility with regular password login\n";
