<?php
// test-db-connection.php
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

echo "Testing database connection...\n";
echo "Host: $host\n";
echo "Port: $port\n";
echo "Database: $dbname\n";
echo "Username: $username\n";
echo "Password: " . (empty($password) ? '(empty)' : '(set)') . "\n\n";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Database connection successful!\n";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "✅ Query test successful: " . $result['test'] . "\n";
    
    // Test projects table
    echo "\nTesting projects table...\n";
    $startTime = microtime(true);
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM fw_projects");
    $result = $stmt->fetch();
    $endTime = microtime(true);
    $queryTime = ($endTime - $startTime) * 1000;
    
    echo "✅ Projects table query successful: " . $result['count'] . " projects found\n";
    echo "⏱️ Query time: " . round($queryTime, 2) . "ms\n";
    
    if ($queryTime > 5000) {
        echo "⚠️ WARNING: Query is very slow (>5s)\n";
    } elseif ($queryTime > 1000) {
        echo "⚠️ WARNING: Query is slow (>1s)\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
}
?>
