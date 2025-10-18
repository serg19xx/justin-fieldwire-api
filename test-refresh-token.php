<?php
// Test refresh token functionality

require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
Dotenv::createImmutable(__DIR__)->load();

$baseUrl = 'http://localhost:8000';

// Test 1: Login and get refresh token cookie
echo "=== Test 1: Login and get refresh token cookie ===\n";

$loginData = [
    'email' => 'test@example.com',
    'password' => 'password1234'
];

$ch = curl_init($baseUrl . '/api/v1/auth/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: http://localhost:3000' // Important for CORS
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HEADER, true); // Get headers
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt'); // Save cookies

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";

// Extract cookies from response
preg_match_all('/Set-Cookie: ([^;]+)/', $response, $matches);
$cookies = [];
foreach ($matches[1] as $cookie) {
    $parts = explode('=', $cookie, 2);
    if (count($parts) === 2) {
        $cookies[$parts[0]] = $parts[1];
    }
}

echo "Cookies received:\n";
foreach ($cookies as $name => $value) {
    echo "  $name: " . substr($value, 0, 20) . "...\n";
}

// Extract access token from response body
$body = substr($response, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
$loginResponse = json_decode($body, true);

if ($loginResponse && isset($loginResponse['data']['token'])) {
    $accessToken = $loginResponse['data']['token'];
    echo "Access token: " . substr($accessToken, 0, 50) . "...\n";
} else {
    echo "❌ Failed to get access token\n";
    echo "Response: $body\n";
    exit(1);
}

curl_close($ch);

echo "\n=== Test 2: Refresh token ===\n";

// Test 2: Use refresh token to get new access token
$ch = curl_init($baseUrl . '/api/v1/auth/refresh-token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: http://localhost:3000' // Important for CORS
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt'); // Use saved cookies

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";

curl_close($ch);

echo "\n=== Test 3: Use new access token ===\n";

// Test 3: Use the new access token
$refreshResponse = json_decode($response, true);
if ($refreshResponse && isset($refreshResponse['data']['token'])) {
    $newAccessToken = $refreshResponse['data']['token'];
    
    $ch = curl_init($baseUrl . '/api/v1/profile');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $newAccessToken,
        'Origin: http://localhost:3000'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "HTTP Code: $httpCode\n";
    echo "Profile response:\n";
    echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";
    
    curl_close($ch);
} else {
    echo "❌ Failed to refresh token\n";
}

echo "\n=== Test 4: Logout (revoke refresh token) ===\n";

// Test 4: Logout to revoke refresh token
$ch = curl_init($baseUrl . '/api/v1/auth/logout');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $newAccessToken,
    'Origin: http://localhost:3000'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Logout response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";

curl_close($ch);

echo "\n=== Test 5: Try to refresh after logout ===\n";

// Test 5: Try to refresh token after logout (should fail)
$ch = curl_init($baseUrl . '/api/v1/auth/refresh-token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Origin: http://localhost:3000'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response (should be 401):\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";

curl_close($ch);

// Clean up
unlink('/tmp/cookies.txt');

echo "\n=== Test Complete ===\n";
