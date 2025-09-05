<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;

// Настройка для генерации документации
$openapi = Generator::scan([
    __DIR__ . '/../src/Controllers',
    __DIR__ . '/../src/Swagger'
], [
    'validate' => false, // Отключаем валидацию для избежания ошибок
    'logger' => new \Psr\Log\NullLogger() // Отключаем логирование
]);

// Генерируем JSON с красивым форматированием
$json = $openapi->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Сохраняем в файл
file_put_contents(__DIR__ . '/../public/swagger.json', $json);

echo "Swagger documentation generated successfully!\n";
echo "File saved to: public/swagger.json\n";
echo "Access documentation at: http://localhost:8000/docs\n";
