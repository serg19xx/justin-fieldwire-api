<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Config;
use App\Database\Database;

try {
    // Загружаем конфигурацию
    $config = new Config();
    
    // Создаем подключение к базе данных
    $database = new Database($config);
    $connection = $database->getConnection();
    
    echo "✅ Database connection successful!\n";
    
    // Проверяем, существует ли таблица шаблонов
    $result = $connection->executeQuery("SHOW TABLES LIKE 'fw_message_templates'");
    $tableExists = $result->fetchOne();
    
    if ($tableExists) {
        echo "✅ Table fw_message_templates exists\n";
        
        // Проверяем количество записей
        $count = $connection->executeQuery("SELECT COUNT(*) FROM fw_message_templates")->fetchOne();
        echo "📊 Records in fw_message_templates: {$count}\n";
        
        // Показываем структуру таблицы
        $result = $connection->executeQuery("DESCRIBE fw_message_templates");
        echo "\n📋 Table structure:\n";
        while ($row = $result->fetchAssociative()) {
            echo "  - {$row['Field']}: {$row['Type']} " . ($row['Null'] === 'NO' ? '(NOT NULL)' : '(NULL)') . "\n";
        }
        
    } else {
        echo "❌ Table fw_message_templates does not exist\n";
        echo "💡 Run: mysql -u your_user -p your_database < scripts/create-message-templates-table.sql\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
