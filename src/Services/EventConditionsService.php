<?php

namespace App\Services;

use App\Database\Database;
use Monolog\Logger;

/**
 * Сервис для проверки условий правил событий
 */
class EventConditionsService
{
    private Database $database;
    private Logger $logger;

    public function __construct(Database $database, Logger $logger)
    {
        $this->database = $database;
        $this->logger = $logger;
    }

    /**
     * Проверяет условия правила события
     * 
     * @param array|null $conditions JSON условия из правила
     * @param array $eventData Данные события
     * @return bool
     */
    public function checkConditions(?array $conditions, array $eventData): bool
    {
        if (empty($conditions)) {
            return true; // Нет условий = правило всегда срабатывает
        }

        try {
            // Проверяем каждое условие
            foreach ($conditions as $conditionType => $conditionValue) {
                if (!$this->checkCondition($conditionType, $conditionValue, $eventData)) {
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error checking event conditions', [
                'conditions' => $conditions,
                'event_data' => $eventData,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Проверяет конкретное условие
     */
    private function checkCondition(string $conditionType, $conditionValue, array $eventData): bool
    {
        switch ($conditionType) {
            case 'user_roles':
                return $this->checkUserRoles($conditionValue, $eventData);
            
            case 'exclude_roles':
                return $this->checkExcludeRoles($conditionValue, $eventData);
            
            case 'time_conditions':
                return $this->checkTimeConditions($conditionValue, $eventData);
            
            case 'project_conditions':
                return $this->checkProjectConditions($conditionValue, $eventData);
            
            case 'task_conditions':
                return $this->checkTaskConditions($conditionValue, $eventData);
            
            case 'event_conditions':
                return $this->checkEventConditions($conditionValue, $eventData);
            
            default:
                $this->logger->warning('Unknown condition type', [
                    'condition_type' => $conditionType,
                    'condition_value' => $conditionValue
                ]);
                return true; // Неизвестное условие = пропускаем
        }
    }

    /**
     * Проверяет роли пользователя
     * 
     * @param array $allowedRoles Массив разрешенных ролей
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkUserRoles(array $allowedRoles, array $eventData): bool
    {
        if (empty($eventData['actor_id'])) {
            return false;
        }

        $userRole = $this->getUserRole($eventData['actor_id']);
        return in_array($userRole, $allowedRoles);
    }

    /**
     * Проверяет исключенные роли
     * 
     * @param array $excludedRoles Массив исключенных ролей
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkExcludeRoles(array $excludedRoles, array $eventData): bool
    {
        if (empty($eventData['actor_id'])) {
            return true; // Нет пользователя = не исключаем
        }

        $userRole = $this->getUserRole($eventData['actor_id']);
        return !in_array($userRole, $excludedRoles);
    }

    /**
     * Проверяет временные условия
     * 
     * @param array $timeConditions Условия времени
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkTimeConditions(array $timeConditions, array $eventData): bool
    {
        // Проверка рабочего времени
        if (isset($timeConditions['business_hours_only']) && $timeConditions['business_hours_only']) {
            if (!$this->isBusinessHours($timeConditions['timezone'] ?? 'UTC')) {
                return false;
            }
        }

        // Проверка дня недели
        if (isset($timeConditions['weekdays_only']) && $timeConditions['weekdays_only']) {
            if (!$this->isWeekday()) {
                return false;
            }
        }

        // Проверка временного диапазона
        if (isset($timeConditions['time_range'])) {
            $startTime = $timeConditions['time_range']['start'] ?? '09:00';
            $endTime = $timeConditions['time_range']['end'] ?? '17:00';
            if (!$this->isInTimeRange($startTime, $endTime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверяет условия проекта
     * 
     * @param array $projectConditions Условия проекта
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkProjectConditions(array $projectConditions, array $eventData): bool
    {
        if (empty($eventData['entity_id'])) {
            return false;
        }

        $project = $this->getProject($eventData['entity_id']);
        if (!$project) {
            return false;
        }

        // Проверка минимального бюджета
        if (isset($projectConditions['min_budget'])) {
            if ($project['budget'] < $projectConditions['min_budget']) {
                return false;
            }
        }

        // Проверка статуса проекта
        if (isset($projectConditions['status'])) {
            if (!in_array($project['status'], $projectConditions['status'])) {
                return false;
            }
        }

        // Проверка типа проекта
        if (isset($projectConditions['project_type'])) {
            if ($project['project_type'] !== $projectConditions['project_type']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверяет условия задачи
     * 
     * @param array $taskConditions Условия задачи
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkTaskConditions(array $taskConditions, array $eventData): bool
    {
        if (empty($eventData['entity_id'])) {
            return false;
        }

        $task = $this->getTask($eventData['entity_id']);
        if (!$task) {
            return false;
        }

        // Проверка статуса задачи
        if (isset($taskConditions['status'])) {
            if (!in_array($task['status'], $taskConditions['status'])) {
                return false;
            }
        }

        // Проверка приоритета
        if (isset($taskConditions['min_priority'])) {
            if ($task['priority'] < $taskConditions['min_priority']) {
                return false;
            }
        }

        // Проверка типа задачи
        if (isset($taskConditions['is_milestone'])) {
            if ($task['milestone'] != $taskConditions['is_milestone']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверяет условия события
     * 
     * @param array $eventConditions Условия события
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkEventConditions(array $eventConditions, array $eventData): bool
    {
        // Проверка минимальной важности
        if (isset($eventConditions['min_severity'])) {
            $severityOrder = ['important' => 1, 'critical' => 2];
            $eventSeverity = $severityOrder[$eventData['severity']] ?? 0;
            $minSeverity = $severityOrder[$eventConditions['min_severity']] ?? 0;
            
            if ($eventSeverity < $minSeverity) {
                return false;
            }
        }

        // Проверка исключения автоматически сгенерированных событий
        if (isset($eventConditions['exclude_auto_generated']) && $eventConditions['exclude_auto_generated']) {
            if ($eventData['actor_type'] === 'system') {
                return false;
            }
        }

        return true;
    }

    /**
     * Получает роль пользователя
     */
    private function getUserRole(int $userId): string
    {
        $connection = $this->database->getConnection();
        $result = $connection->executeQuery(
            "SELECT role_code FROM fw_users WHERE id = ?",
            [$userId]
        );
        
        $user = $result->fetchAssociative();
        return $user['role_code'] ?? 'contractor';
    }

    /**
     * Получает данные проекта
     */
    private function getProject(int $projectId): ?array
    {
        $connection = $this->database->getConnection();
        $result = $connection->executeQuery(
            "SELECT id, budget, status, project_type FROM fw_projects WHERE id = ?",
            [$projectId]
        );
        
        return $result->fetchAssociative() ?: null;
    }

    /**
     * Получает данные задачи
     */
    private function getTask(int $taskId): ?array
    {
        $connection = $this->database->getConnection();
        $result = $connection->executeQuery(
            "SELECT id, status, priority, milestone FROM fw_prj_tasks WHERE id = ?",
            [$taskId]
        );
        
        return $result->fetchAssociative() ?: null;
    }

    /**
     * Проверяет, рабочее ли время
     */
    private function isBusinessHours(string $timezone = 'UTC'): bool
    {
        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $hour = (int)$now->format('H');
        
        // Рабочее время: 9:00 - 17:00
        return $hour >= 9 && $hour < 17;
    }

    /**
     * Проверяет, будний ли день
     */
    private function isWeekday(): bool
    {
        $dayOfWeek = (int)date('N'); // 1 = понедельник, 7 = воскресенье
        return $dayOfWeek >= 1 && $dayOfWeek <= 5;
    }

    /**
     * Проверяет, входит ли время в диапазон
     */
    private function isInTimeRange(string $startTime, string $endTime): bool
    {
        $now = new \DateTime('now');
        $start = new \DateTime($now->format('Y-m-d') . ' ' . $startTime);
        $end = new \DateTime($now->format('Y-m-d') . ' ' . $endTime);
        
        return $now >= $start && $now <= $end;
    }

    /**
     * Возвращает список доступных типов условий
     */
    public function getAvailableConditions(): array
    {
        return [
            // === РОЛИ И ПОЛЬЗОВАТЕЛИ ===
            'user_roles' => [
                'description' => 'Разрешенные роли пользователей для срабатывания правила',
                'type' => 'array',
                'values' => ['admin', 'project_manager', 'contractor', 'architect', 'viewer', 'guest'],
                'example' => ['admin', 'project_manager']
            ],
            'exclude_roles' => [
                'description' => 'Исключенные роли пользователей',
                'type' => 'array',
                'values' => ['admin', 'project_manager', 'contractor', 'architect', 'viewer', 'guest'],
                'example' => ['contractor']
            ],
            'notify_roles' => [
                'description' => 'Роли для уведомления (обязательно для action: notify)',
                'type' => 'array',
                'values' => ['admin', 'project_manager', 'contractor', 'architect', 'viewer', 'guest'],
                'example' => ['admin', 'project_manager']
            ],
            'user_conditions' => [
                'description' => 'Условия пользователя',
                'type' => 'object',
                'properties' => [
                    'min_experience_days' => ['type' => 'number', 'description' => 'Минимальный опыт в днях'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Только активные пользователи'],
                    'has_permissions' => ['type' => 'array', 'description' => 'Обязательные разрешения'],
                    'exclude_permissions' => ['type' => 'array', 'description' => 'Исключенные разрешения']
                ],
                'example' => [
                    'min_experience_days' => 30,
                    'is_active' => true
                ]
            ],

            // === ВРЕМЕННЫЕ УСЛОВИЯ ===
            'time_conditions' => [
                'description' => 'Временные условия',
                'type' => 'object',
                'properties' => [
                    'business_hours_only' => ['type' => 'boolean', 'description' => 'Только в рабочее время'],
                    'weekdays_only' => ['type' => 'boolean', 'description' => 'Только в будние дни'],
                    'weekends_only' => ['type' => 'boolean', 'description' => 'Только в выходные'],
                    'timezone' => ['type' => 'string', 'description' => 'Часовой пояс'],
                    'time_range' => [
                        'type' => 'object',
                        'properties' => [
                            'start' => ['type' => 'string', 'example' => '09:00'],
                            'end' => ['type' => 'string', 'example' => '17:00']
                        ]
                    ],
                    'specific_hours' => ['type' => 'array', 'description' => 'Конкретные часы (0-23)'],
                    'specific_days' => ['type' => 'array', 'description' => 'Конкретные дни недели (1-7)'],
                    'exclude_holidays' => ['type' => 'boolean', 'description' => 'Исключить праздники']
                ],
                'example' => [
                    'business_hours_only' => true,
                    'timezone' => 'America/New_York',
                    'specific_hours' => [9, 10, 11, 14, 15, 16]
                ]
            ],

            // === ПРОЕКТНЫЕ УСЛОВИЯ ===
            'project_conditions' => [
                'description' => 'Условия проекта',
                'type' => 'object',
                'properties' => [
                    'min_budget' => ['type' => 'number', 'description' => 'Минимальный бюджет'],
                    'max_budget' => ['type' => 'number', 'description' => 'Максимальный бюджет'],
                    'status' => ['type' => 'array', 'description' => 'Разрешенные статусы'],
                    'exclude_status' => ['type' => 'array', 'description' => 'Исключенные статусы'],
                    'project_type' => ['type' => 'string', 'description' => 'Тип проекта'],
                    'priority' => ['type' => 'array', 'description' => 'Разрешенные приоритеты'],
                    'min_duration_days' => ['type' => 'number', 'description' => 'Минимальная длительность в днях'],
                    'max_duration_days' => ['type' => 'number', 'description' => 'Максимальная длительность в днях'],
                    'has_milestones' => ['type' => 'boolean', 'description' => 'Только проекты с вехами'],
                    'team_size' => [
                        'type' => 'object',
                        'properties' => [
                            'min' => ['type' => 'number'],
                            'max' => ['type' => 'number']
                        ]
                    ]
                ],
                'example' => [
                    'min_budget' => 100000,
                    'status' => ['active', 'planning'],
                    'priority' => ['high', 'critical']
                ]
            ],

            // === УСЛОВИЯ ЗАДАЧ ===
            'task_conditions' => [
                'description' => 'Условия задачи',
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'array', 'description' => 'Разрешенные статусы'],
                    'exclude_status' => ['type' => 'array', 'description' => 'Исключенные статусы'],
                    'min_priority' => ['type' => 'number', 'description' => 'Минимальный приоритет'],
                    'max_priority' => ['type' => 'number', 'description' => 'Максимальный приоритет'],
                    'is_milestone' => ['type' => 'boolean', 'description' => 'Только вехи'],
                    'has_dependencies' => ['type' => 'boolean', 'description' => 'Только задачи с зависимостями'],
                    'min_duration_hours' => ['type' => 'number', 'description' => 'Минимальная длительность в часах'],
                    'max_duration_hours' => ['type' => 'number', 'description' => 'Максимальная длительность в часах'],
                    'overdue_only' => ['type' => 'boolean', 'description' => 'Только просроченные задачи'],
                    'due_soon_days' => ['type' => 'number', 'description' => 'Срок истекает через N дней']
                ],
                'example' => [
                    'status' => ['in_progress', 'blocked'],
                    'min_priority' => 3,
                    'overdue_only' => true
                ]
            ],

            // === УСЛОВИЯ СОБЫТИЙ ===
            'event_conditions' => [
                'description' => 'Условия события',
                'type' => 'object',
                'properties' => [
                    'min_severity' => ['type' => 'string', 'values' => ['low', 'important', 'critical'], 'description' => 'Минимальная важность'],
                    'exclude_auto_generated' => ['type' => 'boolean', 'description' => 'Исключить автоматические события'],
                    'only_auto_generated' => ['type' => 'boolean', 'description' => 'Только автоматические события'],
                    'min_frequency_hours' => ['type' => 'number', 'description' => 'Минимальная частота в часах'],
                    'max_frequency_hours' => ['type' => 'number', 'description' => 'Максимальная частота в часах'],
                    'source' => ['type' => 'array', 'description' => 'Источники события'],
                    'exclude_source' => ['type' => 'array', 'description' => 'Исключенные источники']
                ],
                'example' => [
                    'min_severity' => 'important',
                    'exclude_auto_generated' => true
                ]
            ],

            // === СИСТЕМНЫЕ УСЛОВИЯ ===
            'system_conditions' => [
                'description' => 'Системные условия',
                'type' => 'object',
                'properties' => [
                    'environment' => ['type' => 'array', 'description' => 'Окружения (production, staging, development)'],
                    'min_load_average' => ['type' => 'number', 'description' => 'Минимальная нагрузка системы'],
                    'max_load_average' => ['type' => 'number', 'description' => 'Максимальная нагрузка системы'],
                    'disk_space_percent' => ['type' => 'number', 'description' => 'Процент свободного места на диске'],
                    'memory_usage_percent' => ['type' => 'number', 'description' => 'Процент использования памяти']
                ],
                'example' => [
                    'environment' => ['production'],
                    'disk_space_percent' => 20
                ]
            ],

            // === ГЕОГРАФИЧЕСКИЕ УСЛОВИЯ ===
            'location_conditions' => [
                'description' => 'Географические условия',
                'type' => 'object',
                'properties' => [
                    'countries' => ['type' => 'array', 'description' => 'Разрешенные страны'],
                    'exclude_countries' => ['type' => 'array', 'description' => 'Исключенные страны'],
                    'timezones' => ['type' => 'array', 'description' => 'Разрешенные часовые пояса'],
                    'regions' => ['type' => 'array', 'description' => 'Разрешенные регионы']
                ],
                'example' => [
                    'countries' => ['US', 'CA'],
                    'timezones' => ['America/New_York', 'America/Los_Angeles']
                ]
            ],

            // === УСЛОВИЯ БЕЗОПАСНОСТИ ===
            'security_conditions' => [
                'description' => 'Условия безопасности',
                'type' => 'object',
                'properties' => [
                    'require_2fa' => ['type' => 'boolean', 'description' => 'Требовать двухфакторную аутентификацию'],
                    'ip_whitelist' => ['type' => 'array', 'description' => 'Белый список IP адресов'],
                    'ip_blacklist' => ['type' => 'array', 'description' => 'Черный список IP адресов'],
                    'min_password_strength' => ['type' => 'string', 'description' => 'Минимальная сложность пароля'],
                    'session_timeout_minutes' => ['type' => 'number', 'description' => 'Таймаут сессии в минутах']
                ],
                'example' => [
                    'require_2fa' => true,
                    'ip_whitelist' => ['192.168.1.0/24']
                ]
            ]
        ];
    }

    /**
     * Возвращает список доступных действий
     */
    public function getAvailableActions(): array
    {
        return [
            // === ОСНОВНЫЕ ДЕЙСТВИЯ ===
            'notify' => [
                'description' => 'Отправить уведомление',
                'requires' => ['notify_roles'],
                'channels' => ['email', 'sms', 'push', 'webhook', 'slack']
            ],
            'log_only' => [
                'description' => 'Только логирование без уведомлений',
                'requires' => [],
                'channels' => ['database', 'file']
            ],
            'create_daily_report' => [
                'description' => 'Создать ежедневный отчет',
                'requires' => [],
                'channels' => ['email', 'dashboard']
            ],

            // === УВЕДОМЛЕНИЯ ===
            'email' => [
                'description' => 'Отправить email уведомление',
                'requires' => ['notify_roles'],
                'channels' => ['email']
            ],
            'sms' => [
                'description' => 'Отправить SMS уведомление',
                'requires' => ['notify_roles'],
                'channels' => ['sms']
            ],
            'push' => [
                'description' => 'Отправить push уведомление',
                'requires' => ['notify_roles'],
                'channels' => ['push']
            ],
            'webhook' => [
                'description' => 'Отправить webhook',
                'requires' => [],
                'channels' => ['webhook']
            ],
            'slack' => [
                'description' => 'Отправить сообщение в Slack',
                'requires' => [],
                'channels' => ['slack']
            ],

            // === ОТЧЕТЫ ===
            'create_report' => [
                'description' => 'Создать отчет',
                'requires' => [],
                'channels' => ['email', 'dashboard', 'file']
            ],
            'create_weekly_report' => [
                'description' => 'Создать еженедельный отчет',
                'requires' => [],
                'channels' => ['email', 'dashboard']
            ],
            'create_monthly_report' => [
                'description' => 'Создать ежемесячный отчет',
                'requires' => [],
                'channels' => ['email', 'dashboard']
            ],

            // === СИСТЕМНЫЕ ДЕЙСТВИЯ ===
            'backup' => [
                'description' => 'Создать резервную копию',
                'requires' => [],
                'channels' => ['file', 'cloud']
            ],
            'cleanup' => [
                'description' => 'Очистить временные файлы/данные',
                'requires' => [],
                'channels' => ['system']
            ],
            'restart_service' => [
                'description' => 'Перезапустить сервис',
                'requires' => [],
                'channels' => ['system']
            ],
            'scale_resource' => [
                'description' => 'Масштабировать ресурсы',
                'requires' => [],
                'channels' => ['system']
            ],

            // === БЕЗОПАСНОСТЬ ===
            'block_user' => [
                'description' => 'Заблокировать пользователя',
                'requires' => [],
                'channels' => ['database']
            ],
            'suspend_user' => [
                'description' => 'Приостановить пользователя',
                'requires' => [],
                'channels' => ['database']
            ],
            'reset_password' => [
                'description' => 'Сбросить пароль',
                'requires' => [],
                'channels' => ['email', 'database']
            ],
            'enable_2fa' => [
                'description' => 'Включить двухфакторную аутентификацию',
                'requires' => [],
                'channels' => ['database']
            ],

            // === ИНТЕГРАЦИИ ===
            'sync_data' => [
                'description' => 'Синхронизировать данные',
                'requires' => [],
                'channels' => ['api', 'database']
            ],
            'export_data' => [
                'description' => 'Экспортировать данные',
                'requires' => [],
                'channels' => ['file', 'email']
            ],
            'import_data' => [
                'description' => 'Импортировать данные',
                'requires' => [],
                'channels' => ['file', 'api']
            ],

            // === АНАЛИТИКА ===
            'track_metric' => [
                'description' => 'Отследить метрику',
                'requires' => [],
                'channels' => ['analytics']
            ],
            'update_dashboard' => [
                'description' => 'Обновить дашборд',
                'requires' => [],
                'channels' => ['dashboard']
            ],
            'generate_chart' => [
                'description' => 'Сгенерировать график',
                'requires' => [],
                'channels' => ['dashboard', 'file']
            ],

            // === РАБОЧИЕ ПРОЦЕССЫ ===
            'start_workflow' => [
                'description' => 'Запустить рабочий процесс',
                'requires' => [],
                'channels' => ['workflow']
            ],
            'pause_workflow' => [
                'description' => 'Приостановить рабочий процесс',
                'requires' => [],
                'channels' => ['workflow']
            ],
            'resume_workflow' => [
                'description' => 'Возобновить рабочий процесс',
                'requires' => [],
                'channels' => ['workflow']
            ],
            'complete_workflow' => [
                'description' => 'Завершить рабочий процесс',
                'requires' => [],
                'channels' => ['workflow']
            ],

            // === КАСТОМНЫЕ ДЕЙСТВИЯ ===
            'custom_action' => [
                'description' => 'Пользовательское действие',
                'requires' => [],
                'channels' => ['custom']
            ],
            'api_call' => [
                'description' => 'Вызов внешнего API',
                'requires' => [],
                'channels' => ['api']
            ],
            'database_query' => [
                'description' => 'Выполнить запрос к базе данных',
                'requires' => [],
                'channels' => ['database']
            ]
        ];
    }
}
