<?php
header('Content-Type: application/json');

$response = [
    'php_version' => phpversion(),
    'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
    'document_root' => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : 'Unknown',
    'script_name' => isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'Unknown',
    'extensions' => [
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'mbstring' => extension_loaded('mbstring'),
        'curl' => extension_loaded('curl'),
        'zip' => extension_loaded('zip'),
        'opcache' => extension_loaded('opcache')
    ],
    'settings' => [
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'display_errors' => ini_get('display_errors'),
        'error_reporting' => ini_get('error_reporting')
    ],
    'timestamp' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get()
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>
