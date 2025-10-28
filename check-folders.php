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
    
    // Проверяем папки с project_id = 0
    echo "📁 Checking default folders (project_id = 0):\n";
    $result = $connection->executeQuery(
        "SELECT id, name, parent_id, project_id, created_at, updated_at 
         FROM fw_plan_folder 
         WHERE project_id = 0 
         ORDER BY parent_id ASC, id ASC"
    );
    
    $folders = $result->fetchAllAssociative();
    
    if (empty($folders)) {
        echo "❌ No default folders found with project_id = 0\n";
        echo "💡 Need to create default folder structure first\n";
    } else {
        echo "✅ Found " . count($folders) . " default folders:\n";
        foreach ($folders as $folder) {
            echo "  - ID: {$folder['id']}, Name: '{$folder['name']}', Parent: {$folder['parent_id']}\n";
        }
    }
    
    // Проверяем последние созданные проекты
    echo "\n📊 Recent projects:\n";
    $result = $connection->executeQuery(
        "SELECT id, prj_name, created_at 
         FROM fw_projects 
         ORDER BY created_at DESC 
         LIMIT 5"
    );
    
    $projects = $result->fetchAllAssociative();
    foreach ($projects as $project) {
        echo "  - Project ID: {$project['id']}, Name: '{$project['prj_name']}', Created: {$project['created_at']}\n";
        
        // Проверяем папки для этого проекта
        $folderResult = $connection->executeQuery(
            "SELECT COUNT(*) as folder_count 
             FROM fw_plan_folder 
             WHERE project_id = ?",
            [$project['id']]
        );
        $folderCount = $folderResult->fetchOne();
        echo "    📁 Folders: {$folderCount}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
