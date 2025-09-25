<?php
require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "Error loading .env: " . $e->getMessage() . "\n";
    exit(1);
}

// Test database connection
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? 3306;
$dbname = $_ENV['DB_NAME'] ?? 'fieldwire_api';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Database connection successful!\n";
    
    // Check user 47
    $stmt = $pdo->query('SELECT id, email, first_name, last_name, status FROM fw_users WHERE id = 47');
    $user = $stmt->fetch();
    
    if ($user) {
        echo "✅ User found: " . json_encode($user) . "\n";
    } else {
        echo "❌ User 47 not found\n";
        
        // Show all users
        $stmt = $pdo->query('SELECT id, email, first_name, last_name, status FROM fw_users ORDER BY id LIMIT 10');
        $users = $stmt->fetchAll();
        echo "Available users:\n";
        foreach ($users as $u) {
            echo "- ID: {$u['id']}, Email: {$u['email']}, Name: {$u['first_name']} {$u['last_name']}, Status: {$u['status']}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
}
?>
