<?php
/**
 * Тест эндпоинта для изменения порядка задач
 */

require_once 'vendor/autoload.php';

// Настройки
$baseUrl = 'http://localhost:8000';
$projectId = 10; // ID проекта для тестирования

// Данные для тестирования
$testData = [
    'projectId' => $projectId,
    'order' => [1, 5, 12, 4, 2] // Новый порядок задач
];

echo "=== Тест эндпоинта reorder tasks ===\n";
echo "URL: {$baseUrl}/api/v1/projects/{$projectId}/tasks/reorder\n";
echo "Method: PUT\n";
echo "Data: " . json_encode($testData, JSON_PRETTY_PRINT) . "\n\n";

// Создаем cURL запрос
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "{$baseUrl}/api/v1/projects/{$projectId}/tasks/reorder");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_TOKEN_HERE' // Замените на реальный токен
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));

// Выполняем запрос
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

// Выводим результат
echo "HTTP Code: {$httpCode}\n";
if ($error) {
    echo "cURL Error: {$error}\n";
} else {
    echo "Response: " . json_encode(json_decode($response, true), JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== Инструкции по использованию ===\n";
echo "1. Убедитесь, что сервер запущен на {$baseUrl}\n";
echo "2. Замените YOUR_TOKEN_HERE на реальный токен авторизации\n";
echo "3. Убедитесь, что проект с ID {$projectId} существует\n";
echo "4. Убедитесь, что задачи с ID [1, 5, 12, 4, 2] существуют в проекте\n";
echo "5. Запустите: php test-reorder-tasks.php\n";

echo "\n=== Пример использования с фронтенда ===\n";
echo "const reorderTasks = async (projectId, taskOrder) => {\n";
echo "    const response = await fetch(`/api/v1/projects/\${projectId}/tasks/reorder`, {\n";
echo "        method: 'PUT',\n";
echo "        headers: {\n";
echo "            'Content-Type': 'application/json',\n";
echo "            'Authorization': `Bearer \${token}`\n";
echo "        },\n";
echo "        body: JSON.stringify({\n";
echo "            projectId: projectId,\n";
echo "            order: taskOrder\n";
echo "        })\n";
echo "    });\n";
echo "    \n";
echo "    return await response.json();\n";
echo "};\n";
echo "\n// Использование:\n";
echo "reorderTasks(10, [1, 5, 12, 4, 2]);\n";
?>
