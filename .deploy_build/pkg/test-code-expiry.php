<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "ENV ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Testing Code Expiry Logic ===\n";

// Test 1: Check current server time vs database time
echo "1. Server time: " . date('Y-m-d H:i:s') . "\n";
echo "   Server timezone: " . date_default_timezone_get() . "\n";

// Test 2: Check database time
try {
    $connection = \App\Database\Database::getConnection();
    $result = $connection->executeQuery('SELECT NOW() as db_time');
    $dbTime = $result->fetchAssociative();
    echo "2. Database time: " . $dbTime['db_time'] . "\n";
} catch (Exception $e) {
    echo "2. Database error: " . $e->getMessage() . "\n";
}

// Test 3: Check existing codes in database
echo "\n3. Checking existing 2FA codes:\n";
try {
    $result = $connection->executeQuery('SELECT user_id, code, expires_at, used, created_at FROM fw_2fa_codes ORDER BY created_at DESC LIMIT 5');
    $codes = $result->fetchAllAssociative();
    
    if (empty($codes)) {
        echo "   No codes found in database\n";
    } else {
        foreach ($codes as $code) {
            $isExpired = strtotime($code['expires_at']) < time();
            $status = $isExpired ? '❌ EXPIRED' : '✅ VALID';
            echo "   User {$code['user_id']}: Code {$code['code']} | Expires: {$code['expires_at']} | Used: {$code['used']} | {$status}\n";
        }
    }
} catch (Exception $e) {
    echo "   Database error: " . $e->getMessage() . "\n";
}

// Test 4: Simulate code verification logic
echo "\n4. Testing verification logic:\n";
if (!empty($codes)) {
    $testCode = $codes[0];
    $userId = $testCode['user_id'];
    $code = $testCode['code'];
    
    echo "   Testing code: {$code} for user: {$userId}\n";
    
    // Simulate the exact SQL query from verifyStoredCode
    $sql = 'SELECT * FROM fw_2fa_codes WHERE user_id = ? AND code = ? AND expires_at > NOW() AND used = 0';
    $result = $connection->executeQuery($sql, [$userId, $code]);
    $verification = $result->fetchAssociative();
    
    if ($verification) {
        echo "   ❌ PROBLEM: Code is still considered valid!\n";
        echo "   Expires at: {$verification['expires_at']}\n";
        echo "   Current time: " . date('Y-m-d H:i:s') . "\n";
        echo "   Time difference: " . (strtotime($verification['expires_at']) - time()) . " seconds\n";
    } else {
        echo "   ✅ Code correctly rejected (expired or used)\n";
    }
}

// Test 5: Check if codes are being marked as used
echo "\n5. Checking if codes are marked as used after verification:\n";
try {
    $result = $connection->executeQuery('SELECT COUNT(*) as total, SUM(used) as used_count FROM fw_2fa_codes');
    $stats = $result->fetchAssociative();
    echo "   Total codes: {$stats['total']}\n";
    echo "   Used codes: {$stats['used_count']}\n";
    echo "   Unused codes: " . ($stats['total'] - $stats['used_count']) . "\n";
} catch (Exception $e) {
    echo "   Database error: " . $e->getMessage() . "\n";
}

echo "\n=== Recommendations ===\n";
echo "1. Check if database timezone matches server timezone\n";
echo "2. Ensure codes are marked as 'used' after verification\n";
echo "3. Consider using UTC for all timestamps\n";
echo "4. Add more detailed logging to verifyStoredCode method\n";
