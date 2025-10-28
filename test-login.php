<?php
/**
 * Тест логина для получения токена
 */

require_once 'vendor/autoload.php';

// Данные для тестирования
$loginData = [
    'email' => 'test@example.com',
    'password' => 'password123'
];

echo "=== Тест логина ===\n";
echo "URL: http://localhost:8000/api/v1/auth/login\n";
echo "Data: " . json_encode($loginData, JSON_PRETTY_PRINT) . "\n\n";

// Создаем cURL запрос
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:8000/api/v1/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));

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
    $responseData = json_decode($response, true);
    echo "Response: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    
    if (isset($responseData['data']['token'])) {
        echo "\n=== Токен получен ===\n";
        echo "Token: " . $responseData['data']['token'] . "\n";
        
        // Сохраняем токен в файл для использования в других тестах
        file_put_contents('test-token.txt', $responseData['data']['token']);
        echo "Токен сохранен в test-token.txt\n";
    }
}
?>
