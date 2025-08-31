<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {
    // Create database connection
    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset={$_ENV['DB_CHARSET']}";
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Database connection successful\n";
    
    // Check if two_factor_codes table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'two_factor_codes'");
    if ($stmt->rowCount() == 0) {
        echo "❌ Table 'two_factor_codes' does not exist\n";
        exit(1);
    }
    
    echo "✅ Table 'two_factor_codes' exists\n";
    
    // Get recent 2FA codes
    $stmt = $pdo->query("SELECT * FROM two_factor_codes ORDER BY created_at DESC LIMIT 5");
    $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n📋 Recent 2FA codes:\n";
    echo str_repeat("-", 100) . "\n";
    printf("%-5s %-5s %-10s %-20s %-20s %-10s\n", "ID", "User", "Code", "Created", "Expires", "Used");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($codes as $code) {
        printf("%-5s %-5s %-10s %-20s %-20s %-10s\n", 
            $code['id'], 
            $code['user_id'], 
            $code['code'], 
            $code['created_at'], 
            $code['expires_at'], 
            $code['used'] ? 'Yes' : 'No'
        );
    }
    
    // Check for codes for specific user
    $user_id = 1;
    $stmt = $pdo->prepare("SELECT * FROM two_factor_codes WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$user_id]);
    $userCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($userCodes) {
        echo "\n📱 2FA codes for user ID {$user_id}:\n";
        foreach ($userCodes as $code) {
            echo "Code: {$code['code']}, Created: {$code['created_at']}, Expires: {$code['expires_at']}, Used: " . ($code['used'] ? 'Yes' : 'No') . "\n";
        }
    } else {
        echo "\n❌ No 2FA codes found for user ID {$user_id}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
