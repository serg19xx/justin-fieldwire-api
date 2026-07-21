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
            foreach ($conditions as $conditionType => $conditionValue) {
                // Legacy / non-filter keys — ignore
                if (in_array($conditionType, ['strict_mode', 'notify_roles'], true)) {
                    continue;
                }

                $unwrapped = $this->unwrapConditionValue($conditionValue);
                if (!$this->checkCondition($conditionType, $unwrapped, $eventData)) {
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
     * Unwrap { value, priority } envelope used by older UI payloads.
     *
     * @param mixed $conditionValue
     * @return mixed
     */
    private function unwrapConditionValue($conditionValue)
    {
        if (
            is_array($conditionValue)
            && array_key_exists('value', $conditionValue)
            && (array_key_exists('priority', $conditionValue) || count($conditionValue) === 1)
        ) {
            return $conditionValue['value'];
        }

        return $conditionValue;
    }

    /**
     * Проверяет конкретное условие
     */
    private function checkCondition(string $conditionType, $conditionValue, array $eventData): bool
    {
        if (!is_array($conditionValue) && in_array($conditionType, [
            'user_roles', 'exclude_roles', 'time_conditions',
            'project_conditions', 'task_conditions', 'event_conditions',
        ], true)) {
            return true;
        }

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
     * Normalize schedule to crontab-like shape (frequency + time window).
     * Accepts legacy business_hours / weekdays / time_range payloads.
     *
     * @param array<string, mixed> $timeConditions
     * @return array{
     *   frequency: string,
     *   days_of_week: list<int>,
     *   monthly_mode: string,
     *   day_of_month: int,
     *   day_of_month_last: bool,
     *   weekday_occurrence: int,
     *   months: list<int>,
     *   at_time: string,
     *   until_time: string,
     *   timezone: string
     * }
     */
    public function normalizeTimeConditions(array $timeConditions): array
    {
        $timezone = trim((string) ($timeConditions['timezone'] ?? 'UTC'));
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        $frequency = strtolower(trim((string) ($timeConditions['frequency'] ?? '')));
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            $frequency = !empty($timeConditions['weekdays_only']) ? 'weekly' : 'daily';
        }

        $days = [];
        if (isset($timeConditions['days_of_week']) && is_array($timeConditions['days_of_week'])) {
            foreach ($timeConditions['days_of_week'] as $d) {
                $n = (int) $d;
                if ($n >= 1 && $n <= 7) {
                    $days[] = $n;
                }
            }
        } elseif (isset($timeConditions['specific_days']) && is_array($timeConditions['specific_days'])) {
            foreach ($timeConditions['specific_days'] as $d) {
                $n = (int) $d;
                if ($n >= 1 && $n <= 7) {
                    $days[] = $n;
                }
            }
        } elseif (!empty($timeConditions['weekdays_only'])) {
            $days = [1, 2, 3, 4, 5];
        } elseif (!empty($timeConditions['weekends_only'])) {
            $days = [6, 7];
        } elseif ($frequency === 'weekly') {
            $days = [1]; // default Monday
        } else {
            $days = [1, 2, 3, 4, 5, 6, 7];
        }
        $days = array_values(array_unique($days));
        sort($days);

        $monthlyMode = strtolower(trim((string) ($timeConditions['monthly_mode'] ?? 'day_of_month')));
        if (!in_array($monthlyMode, ['day_of_month', 'nth_weekday'], true)) {
            $monthlyMode = 'day_of_month';
        }

        $dayOfMonthLast = !empty($timeConditions['day_of_month_last']);
        $dayOfMonth = (int) ($timeConditions['day_of_month'] ?? 1);
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            $dayOfMonth = 1;
        }

        $weekdayOccurrence = (int) ($timeConditions['weekday_occurrence'] ?? 1);
        if (!in_array($weekdayOccurrence, [1, 2, 3, 4, -1], true)) {
            $weekdayOccurrence = 1;
        }
        if ($monthlyMode === 'nth_weekday' && $days === []) {
            $days = [1];
        }

        $months = [];
        if (isset($timeConditions['months']) && is_array($timeConditions['months'])) {
            foreach ($timeConditions['months'] as $m) {
                $n = (int) $m;
                if ($n >= 1 && $n <= 12) {
                    $months[] = $n;
                }
            }
            $months = array_values(array_unique($months));
            sort($months);
        }

        $atTime = trim((string) ($timeConditions['at_time'] ?? ''));
        $untilTime = trim((string) ($timeConditions['until_time'] ?? ''));
        if ($atTime === '' && isset($timeConditions['time_range']) && is_array($timeConditions['time_range'])) {
            $atTime = trim((string) ($timeConditions['time_range']['start'] ?? ''));
            if ($untilTime === '') {
                $untilTime = trim((string) ($timeConditions['time_range']['end'] ?? ''));
            }
        }
        if ($atTime === '' && !empty($timeConditions['business_hours_only'])) {
            $atTime = '09:00';
            if ($untilTime === '') {
                $untilTime = '17:00';
            }
        }
        if ($atTime === '') {
            $atTime = '09:00';
        }
        if ($untilTime === '') {
            $untilTime = $this->addMinutesToHhmm($atTime, 30);
        }

        return [
            'frequency' => $frequency,
            'days_of_week' => $days,
            'monthly_mode' => $monthlyMode,
            'day_of_month' => $dayOfMonth,
            'day_of_month_last' => $dayOfMonthLast,
            'weekday_occurrence' => $weekdayOccurrence,
            'months' => $months,
            'at_time' => $atTime,
            'until_time' => $untilTime,
            'timezone' => $timezone,
        ];
    }

    /**
     * Evaluate crontab-like schedule against "now".
     *
     * @param array<string, mixed>|null $timeConditions
     * @return 'none'|'match'|'before'|'after'|'wrong_day'
     */
    public function evaluateSchedule(?array $timeConditions, ?\DateTimeImmutable $now = null): string
    {
        if ($timeConditions === null || $timeConditions === []) {
            return 'none';
        }

        $schedule = $this->normalizeTimeConditions($timeConditions);
        try {
            $tz = new \DateTimeZone($schedule['timezone']);
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $dow = (int) $now->format('N'); // 1=Mon … 7=Sun
        $month = (int) $now->format('n');

        if ($schedule['months'] !== [] && !in_array($month, $schedule['months'], true)) {
            return 'wrong_day';
        }

        $frequency = $schedule['frequency'];
        if ($frequency === 'weekly' || $frequency === 'daily') {
            if (!in_array($dow, $schedule['days_of_week'], true)) {
                return 'wrong_day';
            }
        }
        if ($frequency === 'monthly') {
            if (!$this->matchesMonthlyDay($now, $schedule)) {
                return 'wrong_day';
            }
        }

        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $schedule['at_time'], $tz);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $schedule['until_time'], $tz);
        if (!$start || !$end) {
            return 'wrong_day';
        }
        // Support overnight windows (e.g. 23:00 → 01:00)
        if ($end < $start) {
            if ($now >= $start || $now <= $end) {
                return 'match';
            }
            return $now < $start ? 'before' : 'after';
        }

        if ($now < $start) {
            return 'before';
        }
        if ($now > $end) {
            return 'after';
        }

        return 'match';
    }

    /**
     * @param array{
     *   monthly_mode: string,
     *   day_of_month: int,
     *   day_of_month_last: bool,
     *   weekday_occurrence: int,
     *   days_of_week: list<int>
     * } $schedule
     */
    private function matchesMonthlyDay(\DateTimeImmutable $now, array $schedule): bool
    {
        $dom = (int) $now->format('j');
        $lastDom = (int) $now->format('t');
        $dow = (int) $now->format('N');

        if ($schedule['monthly_mode'] === 'nth_weekday') {
            $weekday = $schedule['days_of_week'][0] ?? 1;
            return $this->isNthWeekdayOfMonth($now, (int) $schedule['weekday_occurrence'], (int) $weekday);
        }

        // Calendar day of month (optionally last day)
        if (!empty($schedule['day_of_month_last'])) {
            return $dom === $lastDom;
        }

        $target = min((int) $schedule['day_of_month'], $lastDom);
        return $dom === $target;
    }

    /**
     * Cron-style: Nth weekday of month (1=first … 4=fourth, -1=last).
     * Example: 2nd Monday → occurrence=2, weekday=1.
     */
    private function isNthWeekdayOfMonth(\DateTimeImmutable $now, int $occurrence, int $weekday): bool
    {
        if ($weekday < 1 || $weekday > 7) {
            return false;
        }
        if ((int) $now->format('N') !== $weekday) {
            return false;
        }

        if ($occurrence === -1) {
            // Last: same weekday next week is in a different month
            $next = $now->modify('+7 days');
            return (int) $next->format('n') !== (int) $now->format('n');
        }

        if ($occurrence < 1 || $occurrence > 4) {
            return false;
        }

        $nth = intdiv((int) $now->format('j') - 1, 7) + 1;
        return $nth === $occurrence;
    }

    /**
     * Проверяет временные условия (crontab-like). Нет расписания → true.
     *
     * @param array $timeConditions Условия времени
     * @param array $eventData Данные события
     * @return bool
     */
    private function checkTimeConditions(array $timeConditions, array $eventData): bool
    {
        $result = $this->evaluateSchedule($timeConditions);
        return $result === 'none' || $result === 'match';
    }

    private function addMinutesToHhmm(string $hhmm, int $minutes): string
    {
        $parts = explode(':', $hhmm);
        $h = (int) ($parts[0] ?? 9);
        $m = (int) ($parts[1] ?? 0);
        $total = ($h * 60 + $m + $minutes) % (24 * 60);
        if ($total < 0) {
            $total += 24 * 60;
        }
        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
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
    private function isWeekday(string $timezone = 'UTC'): bool
    {
        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $dayOfWeek = (int) $now->format('N'); // 1 = Monday, 7 = Sunday
        return $dayOfWeek >= 1 && $dayOfWeek <= 5;
    }

    /**
     * Проверяет, входит ли время в диапазон
     */
    private function isInTimeRange(string $startTime, string $endTime, string $timezone = 'UTC'): bool
    {
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);
        $start = new \DateTime($now->format('Y-m-d') . ' ' . $startTime, $tz);
        $end = new \DateTime($now->format('Y-m-d') . ' ' . $endTime, $tz);
        
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
                'description' => 'Send notification to action.recipients via selected channels',
                'requires' => ['recipients'],
                'channels' => ['email', 'sms', 'push']
            ],
            'log_only' => [
                'description' => 'Audit log only — no outbox / notifications',
                'requires' => [],
                'channels' => ['database']
            ],
            'create_report' => [
                'description' => 'Generate operational report (daily/weekly/monthly) and email recipients',
                'requires' => ['recipients'],
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
