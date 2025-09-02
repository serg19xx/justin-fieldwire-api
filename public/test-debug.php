<?php
// Simple debug file to test server configuration
echo "=== PHP Debug Info ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "Script Filename: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'Unknown') . "\n";
echo "Current Directory: " . __DIR__ . "\n";
echo "Parent Directory: " . dirname(__DIR__) . "\n\n";

echo "=== File Existence Check ===\n";
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
echo "Vendor autoload exists: " . (file_exists($vendorAutoload) ? 'YES' : 'NO') . "\n";
echo "Vendor autoload path: " . $vendorAutoload . "\n";

$envFile = dirname(__DIR__) . '/.env';
echo "ENV file exists: " . (file_exists($envFile) ? 'YES' : 'NO') . "\n";
echo "ENV file path: " . $envFile . "\n";

$logsDir = dirname(__DIR__) . '/logs';
echo "Logs directory exists: " . (is_dir($logsDir) ? 'YES' : 'NO') . "\n";
echo "Logs directory path: " . $logsDir . "\n";

echo "\n=== Directory Listing ===\n";
$rootDir = dirname(__DIR__);
echo "Root directory contents:\n";
if (is_dir($rootDir)) {
    $files = scandir($rootDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $path = $rootDir . '/' . $file;
            $type = is_dir($path) ? 'DIR' : 'FILE';
            echo "  [$type] $file\n";
        }
    }
} else {
    echo "  Cannot read root directory\n";
}

echo "\n=== Environment Variables ===\n";
echo "APP_ENV: " . ($_ENV['APP_ENV'] ?? 'NOT SET') . "\n";
echo "APP_DEBUG: " . ($_ENV['APP_DEBUG'] ?? 'NOT SET') . "\n";

echo "\n=== Error Reporting ===\n";
echo "display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "\n";
echo "log_errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "\n";
echo "error_log: " . (ini_get('error_log') ?: 'NOT SET') . "\n";

echo "\n=== Test Complete ===\n";
?>
