<?php
// Test ApiRoutes in web server context
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing ApiRoutes in web server context...\n";

try {
    // Load autoloader
    require_once '../vendor/autoload.php';
    echo "Autoloader loaded successfully\n";
    
    // Test if classes exist
    echo "Checking if classes exist:\n";
    echo "- Config class: " . (class_exists('\App\Config\Config') ? 'YES' : 'NO') . "\n";
    echo "- Application class: " . (class_exists('\App\Bootstrap\Application') ? 'YES' : 'NO') . "\n";
    echo "- ApiRoutes class: " . (class_exists('\App\Routes\ApiRoutes') ? 'YES' : 'NO') . "\n";
    
    // Test creating objects
    echo "Creating objects:\n";
    $config = new \App\Config\Config();
    echo "- Config created successfully\n";
    
    $app = new \App\Bootstrap\Application($config);
    echo "- Application created successfully\n";
    
    echo "All tests passed!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
