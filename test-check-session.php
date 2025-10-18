<?php
// Test check-session endpoint

$url = 'http://localhost:8000/api/v1/auth/check-session';
$token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjo0NywiZW1haWwiOiJzZXJnLmtvc3R5dWtAZ21haWwuY29tIiwibmFtZSI6Ik1pa2UgRGF2aXMiLCJpYXQiOjE3NjA3MTYyMTAsImV4cCI6MTc2MDcxODAxMH0.-GrMoe_8sJ0iiXFRcT6CilfgM-HpyJvmBP4ZQh5fZtQ';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

echo "Testing check-session endpoint...\n";
echo "URL: $url\n";
echo "Token: " . substr($token, 0, 50) . "...\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo $response . "\n";

curl_close($ch);

