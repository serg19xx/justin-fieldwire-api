<?php
// Test check-session with expired token

$url = 'http://localhost:8000/api/v1/auth/check-session';
// This token has exp in the past
$expiredToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjo0NywiZW1haWwiOiJzZXJnLmtvc3R5dWtAZ21haWwuY29tIiwibmFtZSI6Ik1pa2UgRGF2aXMiLCJpYXQiOjE3NjA3MDAwMDAsImV4cCI6MTc2MDcwMDAwMH0.test';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $expiredToken
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

echo "Testing check-session with expired token...\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";

curl_close($ch);
