<?php

require_once 'vendor/autoload.php';
use App\Database\Database;

try {
    $connection = Database::getConnection();
    echo "Database connected successfully!\n";
    
    // Check if table exists
    $result = $connection->executeQuery("SHOW TABLES LIKE 'fw_2fa_codes'");
    $tableExists = $result->fetchAssociative();
    
    if ($tableExists) {
        echo "Table fw_2fa_codes exists!\n";
        
        // Check recent codes
        $result = $connection->executeQuery("SELECT * FROM fw_2fa_codes WHERE user_id = 47 ORDER BY created_at DESC LIMIT 5");
        $codes = $result->fetchAllAssociative();
        
        echo "Recent codes for user 47:\n";
        foreach ($codes as $code) {
            echo "ID: {$code['id']}, Code: {$code['code']}, Expires: {$code['expires_at']}, Used: {$code['used']}, Created: {$code['created_at']}\n";
        }
        
        // Check current time vs expires_at
        $result = $connection->executeQuery("SELECT NOW() as current_time");
        $time = $result->fetchAssociative();
        echo "Current database time: {$time['current_time']}\n";
        
    } else {
        echo "Table fw_2fa_codes does NOT exist!\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
