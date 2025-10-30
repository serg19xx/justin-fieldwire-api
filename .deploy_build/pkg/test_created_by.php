<?php

// Тестовый скрипт для проверки поля created_by в API проектов

$baseUrl = 'http://localhost:3000/api/v1';
$token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjoxLCJlbWFpbCI6Ikp1c3Rpbi5rZWFybmV5QG1lLmNvbSIsIm5hbWUiOiJKdXN0aW4gS2Vhcm5leSIsImlhdCI6MTc2MTQ4Mjk5MiwiZXhwIjoxNzYxNDg0NzkyfQ.f2Wt1qaXcomOd5_1P9_2OSbmsBCiwQ6O_jJxCM2MMvE';

$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
];

echo "=== Тестирование поля created_by в API проектов ===\n\n";

// 1. Создание проекта с полем created_by
echo "1. Создание проекта с created_by...\n";
$projectData = [
    'prj_name' => 'Test Project with created_by',
    'address' => '123 Test Street',
    'date_start' => '2025-01-01',
    'date_end' => '2025-12-31',
    'priority' => 'High',
    'status' => 'Active',
    'prj_manager' => 1,
    'created_by' => 47
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/projects');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($projectData));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";

$responseData = json_decode($response, true);
if ($responseData && isset($responseData['data']['project']['id'])) {
    $projectId = $responseData['data']['project']['id'];
    echo "Создан проект с ID: $projectId\n";
    echo "created_by: " . ($responseData['data']['project']['created_by'] ?? 'null') . "\n\n";
    
    // 2. Получение проекта
    echo "2. Получение проекта...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/projects/' . $projectId);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n\n";
    
    // 3. Обновление проекта с новым created_by
    echo "3. Обновление created_by...\n";
    $updateData = [
        'created_by' => 48
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/projects/' . $projectId);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n\n";
    
    // 4. Получение списка проектов
    echo "4. Получение списка проектов...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/projects');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode\n";
    $responseData = json_decode($response, true);
    if ($responseData && isset($responseData['data']['projects'])) {
        $projects = $responseData['data']['projects'];
        echo "Найдено проектов: " . count($projects) . "\n";
        foreach ($projects as $project) {
            if ($project['id'] == $projectId) {
                echo "Найден тестовый проект:\n";
                echo "  ID: " . $project['id'] . "\n";
                echo "  Name: " . $project['prj_name'] . "\n";
                echo "  created_by: " . ($project['created_by'] ?? 'null') . "\n";
                break;
            }
        }
    }
    
    // 5. Удаление тестового проекта
    echo "\n5. Удаление тестового проекта...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/projects/' . $projectId);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n";
}

echo "\n=== Тестирование завершено ===\n";
