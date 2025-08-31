# FieldWire API - Спецификация Endpoints

## Базовый URL
```
http://localhost:8000/api/v1
```

## Аутентификация
Все защищенные endpoints требуют JWT токен в заголовке `Authorization`:
```
Authorization: Bearer <jwt_token>
```

## Общий формат ответов

### Успешный ответ
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Описание операции",
  "data": { ... }
}
```

### Ошибка
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Описание ошибки",
  "data": null
}
```

## Endpoints

### 1. Системные Endpoints

#### 1.1 Проверка здоровья API
- **URL:** `GET /health`
- **Описание:** Проверка состояния API и базы данных
- **Аутентификация:** Не требуется

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "API is healthy",
  "data": {
    "health_status": "healthy",
    "timestamp": "2025-08-31T16:59:52+02:00",
    "uptime": {
      "seconds": 0,
      "formatted": "00:00:00"
    },
    "memory_usage": {
      "current": 6291456,
      "peak": 6291456,
      "limit": "512M"
    },
    "version": "1.0.0",
    "database": {
      "status": "connected"
    }
  }
}
```

#### 1.2 Информация о версии
- **URL:** `GET /version`
- **Описание:** Получение информации о версии API
- **Аутентификация:** Не требуется

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Version information retrieved",
  "data": {
    "version": "1.0.0",
    "build_date": "2025-08-31",
    "environment": "development"
  }
}
```

### 2. Аутентификация

#### 2.1 Вход в систему
- **URL:** `POST /auth/login`
- **Описание:** Аутентификация пользователя
- **Аутентификация:** Не требуется

**Параметры:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Ответ (успешный):**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "name": "John Doe",
      "phone": "+1234567890",
      "user_type": "System Administrator",
      "job_title": "Senior Developer",
      "status": "active",
      "additional_info": null,
      "avatar_url": null,
      "two_factor_enabled": false,
      "last_login": "2025-08-31 10:53:19"
    },
    "requires_2fa": false,
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_at": "2025-08-31T18:01:25+02:00"
  }
}
```

**Ответ (требуется 2FA):**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Login successful, 2FA required",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "name": "John Doe",
      "phone": "+1234567890",
      "user_type": "System Administrator",
      "job_title": "Senior Developer",
      "status": "active",
      "additional_info": null,
      "avatar_url": null,
      "two_factor_enabled": true
    },
    "requires_2fa": true
  }
}
```

### 3. Управление профилем

#### 3.1 Получение профиля
- **URL:** `GET /profile`
- **Описание:** Получение профиля текущего пользователя
- **Аутентификация:** Требуется

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Profile retrieved successfully",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "name": "John Doe",
      "phone": "+1234567890",
      "user_type": "System Administrator",
      "job_title": "Senior Developer",
      "status": "active",
      "additional_info": null,
      "avatar_url": null,
      "two_factor_enabled": false,
      "last_login": "2025-08-31 10:53:19",
      "created_at": "2025-08-29 17:17:51",
      "updated_at": "2025-08-31 11:02:12"
    }
  }
}
```

#### 3.2 Обновление профиля
- **URL:** `PUT /profile`
- **Описание:** Обновление данных профиля
- **Аутентификация:** Требуется

**Параметры:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "phone": "+1234567890",
  "job_title": "Senior Developer",
  "additional_info": "Дополнительная информация"
}
```

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Profile updated successfully",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "name": "John Doe",
      "phone": "+1234567890",
      "user_type": "System Administrator",
      "job_title": "Senior Developer",
      "status": "active",
      "additional_info": "Дополнительная информация",
      "avatar_url": null,
      "two_factor_enabled": false,
      "updated_at": "2025-08-31 11:02:12"
    }
  }
}
```

#### 3.3 Загрузка аватара
- **URL:** `POST /profile/avatar`
- **Описание:** Загрузка аватара пользователя
- **Аутентификация:** Требуется
- **Content-Type:** `multipart/form-data`

**Параметры:**
- `avatar` - файл изображения (JPEG, PNG, GIF, до 5MB)

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Avatar uploaded successfully",
  "data": {
    "avatar_url": "/uploads/avatars/user_1_1756652412.jpg"
  }
}
```

#### 3.4 Получение аватара
- **URL:** `GET /profile/avatar?user_id={id}`
- **Описание:** Получение URL аватара пользователя
- **Аутентификация:** Требуется

**Параметры:**
- `user_id` - ID пользователя

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Avatar retrieved successfully",
  "data": {
    "avatar_url": "/uploads/avatars/user_1_1756652412.jpg",
    "full_url": "http://localhost:8000/api/v1/avatar?file=user_1_1756652412.jpg"
  }
}
```

#### 3.5 Отображение аватара
- **URL:** `GET /api/v1/avatar?file={filename}`
- **Описание:** Прямое отображение файла аватара
- **Аутентификация:** Не требуется

**Параметры:**
- `file` - имя файла аватара

**Ответ:** Изображение с соответствующим Content-Type

### 4. Двухфакторная аутентификация (2FA)

#### 4.1 Включение 2FA
- **URL:** `POST /profile/2fa/enable`
- **Описание:** Включение двухфакторной аутентификации
- **Аутентификация:** Требуется

**Параметры:**
```json
{
  "delivery_method": "sms"
}
```

**Возможные значения `delivery_method`:**
- `sms` - отправка кода через SMS
- `email` - отправка кода через email

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "2FA verification code sent",
  "data": {
    "delivery_method": "sms",
    "expires_at": "2025-08-31 18:10:00"
  }
}
```

#### 4.2 Отключение 2FA
- **URL:** `POST /profile/2fa/disable`
- **Описание:** Отключение двухфакторной аутентификации
- **Аутентификация:** Требуется

**Параметры:**
```json
{
  "verification_code": "123456"
}
```

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "2FA disabled successfully",
  "data": null
}
```

#### 4.3 Отправка кода 2FA
- **URL:** `POST /2fa/send-code`
- **Описание:** Отправка кода верификации для 2FA
- **Аутентификация:** Не требуется

**Параметры:**
```json
{
  "email": "user@example.com",
  "delivery_method": "sms"
}
```

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Verification code sent",
  "data": {
    "delivery_method": "sms",
    "expires_at": "2025-08-31 18:10:00"
  }
}
```

#### 4.4 Проверка кода 2FA
- **URL:** `POST /2fa/verify-code`
- **Описание:** Проверка кода верификации 2FA
- **Аутентификация:** Не требуется

**Параметры:**
```json
{
  "email": "user@example.com",
  "verification_code": "123456"
}
```

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Verification successful",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "name": "John Doe",
      "phone": "+1234567890",
      "user_type": "System Administrator",
      "job_title": "Senior Developer",
      "status": "active",
      "additional_info": null,
      "avatar_url": null,
      "two_factor_enabled": true
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_at": "2025-08-31T18:01:25+02:00"
  }
}
```

## Коды ошибок

| Код | Описание |
|-----|----------|
| 0 | Успешная операция |
| 400 | Неверные параметры запроса |
| 401 | Не авторизован |
| 404 | Ресурс не найден |
| 500 | Внутренняя ошибка сервера |

## Примеры использования

### JavaScript (Fetch API)

#### Вход в систему
```javascript
const login = async (email, password) => {
  const response = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email, password })
  });
  
  const data = await response.json();
  
  if (data.error_code === 0) {
    // Сохраняем токен
    localStorage.setItem('token', data.data.token);
    return data.data;
  } else {
    throw new Error(data.message);
  }
};
```

#### Получение профиля
```javascript
const getProfile = async () => {
  const token = localStorage.getItem('token');
  
  const response = await fetch('/api/v1/profile', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  const data = await response.json();
  
  if (data.error_code === 0) {
    return data.data.user;
  } else {
    throw new Error(data.message);
  }
};
```

#### Обновление профиля
```javascript
const updateProfile = async (profileData) => {
  const token = localStorage.getItem('token');
  
  const response = await fetch('/api/v1/profile', {
    method: 'PUT',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(profileData)
  });
  
  const data = await response.json();
  
  if (data.error_code === 0) {
    return data.data.user;
  } else {
    throw new Error(data.message);
  }
};
```

#### Загрузка аватара
```javascript
const uploadAvatar = async (file) => {
  const token = localStorage.getItem('token');
  
  const formData = new FormData();
  formData.append('avatar', file);
  
  const response = await fetch('/api/v1/profile/avatar', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`
    },
    body: formData
  });
  
  const data = await response.json();
  
  if (data.error_code === 0) {
    return data.data.avatar_url;
  } else {
    throw new Error(data.message);
  }
};
```

#### Получение URL аватара
```javascript
const getAvatarUrl = async (userId) => {
  const token = localStorage.getItem('token');
  
  const response = await fetch(`/api/v1/profile/avatar?user_id=${userId}`, {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  const data = await response.json();
  
  if (data.error_code === 0) {
    return data.data.full_url;
  } else {
    throw new Error(data.message);
  }
};
```

#### Отображение аватара в HTML
```html
<!-- Используйте full_url для отображения аватара -->
<img src="http://localhost:8000/api/v1/avatar?file=user_1_1756652412.jpg" 
     alt="User Avatar" 
     class="w-10 h-10 rounded-full object-cover">

<!-- Или используйте полученный URL из API -->
<img :src="userAvatarUrl" 
     alt="User Avatar" 
     class="w-10 h-10 rounded-full object-cover"
     @error="handleAvatarError">
```

#### Полный пример работы с аватарами
```javascript
// 1. Получить URL аватара пользователя
const getAvatarUrl = async (userId) => {
    const token = localStorage.getItem('token');
    
    const response = await fetch(`/api/v1/profile/avatar?user_id=${userId}`, {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    const data = await response.json();
    
    if (data.error_code === 0) {
        return data.data.full_url; // Используйте full_url для отображения
    } else {
        throw new Error(data.message);
    }
};

// 2. Отобразить аватар
const displayAvatar = async (userId) => {
    try {
        const avatarUrl = await getAvatarUrl(userId);
        document.getElementById('avatar-img').src = avatarUrl;
    } catch (error) {
        console.error('Failed to load avatar:', error);
        // Покажите дефолтный аватар
        document.getElementById('avatar-img').src = '/default-avatar.png';
    }
};
```

### Vue.js с Composition API

```javascript
import { ref, reactive } from 'vue'

export function useProfile() {
  const profile = reactive({
    id: null,
    email: '',
    first_name: '',
    last_name: '',
    phone: '',
    job_title: '',
    avatar_url: null,
    two_factor_enabled: false
  })
  
  const loading = ref(false)
  const error = ref(null)
  
  const fetchProfile = async () => {
    loading.value = true
    error.value = null
    
    try {
      const token = localStorage.getItem('token')
      const response = await fetch('/api/v1/profile', {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      })
      
      const data = await response.json()
      
      if (data.error_code === 0) {
        Object.assign(profile, data.data.user)
      } else {
        error.value = data.message
      }
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }
  
  const updateProfile = async (updates) => {
    loading.value = true
    error.value = null
    
    try {
      const token = localStorage.getItem('token')
      const response = await fetch('/api/v1/profile', {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(updates)
      })
      
      const data = await response.json()
      
      if (data.error_code === 0) {
        Object.assign(profile, data.data.user)
        return data.data.user
      } else {
        error.value = data.message
      }
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }
  
  return {
    profile,
    loading,
    error,
    fetchProfile,
    updateProfile
  }
}
```

## Примечания

1. **JWT токены** имеют срок действия 1 час
2. **Коды 2FA** действительны 10 минут
3. **Аватары** поддерживают форматы JPEG, PNG, GIF до 5MB
4. **Все даты** возвращаются в формате ISO 8601
5. **Телефонные номера** должны быть в международном формате (+1234567890)

## Тестирование

Для тестирования API можно использовать:
- **Postman** или **Insomnia** для REST API
- **curl** для командной строки
- **Swagger UI** по адресу `/api/docs` (если доступен)
