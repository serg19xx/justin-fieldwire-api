<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "=== Testing Auth Flow ===\n\n";

// Test token from the logs
$token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjo0NywiZW1haWwiOiJwbTFAZXhhbXBsZS5jb20iLCJuYW1lIjoiTWlrZSBEYXZpcyIsImlhdCI6MTc2MDU3MDA0OSwiZXhwIjoxNzYwNTczNjQ5fQ.ou4fNJPAyMRqQUCMGLnEifzwM5dBdjNYFaADKWXXADw";

echo "1. Token: " . substr($token, 0, 50) . "...\n";
echo "   Length: " . strlen($token) . "\n";
echo "   Dots: " . substr_count($token, '.') . "\n\n";

// Decode manually
$parts = explode('.', $token);
echo "2. Parts: " . count($parts) . "\n\n";

if (count($parts) === 3) {
    [$headerEncoded, $payloadEncoded, $signature] = $parts;
    
    // Decode payload
    $payload = str_replace(['-', '_'], ['+', '/'], $payloadEncoded);
    $payload = str_pad($payload, strlen($payload) % 4, '=', STR_PAD_RIGHT);
    $payloadDecoded = base64_decode($payload);
    $payloadData = json_decode($payloadDecoded, true);
    
    echo "3. Payload decoded:\n";
    print_r($payloadData);
    echo "\n";
    
    // Verify signature
    $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
    $expectedSignature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, $secret, true);
    $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));
    
    echo "4. Signature verification:\n";
    echo "   Expected: $expectedSignature\n";
    echo "   Received: $signature\n";
    echo "   Match: " . (hash_equals($expectedSignature, $signature) ? 'YES' : 'NO') . "\n\n";
    
    // Check expiration
    $currentTime = time();
    echo "5. Expiration check:\n";
    echo "   Expires at: " . $payloadData['exp'] . " (" . date('Y-m-d H:i:s', $payloadData['exp']) . ")\n";
    echo "   Current time: $currentTime (" . date('Y-m-d H:i:s', $currentTime) . ")\n";
    echo "   Is expired: " . ($payloadData['exp'] < $currentTime ? 'YES' : 'NO') . "\n";
    echo "   Time remaining: " . ($payloadData['exp'] - $currentTime) . " seconds\n\n";
    
    // Get user from database
    try {
        $connection = \App\Database\Database::getConnection();
        $sql = 'SELECT id, email, first_name, last_name FROM fw_v_users WHERE id = ?';
        $result = $connection->executeQuery($sql, [$payloadData['user_id']]);
        $user = $result->fetchAssociative();
        
        echo "6. User from database:\n";
        if ($user) {
            echo "   ✅ User found: {$user['first_name']} {$user['last_name']} ({$user['email']})\n";
        } else {
            echo "   ❌ User not found\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Database error: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Test Complete ===\n";
