# API Response Format

Все эндпоинты API возвращают унифицированную структуру ответов для упрощения обработки на фронтенде.

## 📋 Унифицированная структура ответа

### ✅ Успешные ответы (HTTP 200)
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Описание успешного действия",
  "data": {
    // Данные ответа
  }
}
```

### ❌ Ошибочные ответы (HTTP 400, 401, 404, 500)
```json
{
  "error_code": 400|401|404|500,
  "status": "error",
  "message": "Описание ошибки",
  "data": null,
  "details": {
    // Дополнительные детали ошибки (опционально)
  }
}
```

## 🔑 Поля ответа

| Поле | Тип | Описание |
|------|-----|----------|
| `error_code` | integer | Код ошибки (0 для успеха, >0 для ошибок) |
| `status` | string | Статус: `"success"` или `"error"` |
| `message` | string | Человекочитаемое сообщение |
| `data` | object\|null | Данные ответа (null для ошибок) |
| `details` | object\|string | Дополнительные детали ошибки (опционально) |

## 📝 Примеры ответов

### 🔐 Аутентификация

#### Успешный логин
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "email": "admin@medicalcontractor.ca",
      "first_name": "Justin",
      "last_name": "Admin",
      "name": "Justin Admin",
      "phone": null,
      "user_type": "System Administrator",
      "job_title": "System Administrator",
      "status": "active",
      "additional_info": null,
      "avatar_url": null,
      "two_factor_enabled": false,
      "last_login": "2025-08-31 07:36:07"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_at": "2025-08-31T14:36:12+02:00"
  }
}
```

#### Ошибка аутентификации
```json
{
  "error_code": 401,
  "status": "error",
  "message": "Invalid email or password",
  "data": null
}
```

#### Ошибка валидации
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Invalid input data. Email and password are required.",
  "data": null,
  "details": {
    "email": "Valid email address is required",
    "password": "Password is required"
  }
}
```

### 🏥 Системные эндпоинты

#### Health Check
```json
{
  "error_code": 0,
  "status": "success",
  "message": "API is healthy",
  "data": {
    "health_status": "healthy",
    "timestamp": "2025-08-31T00:17:52+02:00",
    "uptime": {
      "seconds": 0,
      "formatted": "00:00:00"
    },
    "memory_usage": {
      "current": 4194304,
      "peak": 4194304,
      "limit": "512M"
    },
    "version": "1.0.0",
    "database": {
      "status": "connected"
    }
  }
}
```

#### Версия API
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Version information retrieved",
  "data": {
    "api_version": "v1",
    "status": "stable",
    "released": "2025-08-28",
    "endpoints": {
      "health": "/api/v1/health",
      "version": "/api/v1/version"
    }
  }
}
```

#### Таблицы базы данных
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Database tables retrieved successfully",
  "data": {
    "tables": ["users", "admin", "api_logs", ...],
    "count": 56,
    "database": "yjyhtqh8_easyrx",
    "timestamp": "2025-08-31T00:17:56+02:00"
  }
}
```

## 🚀 Обработка на фронтенде

### TypeScript интерфейсы

```typescript
interface ApiResponse<T = any> {
  error_code: number;
  status: 'success' | 'error';
  message: string;
  data: T | null;
  details?: any;
}

interface User {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  name: string;
  phone: string | null;
  user_type: string;
  job_title: string | null;
  status: string;
  additional_info: string | null;
  avatar_url: string | null;
  two_factor_enabled: boolean;
  last_login: string | null;
}

interface LoginData {
  user: User;
  token: string;
  expires_at: string;
}

interface LoginResponse extends ApiResponse<LoginData> {}
```

### Пример обработки

```typescript
async function login(email: string, password: string): Promise<LoginResponse> {
  const response = await fetch('/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email, password }),
  });

  const result: LoginResponse = await response.json();

  if (result.status === 'success') {
    // Сохраняем токен
    localStorage.setItem('token', result.data!.token);
    return result;
  } else {
    // Обрабатываем ошибку
    throw new Error(result.message);
  }
}
```

## 🔧 Коды ошибок

| Код | HTTP Status | Описание |
|-----|-------------|----------|
| 0 | 200 | Успешный ответ |
| 400 | 400 | Ошибка валидации данных |
| 401 | 401 | Неавторизованный доступ |
| 404 | 404 | Ресурс не найден |
| 500 | 500 | Внутренняя ошибка сервера |

## 📞 Эндпоинты

### Аутентификация
- `POST /auth/login` - Вход в систему
- `POST /api/v1/auth/login` - Вход в систему (альтернативный путь)

### Системные
- `GET /api/v1/health` - Проверка состояния API
- `GET /api/v1/version` - Информация о версии
- `GET /api/v1/database/tables` - Список таблиц БД
- `GET /api` - Информация об API

### Учетные данные для тестирования
- **Email:** `admin@medicalcontractor.ca`
- **Password:** `password123`
