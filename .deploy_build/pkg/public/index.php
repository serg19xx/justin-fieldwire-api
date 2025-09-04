<?php
// Включить буферизацию вывода для предотвращения вывода warning'ов
ob_start();

// Настройка отображения ошибок в зависимости от окружения
$appEnv = $_ENV['APP_ENV'] ?? 'development';

if ($appEnv === 'production') {
    // В продакшн режиме не показываем ошибки пользователю
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
} else {
    // В режиме разработки показываем все ошибки
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

// Логирование ошибок всегда включено
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('APP_START_TIME', time());

// Загрузка автозагрузчика
require_once __DIR__ . '/../vendor/autoload.php';

// Загрузка .env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    error_log('ENV ERROR: ' . $e->getMessage());
}

// Инициализация приложения
try {
    $config = new App\Config\Config();
    $app = new App\Bootstrap\Application($config);
} catch (\Exception $e) {
    error_log('APP ERROR: ' . $e->getMessage());
    error_log('STACK: ' . $e->getTraceAsString());
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

// Handle all routes through FlightPHP
Flight::route('*', function() {
    // Get the request URI
    $uri = $_SERVER['REQUEST_URI'];
    
    // Remove query string
    $uri = strtok($uri, '?');
    
    // НЕ блокировать API маршруты!
    if (str_starts_with($uri, '/api/')) {
        return; // Пропускаем API запросы
    }
    
    // Handle specific routes
    if ($uri === '/docs') {
        // Serve Swagger UI
        require_once __DIR__ . '/swagger-ui.php';
        return;
    }
    
    if ($uri === '/swagger.json') {
        // Serve Swagger JSON
        require_once __DIR__ . '/swagger.php';
        return;
    }
    
    // For all other routes, let FlightPHP handle them
    // This will trigger the 404 handler if route not found
    Flight::notFound();
});

// Очистить буфер от warning'ов в продакшн режиме
if ($appEnv === 'production') {
    ob_clean();
}

// Start the application
Flight::start();
