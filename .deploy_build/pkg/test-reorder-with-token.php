<?php
/**
 * Тест эндпоинта reorder с валидным токеном
 */

$baseUrl = 'http://localhost:8000';
$projectId = 10;
$token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjo0NywiZW1haWwiOiJzZXJnLmtvc3R5dWtAZ21haWwuY29tIiwibmFtZSI6Ik1pa2UgRGF2aXMiLCJpYXQiOjE3NjExNDM0ODAsImV4cCI6MTc2MTE0NTI4MH0.JrP8k-xrRAghr8W0PcIMBnrhkoKnTsvB2nJa9UHadpU';

echo "=== Тест 1: Получение списка задач ===\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "{$baseUrl}/api/v1/projects/{$projectId}/tasks");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
$tasksData = json_decode($response, true);
echo "Response: " . json_encode($tasksData, JSON_PRETTY_PRINT) . "\n";

if (isset($tasksData['data']['tasks']) && !empty($tasksData['data']['tasks'])) {
    $taskIds = array_column($tasksData['data']['tasks'], 'id');
    echo "\n=== Найдены задачи ===\n";
    echo "Task IDs: " . implode(', ', $taskIds) . "\n";
    
    // Тестируем reorder с первыми несколькими задачами
    $testOrder = array_slice($taskIds, 0, min(3, count($taskIds)));
    if (count($testOrder) > 1) {
        // Перемешиваем порядок для теста
        shuffle($testOrder);
        
        echo "\n=== Тест 2: Reorder задач ===\n";
        echo "Новый порядок: [" . implode(', ', $testOrder) . "]\n";
        
        $reorderData = [
            'projectId' => $projectId,
            'order' => $testOrder
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$baseUrl}/api/v1/projects/{$projectId}/tasks/reorder");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($reorderData));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "HTTP Code: {$httpCode}\n";
        echo "Response: " . json_encode(json_decode($response, true), JSON_PRETTY_PRINT) . "\n";
        
        if ($httpCode === 200) {
            echo "\n✅ Reorder успешен!\n";
        } else {
            echo "\n❌ Reorder не удался\n";
        }
    } else {
        echo "\n❌ Недостаточно задач для тестирования reorder (нужно минимум 2)\n";
    }
} else {
    echo "\n❌ Задачи не найдены в проекте {$projectId}\n";
    echo "Создайте несколько задач сначала.\n";
}

echo "\n=== Проверка логов ===\n";
echo "Проверьте логи: tail -f logs/app.log\n";
?>
