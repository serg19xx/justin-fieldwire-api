# Интеграция системы аутентификации и аудита

## Обзор

Система аутентификации теперь включает полноценный аудит пользователей с отслеживанием:
- Логинов и логаутов
- Продолжительности сессий
- API вызовов
- Неудачных попыток входа
- Изменений профиля и паролей

## Новые API Endpoints

### 1. Логаут пользователя
```http
POST /api/v1/auth/logout
Authorization: Bearer <token>
```

**Ответ:**
```json
{
    "error_code": 0,
    "status": "success",
    "message": "Logout successful",
    "data": null
}
```

### 2. Проверка сессии
```http
POST /api/v1/auth/check-session
Authorization: Bearer <token>
```

**Ответ:**
```json
{
    "error_code": 0,
    "status": "success",
    "message": "Session is valid",
    "data": {
        "user": {
            "id": 1,
            "email": "user@example.com",
            "first_name": "John",
            "last_name": "Doe"
        },
        "session_valid": true,
        "last_activity": "2024-01-15T10:30:00Z"
    }
}
```

## Стратегии отслеживания логаута

### 1. Явный логаут
Когда пользователь нажимает кнопку "Выйти":
- Frontend вызывает `POST /api/v1/auth/logout`
- Система логирует логаут с продолжительностью сессии
- Токен становится недействительным

### 2. Автоматический логаут по истечению токена
- Токен имеет время жизни (24 часа)
- При каждом API запросе проверяется валидность токена
- Если токен истек - пользователь автоматически считается вышедшим

### 3. Проверка активности
- Frontend периодически вызывает `POST /api/v1/auth/check-session`
- Обновляется время последней активности
- Если пользователь неактивен долгое время - сессия завершается

### 4. Session timeout
- Можно добавить поле `last_activity` в таблицу пользователей
- Если `last_activity` старше определенного времени - сессия недействительна

## Таблица аудита

Все действия пользователей записываются в таблицу `fw_user_audit_log`:

```sql
CREATE TABLE fw_user_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action_type ENUM(
        'login', 
        'logout', 
        'login_failed', 
        'session_start', 
        'session_end', 
        'password_change', 
        'profile_update',
        'twofa_enabled',
        'twofa_disabled',
        'api_call'
    ) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(255),
    duration_seconds INT NULL,
    success BOOLEAN DEFAULT TRUE,
    error_message VARCHAR(500) NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Представления (Views)

### 1. Сессии пользователей
```sql
CREATE VIEW v_user_sessions AS
SELECT 
    user_id,
    session_id,
    MIN(created_at) as session_start,
    MAX(created_at) as session_end,
    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) as session_duration_seconds,
    COUNT(*) as total_actions,
    SUM(CASE WHEN action_type = 'login' THEN 1 ELSE 0 END) as login_count,
    SUM(CASE WHEN action_type = 'logout' THEN 1 ELSE 0 END) as logout_count,
    MAX(ip_address) as ip_address,
    MAX(user_agent) as user_agent
FROM fw_user_audit_log 
WHERE action_type IN ('login', 'logout', 'session_start', 'session_end')
GROUP BY user_id, session_id
ORDER BY session_start DESC;
```

### 2. Ежедневная активность
```sql
CREATE VIEW v_daily_user_activity AS
SELECT 
    user_id,
    DATE(created_at) as activity_date,
    COUNT(*) as total_actions,
    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_actions,
    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_actions,
    COUNT(DISTINCT action_type) as unique_action_types,
    GROUP_CONCAT(DISTINCT action_type) as action_types,
    MIN(created_at) as first_action,
    MAX(created_at) as last_action,
    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) as activity_duration_seconds
FROM fw_user_audit_log 
GROUP BY user_id, DATE(created_at)
ORDER BY activity_date DESC, user_id;
```

## Интеграция с Frontend

### JavaScript Service
Используйте `AuthService` класс из `examples/frontend-integration/auth-service.js`:

```javascript
const authService = new AuthService();

// Логин
const result = await authService.login(email, password);

// Проверка сессии
const isValid = await authService.checkSession();

// Логаут
await authService.logout();

// Автоматическая проверка сессии
authService.startSessionCheck();
```

### Рекомендуемая стратегия

1. **При загрузке страницы:**
   - Проверить наличие токена в localStorage
   - Вызвать `checkSession()` для валидации
   - Запустить автоматическую проверку

2. **Периодическая проверка:**
   - Каждые 5 минут вызывать `checkSession()`
   - При истечении сессии - автоматический логаут

3. **При API запросах:**
   - Добавлять токен в заголовок Authorization
   - Обрабатывать 401 ошибки как истечение сессии

4. **При закрытии браузера:**
   - Можно вызвать logout (но это не всегда работает)
   - Полагаться на истечение токена

## Мониторинг и аналитика

### Запросы для анализа

```sql
-- Активные пользователи за последние 24 часа
SELECT user_id, COUNT(*) as activity_count
FROM fw_user_audit_log 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY user_id
ORDER BY activity_count DESC;

-- Неудачные попытки входа
SELECT ip_address, COUNT(*) as failed_attempts
FROM fw_user_audit_log 
WHERE action_type = 'login_failed' 
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY ip_address
ORDER BY failed_attempts DESC;

-- Средняя продолжительность сессий
SELECT AVG(duration_seconds) as avg_session_duration
FROM fw_user_audit_log 
WHERE action_type = 'logout' 
AND duration_seconds IS NOT NULL;
```

### Логирование событий

Все события аутентификации записываются в:
- Таблицу аудита `fw_user_audit_log`
- Логи приложения через Monolog
- Системные логи для мониторинга

## Безопасность

1. **Токены:**
   - Время жизни: 24 часа
   - Хранятся в localStorage (можно улучшить до httpOnly cookies)
   - Проверяются при каждом запросе

2. **Аудит:**
   - Все действия логируются
   - IP адреса и User-Agent записываются
   - Неудачные попытки отслеживаются

3. **Сессии:**
   - Автоматическое завершение при неактивности
   - Проверка валидности токена
   - Логирование продолжительности сессий

## Рекомендации по улучшению

1. **Добавить поле `last_activity` в таблицу пользователей**
2. **Реализовать refresh tokens для продления сессий**
3. **Добавить rate limiting для предотвращения брутфорса**
4. **Использовать httpOnly cookies вместо localStorage**
5. **Добавить уведомления о подозрительной активности**
