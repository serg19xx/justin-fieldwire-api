<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    // Create database connection
    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset={$_ENV['DB_CHARSET']}";
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Database connection successful\n";
    
    // Test user data
    $email = "test@example.com";
    $password = "password1234";
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $firstName = "Test";
    $lastName = "User";
    
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM fw_users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        echo "⚠️  User already exists: $email\n";
        
        // Update password
        $stmt = $pdo->prepare("UPDATE fw_users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$passwordHash, $email]);
        echo "✅ Password updated for user: $email\n";
    } else {
        // Create new user
        $stmt = $pdo->prepare("INSERT INTO fw_users (email, password_hash, first_name, last_name, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
        $stmt->execute([$email, $passwordHash, $firstName, $lastName]);
        echo "✅ New user created: $email\n";
    }
    
    echo "\n🔑 Test user credentials:\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
    echo "\n📝 You can now use these credentials in Swagger UI!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
