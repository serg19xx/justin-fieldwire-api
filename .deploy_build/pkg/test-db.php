<?php
// Simple database test script
require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "=== Database Test ===\n";
echo "DB_HOST: " . ($_ENV['DB_HOST'] ?? 'NOT SET') . "\n";
echo "DB_NAME: " . ($_ENV['DB_NAME'] ?? 'NOT SET') . "\n";
echo "DB_USERNAME: " . ($_ENV['DB_USERNAME'] ?? 'NOT SET') . "\n";
echo "DB_PASSWORD: " . (isset($_ENV['DB_PASSWORD']) ? 'SET' : 'NOT SET') . "\n\n";

try {
    $config = [
        'driver' => 'pdo_mysql',
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'dbname' => $_ENV['DB_NAME'] ?? 'fieldwire_api',
        'user' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ];

    echo "Connecting to database...\n";
    $connection = Doctrine\DBAL\DriverManager::getConnection($config);
    echo "✅ Database connected successfully!\n\n";

    // Test query to get user
    echo "Testing user query...\n";
    $sql = 'SELECT id, email, password_hash, first_name, last_name, status FROM fw_users WHERE email = ?';
    $result = $connection->executeQuery($sql, ['serg.kostyuk@gmail.com']);
    $user = $result->fetchAssociative();

    if ($user) {
        echo "✅ User found:\n";
        echo "  ID: " . $user['id'] . "\n";
        echo "  Email: " . $user['email'] . "\n";
        echo "  First Name: " . $user['first_name'] . "\n";
        echo "  Last Name: " . $user['last_name'] . "\n";
        echo "  Status: " . $user['status'] . "\n";
        echo "  Password Hash: " . substr($user['password_hash'], 0, 20) . "...\n";
        
        // Test password verification
        echo "\nTesting password verification...\n";
        $testPassword = 'Medeli@2025';
        if (password_verify($testPassword, $user['password_hash'])) {
            echo "✅ Password verification successful!\n";
        } else {
            echo "❌ Password verification failed!\n";
            
            // Show what the hash should look like
            echo "Expected hash for 'Medeli@2025': " . password_hash($testPassword, PASSWORD_DEFAULT) . "\n";
        }
    } else {
        echo "❌ User not found!\n";
        
        // Show all users
        echo "\nAll users in database:\n";
        $allUsers = $connection->executeQuery('SELECT id, email, first_name, last_name FROM fw_users LIMIT 10');
        while ($row = $allUsers->fetchAssociative()) {
            echo "  ID: " . $row['id'] . ", Email: " . $row['email'] . ", Name: " . $row['first_name'] . " " . $row['last_name'] . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
