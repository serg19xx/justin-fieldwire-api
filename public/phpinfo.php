<?php
// PHP Info for FieldWire API
// This file helps diagnose PHP configuration issues

echo "<h1>FieldWire API - PHP Configuration</h1>";
echo "<h2>PHP Version: " . phpversion() . "</h2>";
echo "<h2>Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "</h2>";
echo "<h2>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</h2>";

echo "<h3>Loaded Extensions:</h3>";
echo "<ul>";
foreach (get_loaded_extensions() as $ext) {
    echo "<li>$ext</li>";
}
echo "</ul>";

echo "<h3>PHP Configuration:</h3>";
echo "<ul>";
echo "<li>memory_limit: " . ini_get('memory_limit') . "</li>";
echo "<li>max_execution_time: " . ini_get('max_execution_time') . "</li>";
echo "<li>display_errors: " . ini_get('display_errors') . "</li>";
echo "<li>error_reporting: " . ini_get('error_reporting') . "</li>";
echo "<li>opcache.enable: " . ini_get('opcache.enable') . "</li>";
echo "</ul>";

echo "<h3>Environment Variables:</h3>";
echo "<ul>";
echo "<li>APP_ENV: " . (isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'Not set') . "</li>";
echo "<li>APP_DEBUG: " . (isset($_ENV['APP_DEBUG']) ? $_ENV['APP_DEBUG'] : 'Not set') . "</li>";
echo "</ul>";

// Test database connection
echo "<h3>Database Connection Test:</h3>";
try {
    $pdo = new PDO(
        "mysql:host=" . (isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : 'localhost') . 
        ";dbname=" . (isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : 'test'),
        isset($_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : 'root',
        isset($_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : ''
    );
    echo "<p style='color: green;'>✅ Database connection successful!</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Note:</strong> This file should be removed after debugging.</p>";
?>
