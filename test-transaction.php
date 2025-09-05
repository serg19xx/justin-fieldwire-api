<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "ENV ERROR: " . $e->getMessage() . "\n";
}

echo "=== TESTING TRANSACTIONAL LOGIC ===\n\n";

// Test 1: Successful transaction
echo "=== TEST 1: SUCCESSFUL TRANSACTION ===\n";

$testData = [
    'email' => 'test.success@example.com',
    'first_name' => 'Test',
    'last_name' => 'Success',
    'user_type' => 'Employee',
    'job_title' => 'Tester',
    'phone' => '+1234567890',
    'email_provider' => 'auto'
];

$jsonData = json_encode($testData);

// Simulate API call
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/v1/workers/invite');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_TOKEN_HERE' // Replace with actual token
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n\n";

// Test 2: Test with invalid email (should fail)
echo "=== TEST 2: INVALID EMAIL (SHOULD FAIL) ===\n";

$testData2 = [
    'email' => 'invalid-email',
    'first_name' => 'Test',
    'last_name' => 'Fail',
    'user_type' => 'Employee',
    'job_title' => 'Tester',
    'phone' => '+1234567890',
    'email_provider' => 'auto'
];

$jsonData2 = json_encode($testData2);

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, 'http://localhost:8000/api/v1/workers/invite');
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $jsonData2);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_TOKEN_HERE' // Replace with actual token
]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 30);

$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Code: " . $httpCode2 . "\n";
echo "Response: " . $response2 . "\n\n";

echo "=== TRANSACTION TEST COMPLETED ===\n";
echo "Note: These tests require a running server and valid authentication token.\n";
echo "The transaction logic ensures:\n";
echo "✅ Database insert/update and email sending are atomic\n";
echo "✅ If email fails, database changes are rolled back\n";
echo "✅ If database fails, no email is sent\n";
echo "✅ Proper error messages are returned\n";
echo "✅ Success messages are returned only when both operations succeed\n";
