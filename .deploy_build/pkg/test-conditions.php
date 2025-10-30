<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Database\Database;
use App\Services\EventConditionsService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Создаем логгер
$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

try {
    // Создаем подключение к базе данных
    $database = new Database();
    
    // Создаем сервис условий
    $conditionsService = new EventConditionsService($database, $logger);
    
    echo "=== Система условий для правил событий ===\n\n";
    
    // Получаем доступные условия
    $availableConditions = $conditionsService->getAvailableConditions();
    
    echo "Доступные типы условий:\n";
    foreach ($availableConditions as $type => $info) {
        echo "- {$type}: {$info['description']}\n";
        if (isset($info['example'])) {
            echo "  Пример: " . json_encode($info['example'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        echo "\n";
    }
    
    echo "=== Примеры использования ===\n\n";
    
    // Пример 1: Проверка ролей пользователя
    echo "Пример 1: Проверка ролей пользователя\n";
    $conditions1 = [
        'user_roles' => ['admin', 'project_manager'],
        'exclude_roles' => ['contractor']
    ];
    
    $eventData1 = [
        'actor_id' => 1, // Предполагаем, что это админ
        'entity_id' => 123,
        'severity' => 'important',
        'actor_type' => 'user'
    ];
    
    $result1 = $conditionsService->checkConditions($conditions1, $eventData1);
    echo "Условия: " . json_encode($conditions1, JSON_UNESCAPED_UNICODE) . "\n";
    echo "Результат: " . ($result1 ? 'ПРОЙДЕНО' : 'НЕ ПРОЙДЕНО') . "\n\n";
    
    // Пример 2: Проверка временных условий
    echo "Пример 2: Проверка временных условий\n";
    $conditions2 = [
        'time_conditions' => [
            'business_hours_only' => true,
            'timezone' => 'UTC'
        ]
    ];
    
    $eventData2 = [
        'actor_id' => 1,
        'entity_id' => 123,
        'severity' => 'important',
        'actor_type' => 'user'
    ];
    
    $result2 = $conditionsService->checkConditions($conditions2, $eventData2);
    echo "Условия: " . json_encode($conditions2, JSON_UNESCAPED_UNICODE) . "\n";
    echo "Результат: " . ($result2 ? 'ПРОЙДЕНО' : 'НЕ ПРОЙДЕНО') . "\n\n";
    
    // Пример 3: Комбинированные условия
    echo "Пример 3: Комбинированные условия\n";
    $conditions3 = [
        'user_roles' => ['admin'],
        'time_conditions' => [
            'business_hours_only' => true
        ],
        'project_conditions' => [
            'min_budget' => 100000
        ]
    ];
    
    $eventData3 = [
        'actor_id' => 1,
        'entity_id' => 123,
        'severity' => 'important',
        'actor_type' => 'user'
    ];
    
    $result3 = $conditionsService->checkConditions($conditions3, $eventData3);
    echo "Условия: " . json_encode($conditions3, JSON_UNESCAPED_UNICODE) . "\n";
    echo "Результат: " . ($result3 ? 'ПРОЙДЕНО' : 'НЕ ПРОЙДЕНО') . "\n\n";
    
    echo "=== Примеры правил с условиями ===\n\n";
    
    // Пример правила 1
    echo "Правило 1: Уведомлять админа только о крупных проектах\n";
    $rule1 = [
        'event_type' => 'PROJECT_CREATED',
        'enabled' => true,
        'actions' => ['notify_admin'],
        'severity' => 'important',
        'conditions' => [
            'project_conditions' => [
                'min_budget' => 100000
            ]
        ],
        'comment' => 'Notify admin only for large projects'
    ];
    echo json_encode($rule1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Пример правила 2
    echo "Правило 2: Уведомлять менеджера только в рабочее время\n";
    $rule2 = [
        'event_type' => 'TASK_CREATED',
        'enabled' => true,
        'actions' => ['notify_manager'],
        'severity' => 'important',
        'conditions' => [
            'time_conditions' => [
                'business_hours_only' => true,
                'timezone' => 'America/New_York'
            ]
        ],
        'comment' => 'Notify manager only during business hours'
    ];
    echo json_encode($rule2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Пример правила 3
    echo "Правило 3: Комбинированные условия\n";
    $rule3 = [
        'event_type' => 'TASK_DELETED',
        'enabled' => true,
        'actions' => ['notify_manager', 'notify_admin'],
        'severity' => 'critical',
        'conditions' => [
            'user_roles' => ['admin', 'project_manager'],
            'time_conditions' => [
                'business_hours_only' => true
            ],
            'task_conditions' => [
                'min_priority' => 2
            ]
        ],
        'comment' => 'Notify about high priority task deletions during business hours'
    ];
    echo json_encode($rule3, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    echo "=== Система готова к использованию! ===\n";
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
