<?php

// Простой тест для проверки логаута
$baseUrl = 'http://localhost:8000';

echo "=== Тест системы аутентификации ===\n\n";

// 1. Тест логина
echo "1. Тестируем логин...\n";
$loginData = [
    'email' => 'pm1@example.com',
    'password' => 'password'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/api/v1/auth/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$loginResponse = curl_exec($ch);
$loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $loginHttpCode\n";
echo "Response: $loginResponse\n\n";

if ($loginHttpCode === 200) {
    $loginData = json_decode($loginResponse, true);
    
    if (isset($loginData['data']['token'])) {
        $token = $loginData['data']['token'];
        echo "✅ Логин успешен! Токен получен.\n\n";
        
        // 2. Тест логаута
        echo "2. Тестируем логаут...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/api/v1/auth/logout');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $logoutResponse = curl_exec($ch);
        $logoutHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "HTTP Code: $logoutHttpCode\n";
        echo "Response: $logoutResponse\n\n";
        
        if ($logoutHttpCode === 200) {
            echo "✅ Логаут успешен!\n\n";
        } else {
            echo "❌ Ошибка логаута!\n\n";
        }
        
        // 3. Проверяем аудит
        echo "3. Проверяем записи в аудите...\n";
        
        try {
            require_once 'vendor/autoload.php';
            $connection = \App\Database\Database::getConnection();
            
            $sql = "SELECT * FROM fw_user_audit_log ORDER BY created_at DESC LIMIT 5";
            $result = $connection->executeQuery($sql);
            $logs = $result->fetchAllAssociative();
            
            echo "Последние 5 записей в аудите:\n";
            foreach ($logs as $log) {
                echo "- ID: {$log['id']}, User: {$log['user_id']}, Action: {$log['action_type']}, Success: {$log['success']}, Time: {$log['created_at']}\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Ошибка при проверке аудита: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "❌ Токен не получен в ответе логина!\n";
    }
} else {
    echo "❌ Ошибка логина!\n";
}

echo "\n=== Тест завершен ===\n";
