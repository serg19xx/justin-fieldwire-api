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
    
    // Check if fw_users table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'fw_users'");
    if ($stmt->rowCount() == 0) {
        echo "❌ Table 'fw_users' does not exist\n";
        exit(1);
    }
    
    echo "✅ Table 'fw_users' exists\n";
    
    // Get all users
    $stmt = $pdo->query("SELECT id, email, first_name, last_name, phone, status FROM fw_users LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n📋 Users in database:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-5s %-30s %-20s %-20s %-15s %-10s\n", "ID", "Email", "First Name", "Last Name", "Phone", "Status");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($users as $user) {
        printf("%-5s %-30s %-20s %-20s %-15s %-10s\n", 
            $user['id'], 
            $user['email'], 
            $user['first_name'], 
            $user['last_name'], 
            $user['phone'], 
            $user['status']
        );
    }
    
    // Check specific user
    $email = "erg.kostyuk@gmail.com";
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, phone, status FROM fw_users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "\n✅ User found: " . $email . "\n";
        print_r($user);
    } else {
        echo "\n❌ User not found: " . $email . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
