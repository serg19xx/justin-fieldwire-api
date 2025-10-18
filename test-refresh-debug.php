<?php
// Debug refresh token endpoint

require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
Dotenv::createImmutable(__DIR__, 'env.development')->load();

$baseUrl = 'http://localhost:8000';

echo "=== Debug Refresh Token Endpoint ===\n";

// Test 1: Check what cookies are being sent
echo "1. Testing refresh token without any cookies:\n";

$ch = curl_init($baseUrl . '/api/v1/auth/refresh-token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: http://localhost:3000'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_STDERR, fopen('php://temp', 'w+'));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

// Get verbose output
rewind(curl_getinfo($ch, CURLOPT_STDERR));
$verbose = stream_get_contents(curl_getinfo($ch, CURLOPT_STDERR));
echo "Verbose output:\n$verbose\n";

curl_close($ch);

echo "\n2. Testing with a fake cookie:\n";

$ch = curl_init($baseUrl . '/api/v1/auth/refresh-token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: http://localhost:3000',
    'Cookie: refresh_token=fake_token_123'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

curl_close($ch);

echo "\n3. Testing with Authorization header (like frontend does):\n";

$ch = curl_init($baseUrl . '/api/v1/auth/refresh-token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: http://localhost:3000',
    'Authorization: Bearer fake_token_123'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

curl_close($ch);
