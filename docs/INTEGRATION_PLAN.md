# План интеграции системы логирования в контроллеры

## 🎯 **Стратегия интеграции**

### **Принципы:**
1. **Поэтапное внедрение** - не все сразу, по приоритетам
2. **Тестирование на каждом этапе** - проверка работоспособности
3. **Условное логирование** - не все операции требуют логирования
4. **Производительность** - не замедлять основные операции
5. **Безопасность** - не логировать чувствительные данные

## 📋 **Этап 1: Критически важные операции (Высокий приоритет)**

### **1.1 TaskController** 
**Операции для логирования:**
- ✅ `createTask()` → `TASK_CREATED`
- ✅ `updateTask()` → `TASK_UPDATED` / `TASK_STATUS_CHANGED` / `TASK_SCHEDULE_CHANGED`
- ✅ `deleteTask()` → `TASK_DELETED`

**Условия логирования:**
- Всегда логировать создание и удаление
- При обновлении - только значительные изменения (статус, расписание, исполнители)

### **1.2 ProjectController**
**Операции для логирования:**
- ✅ `createProject()` → `PROJECT_CREATED`
- ✅ `updateProject()` → `PROJECT_UPDATED` / `PROJECT_STATUS_CHANGED`
- ✅ `deleteProject()` → `PROJECT_DELETED`

**Условия логирования:**
- Всегда логировать создание и удаление
- При обновлении - только значительные изменения (статус, менеджер, даты)

### **1.3 AuthController**
**Операции для логирования:**
- ✅ `login()` → `USER_LOGIN` (критическое событие)
- ✅ `logout()` → `USER_LOGOUT`
- ✅ `register()` → `USER_REGISTERED` (критическое событие)

**Условия логирования:**
- Всегда логировать (критические операции безопасности)

### **1.4 ProfileController**
**Операции для логирования:**
- ✅ `updateProfile()` → `PROFILE_UPDATED`
- ✅ `uploadAvatar()` → `AVATAR_UPLOADED`

**Условия логирования:**
- Всегда логировать (изменения профиля важны для аудита)

## 📋 **Этап 2: Важные бизнес-операции (Средний приоритет)**

### **2.1 ProjectTeamController**
**Операции для логирования:**
- ✅ `addTeamMember()` → `PROJECT_MEMBER_ADDED`
- ✅ `removeTeamMember()` → `PROJECT_MEMBER_REMOVED`
- ✅ `updateTeamMemberRole()` → `PROJECT_MEMBER_ROLE_CHANGED`

### **2.2 TwoFactorController**
**Операции для логирования:**
- ✅ `enable2FA()` → `2FA_ENABLED` (критическое событие)
- ✅ `disable2FA()` → `2FA_DISABLED` (критическое событие)

### **2.3 WorkerController**
**Операции для логирования:**
- ✅ `inviteWorker()` → `WORKER_INVITED`
- ✅ `updateWorkerStatus()` → `WORKER_STATUS_CHANGED`

## 📋 **Этап 3: Справочные данные (Низкий приоритет)**

### **3.1 Медицинские контроллеры**
- PatientController (CRUD операции)
- PhysicianController (CRUD операции)
- MedicalClinicController (CRUD операции)

### **3.2 Фармацевтические контроллеры**
- PharmacyController (CRUD операции)
- PharmacistController (CRUD операции)

### **3.3 Другие контроллеры**
- DriverController (CRUD операции)

## 🔧 **Техническая реализация**

### **Паттерн интеграции:**

```php
// 1. Добавить сервис в конструктор
private EventLoggingService $eventLoggingService;

public function __construct(Logger $logger)
{
    $this->logger = $logger;
    $this->eventLoggingService = new EventLoggingService($logger);
}

// 2. Логировать после успешной операции
public function createTask(int $projectId): void
{
    try {
        // ... существующая логика создания ...
        
        $taskId = $connection->lastInsertId();
        
        // Логируем создание
        $this->eventLoggingService->logEvent(
            entityType: 'task',
            entityId: $taskId,
            eventType: 'TASK_CREATED',
            beforeData: [],
            afterData: $taskData,
            changedFields: array_keys($taskData),
            options: [
                'comment' => "Task created: {$taskData['name']}",
                'actor_type' => 'user',
                'actor_id' => $this->getCurrentUserId()
            ]
        );
        
        // ... возврат ответа ...
        
    } catch (\Exception $e) {
        // ... обработка ошибок ...
    }
}
```

### **Условное логирование:**

```php
// Логировать только значительные изменения
public function updateTask(int $projectId, int $taskId): void
{
    try {
        // Получаем текущие данные
        $currentTask = $this->getTaskById($taskId);
        
        // ... обновление в БД ...
        
        // Определяем, нужно ли логировать
        $significantChanges = $this->getSignificantChanges($currentTask, $newData);
        
        if (!empty($significantChanges)) {
            $this->eventLoggingService->logEvent(
                entityType: 'task',
                entityId: $taskId,
                eventType: $this->determineEventType($significantChanges),
                beforeData: $currentTask,
                afterData: $newData,
                changedFields: array_keys($significantChanges),
                options: [
                    'comment' => 'Significant task changes detected',
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId()
                ]
            );
        }
        
    } catch (\Exception $e) {
        // ... обработка ошибок ...
    }
}
```

## 📊 **Метрики и мониторинг**

### **Что отслеживать:**
1. **Количество событий** - по типам и контроллерам
2. **Производительность** - время выполнения операций
3. **Ошибки логирования** - failed events
4. **Outbox события** - pending/processing/completed/failed

### **Алерты:**
1. **Высокий объем событий** - возможная проблема
2. **Ошибки логирования** - проблемы с системой
3. **Застрявшие outbox события** - проблемы с N8N

## 🧪 **План тестирования**

### **Для каждого этапа:**
1. **Unit тесты** - тестирование логирования
2. **Integration тесты** - тестирование с БД
3. **API тесты** - тестирование через HTTP
4. **N8N тесты** - тестирование workflow

### **Критерии готовности:**
- ✅ Все тесты проходят
- ✅ Нет ошибок в логах
- ✅ Outbox события создаются корректно
- ✅ N8N получает события
- ✅ Производительность не ухудшилась

## 📅 **Временные рамки**

### **Этап 1 (Высокий приоритет):** 1-2 недели
- TaskController: 3-4 дня
- ProjectController: 3-4 дня  
- AuthController: 2-3 дня
- ProfileController: 2-3 дня

### **Этап 2 (Средний приоритет):** 1-2 недели
- ProjectTeamController: 2-3 дня
- TwoFactorController: 1-2 дня
- WorkerController: 2-3 дня

### **Этап 3 (Низкий приоритет):** 2-3 недели
- Медицинские контроллеры: 1-2 недели
- Фармацевтические контроллеры: 1 неделя

## ⚠️ **Риски и митигация**

### **Риски:**
1. **Производительность** - замедление операций
2. **Ошибки логирования** - сбой основной функциональности
3. **Объем данных** - переполнение БД событиями
4. **Сложность** - усложнение кода

### **Митигация:**
1. **Асинхронное логирование** - не блокировать основные операции
2. **Try-catch блоки** - изолировать ошибки логирования
3. **Архивирование** - старые события в архив
4. **Документация** - четкие примеры интеграции

## 🎯 **Критерии успеха**

1. **Все критические операции логируются**
2. **N8N получает события и выполняет workflow**
3. **Производительность не ухудшилась более чем на 10%**
4. **Нет ошибок в production**
5. **Система мониторинга работает**
6. **Документация актуальна**

## 📝 **Следующие шаги**

1. **Начать с TaskController** - создать пример интеграции
2. **Протестировать на dev окружении**
3. **Получить обратную связь от команды**
4. **Итеративно улучшать**
5. **Постепенно расширять на другие контроллеры**
