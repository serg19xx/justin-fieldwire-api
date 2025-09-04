<?php
// Test file to check what works on production
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>PHP Test - Production Server</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";

// Test if we can read files
echo "<h2>File System Test</h2>";
if (file_exists('../.env')) {
    echo "<p>✅ .env file exists</p>";
} else {
    echo "<p>❌ .env file NOT found</p>";
}

if (file_exists('../vendor/autoload.php')) {
    echo "<p>✅ vendor/autoload.php exists</p>";
} else {
    echo "<p>❌ vendor/autoload.php NOT found</p>";
}

// Test if we can include autoload
echo "<h2>Autoload Test</h2>";
try {
    if (file_exists('../vendor/autoload.php')) {
        require_once '../vendor/autoload.php';
        echo "<p>✅ Autoload loaded successfully</p>";
    } else {
        echo "<p>❌ Cannot load autoload.php</p>";
    }
} catch (Exception $e) {
    echo "<p>❌ Error loading autoload: " . $e->getMessage() . "</p>";
}

// Test environment variables
echo "<h2>Environment Test</h2>";
if (isset($_ENV['APP_ENV'])) {
    echo "<p>✅ APP_ENV: " . $_ENV['APP_ENV'] . "</p>";
} else {
    echo "<p>❌ APP_ENV not set</p>";
}

if (isset($_ENV['DB_HOST'])) {
    echo "<p>✅ DB_HOST: " . $_ENV['DB_HOST'] . "</p>";
} else {
    echo "<p>❌ DB_HOST not set</p>";
}

echo "<h2>PHP Info</h2>";
echo "<p>Loaded Extensions:</p>";
echo "<ul>";
$extensions = ['mbstring', 'xml', 'pdo_mysql', 'curl'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<li>✅ $ext</li>";
    } else {
        echo "<li>❌ $ext</li>";
    }
}
echo "</ul>";
?>
