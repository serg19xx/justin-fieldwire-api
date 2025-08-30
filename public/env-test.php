<?php
echo "<h1>Environment Test</h1>";

// Check if .env file exists
$envFile = __DIR__ . '/../.env';
echo "<p>ENV file exists: " . (file_exists($envFile) ? 'YES' : 'NO') . "</p>";

if (file_exists($envFile)) {
    echo "<p>ENV file size: " . filesize($envFile) . " bytes</p>";
    echo "<p>ENV file content (first 200 chars):</p>";
    echo "<pre>" . htmlspecialchars(substr(file_get_contents($envFile), 0, 200)) . "...</pre>";
}

// Check if vendor/autoload.php exists
$autoloadFile = __DIR__ . '/../vendor/autoload.php';
echo "<p>Autoload file exists: " . (file_exists($autoloadFile) ? 'YES' : 'NO') . "</p>";

// Check if dotenv is available
if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
    if (class_exists('Dotenv\Dotenv')) {
        echo "<p>Dotenv class available: YES</p>";
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
            $dotenv->load();
            echo "<p>Environment loaded successfully!</p>";
            echo "<p>APP_ENV: " . (isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'Not set') . "</p>";
        } catch (Exception $e) {
            echo "<p>Error loading environment: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p>Dotenv class available: NO</p>";
    }
}

echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current directory: " . __DIR__ . "</p>";
?>
