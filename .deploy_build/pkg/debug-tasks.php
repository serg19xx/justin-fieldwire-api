<?php
/**
 * Отладка задач в проекте
 */

require_once 'vendor/autoload.php';

use App\Database\Database;

try {
    $database = new Database();
    $connection = $database->getConnection();
    
    $projectId = 10;
    
    echo "=== Проверка задач в проекте {$projectId} ===\n";
    
    // Проверяем существование проекта
    $projectCheck = $connection->executeQuery(
        "SELECT id, name FROM fw_projects WHERE id = ?",
        [$projectId]
    );
    $project = $projectCheck->fetchAssociative();
    
    if (!$project) {
        echo "❌ Проект с ID {$projectId} не найден!\n";
        exit;
    }
    
    echo "✅ Проект найден: {$project['name']} (ID: {$project['id']})\n\n";
    
    // Получаем все задачи проекта
    $tasksResult = $connection->executeQuery(
        "SELECT id, task_order, name, status FROM fw_prj_tasks WHERE project_id = ? ORDER BY task_order ASC",
        [$projectId]
    );
    $tasks = $tasksResult->fetchAllAssociative();
    
    if (empty($tasks)) {
        echo "❌ В проекте нет задач!\n";
        echo "Создайте несколько задач сначала.\n";
    } else {
        echo "✅ Найдено задач: " . count($tasks) . "\n";
        echo "\nСписок задач:\n";
        foreach ($tasks as $task) {
            echo "- ID: {$task['id']}, Order: {$task['task_order']}, Name: {$task['name']}, Status: {$task['status']}\n";
        }
        
        echo "\n=== Тестовые данные для reorder ===\n";
        $taskIds = array_column($tasks, 'id');
        echo "Доступные ID задач: " . implode(', ', $taskIds) . "\n";
        
        if (count($taskIds) >= 2) {
            // Перемешиваем первые несколько задач для теста
            $testOrder = array_slice($taskIds, 0, min(3, count($taskIds)));
            shuffle($testOrder);
            echo "Тестовый порядок: [" . implode(', ', $testOrder) . "]\n";
            
            echo "\n=== JSON для тестирования ===\n";
            echo json_encode([
                'projectId' => $projectId,
                'order' => $testOrder
            ], JSON_PRETTY_PRINT) . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
