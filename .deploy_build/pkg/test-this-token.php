<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjo0NywiZW1haWwiOiJwbTFAZXhhbXBsZS5jb20iLCJuYW1lIjoiTWlrZSBEYXZpcyIsImlhdCI6MTc2MDU3MDc1MywiZXhwIjoxNzYwNTc0MzUzfQ.pbrqmu9AXekQ0Fip5YlibRYuGrs4CGLqqQ1YiBf2Y-0";

echo "Testing token from browser...\n\n";

$parts = explode('.', $token);
[$headerEncoded, $payloadEncoded, $signature] = $parts;

// Decode payload
$payload = str_replace(['-', '_'], ['+', '/'], $payloadEncoded);
$payload = str_pad($payload, strlen($payload) % 4, '=', STR_PAD_RIGHT);
$payloadDecoded = base64_decode($payload);
$payloadData = json_decode($payloadDecoded, true);

echo "Payload:\n";
print_r($payloadData);
echo "\n";

// Verify signature
$secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
$expectedSignature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, $secret, true);
$expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));

echo "Signature verification:\n";
echo "Expected: $expectedSignature\n";
echo "Received: $signature\n";
echo "Match: " . (hash_equals($expectedSignature, $signature) ? 'YES ✅' : 'NO ❌') . "\n\n";

// Check expiration
$currentTime = time();
echo "Expiration:\n";
echo "Expires at: " . $payloadData['exp'] . " (" . date('Y-m-d H:i:s', $payloadData['exp']) . ")\n";
echo "Current time: $currentTime (" . date('Y-m-d H:i:s', $currentTime) . ")\n";
echo "Is expired: " . ($payloadData['exp'] < $currentTime ? 'YES ❌' : 'NO ✅') . "\n";

