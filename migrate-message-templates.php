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
    
    // Добавляем поле is_editable
    echo "🔧 Adding is_editable field...\n";
    $connection->executeStatement("
        ALTER TABLE fw_message_templates 
        ADD COLUMN is_editable BOOLEAN DEFAULT TRUE COMMENT 'Можно ли редактировать (для системных шаблонов)'
    ");
    echo "✅ Field is_editable added successfully!\n";
    
    // Добавляем индексы
    echo "🔧 Adding indexes...\n";
    $connection->executeStatement("ALTER TABLE fw_message_templates ADD KEY idx_event_type (event_type)");
    $connection->executeStatement("ALTER TABLE fw_message_templates ADD KEY idx_type (type)");
    $connection->executeStatement("ALTER TABLE fw_message_templates ADD KEY idx_category (category)");
    $connection->executeStatement("ALTER TABLE fw_message_templates ADD KEY idx_is_active (is_active)");
    echo "✅ Indexes added successfully!\n";
    
    // Добавляем уникальный ключ
    echo "🔧 Adding unique constraint...\n";
    try {
        $connection->executeStatement("
            ALTER TABLE fw_message_templates 
            ADD UNIQUE KEY unique_template (event_type, type, category, name)
        ");
        echo "✅ Unique constraint added successfully!\n";
    } catch (Exception $e) {
        echo "⚠️  Unique constraint already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Проверяем структуру таблицы
    echo "\n📋 Final table structure:\n";
    $result = $connection->executeQuery("DESCRIBE fw_message_templates");
    while ($row = $result->fetchAssociative()) {
        echo "  - {$row['Field']}: {$row['Type']} " . ($row['Null'] === 'NO' ? '(NOT NULL)' : '(NULL)') . "\n";
    }
    
    echo "\n🎉 Database migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
