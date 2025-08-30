<?php

// Webhook для автоматического деплоя
// GitHub будет отправлять POST запросы на этот файл

// Проверяем что это POST запрос от GitHub
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Получаем данные от GitHub
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Проверяем что это push в main ветку
if (!isset($data['ref']) || $data['ref'] !== 'refs/heads/main') {
    http_response_code(200);
    exit('Not main branch');
}

// Логируем событие
$log = date('Y-m-d H:i:s') . " - Webhook received for main branch\n";
file_put_contents(__DIR__ . '/../logs/webhook.log', $log, FILE_APPEND);

// Выполняем деплой
try {
    // Переходим в корневую папку проекта
    chdir(__DIR__ . '/..');
    
            // Устанавливаем зависимости
        exec('php composer.phar install --no-dev --optimize-autoloader 2>&1', $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception('Composer install failed: ' . implode("\n", $output));
    }
    
    // Создаем папки если их нет
    if (!is_dir('logs')) mkdir('logs', 0755, true);
    if (!is_dir('public/uploads')) mkdir('public/uploads', 0755, true);
    
    // Настраиваем права доступа
    chmod('.env', 0644);
    chmod('logs', 0755);
    chmod('public/uploads', 0755);
    
            // Настраиваем базу данных
        exec('php composer.phar db:setup 2>&1', $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception('Database setup failed: ' . implode("\n", $output));
    }
    
    // Логируем успех
    $log = date('Y-m-d H:i:s') . " - Deployment completed successfully\n";
    file_put_contents(__DIR__ . '/../logs/webhook.log', $log, FILE_APPEND);
    
    // Отправляем ответ
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Deployment completed']);
    
} catch (Exception $e) {
    // Логируем ошибку
    $log = date('Y-m-d H:i:s') . " - Deployment failed: " . $e->getMessage() . "\n";
    file_put_contents(__DIR__ . '/../logs/webhook.log', $log, FILE_APPEND);
    
    // Отправляем ошибку
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
