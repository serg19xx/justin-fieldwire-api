# 🔄 Frontend Refresh Token Setup

## Проблема

Фронтенд получает ошибку 400 "Refresh token not found" при попытке обновить токен. Это происходит потому, что cookie с refresh token не передается на сервер.

## Решение

### 1. Настройка Axios для отправки cookies

В файле `api.ts` или где настраивается Axios, добавьте:

```javascript
import axios from 'axios';

// Настройка базового URL
const api = axios.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true, // ВАЖНО: это отправляет cookies
  headers: {
    'Content-Type': 'application/json',
  }
});

// Или для конкретных запросов:
axios.post('/api/v1/auth/refresh-token', {}, {
  withCredentials: true // Отправляет cookies
});
```

### 2. Обновление Response Interceptor

```javascript
// Response interceptor для автоматического обновления токена
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;

    // Если 401 и еще не пытались обновить токен
    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true;

      try {
        // Вызываем refresh token endpoint
        const { data } = await api.post('/api/v1/auth/refresh-token', {}, {
          withCredentials: true // ВАЖНО: отправляет cookies
        });

        // Обновляем access token
        const newToken = data.data.token;
        localStorage.setItem('authToken', newToken);

        // Повторяем оригинальный запрос с новым токеном
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        return api(originalRequest);
      } catch (refreshError) {
        // Refresh не удался - выходим
        localStorage.removeItem('authToken');
        window.location.href = '/login';
        return Promise.reject(refreshError);
      }
    }

    return Promise.reject(error);
  }
);
```

### 3. Проверка Cookie Domain

Убедитесь, что в `.env` файле бэкенда установлен правильный `COOKIE_DOMAIN`:

```bash
# Для localhost разработки
COOKIE_DOMAIN=localhost

# Для production
COOKIE_DOMAIN=.yourdomain.com
```

### 4. Тестирование

После внесения изменений:

1. **Очистите localStorage и cookies** в браузере
2. **Войдите в систему** - должен создаться refresh token cookie
3. **Проверьте в DevTools** → Application → Cookies → `http://localhost:8000`
4. **Должен быть cookie** `refresh_token` с длинным значением

### 5. Отладка

Если все еще не работает, проверьте в DevTools:

```javascript
// В консоли браузера
console.log('Cookies:', document.cookie);
console.log('LocalStorage token:', localStorage.getItem('authToken'));

// Проверьте, отправляются ли cookies
fetch('http://localhost:8000/api/v1/auth/refresh-token', {
  method: 'POST',
  credentials: 'include', // ВАЖНО
  headers: {
    'Content-Type': 'application/json'
  },
  body: '{}'
}).then(r => r.json()).then(console.log);
```

## Важные моменты

1. **`withCredentials: true`** - обязательно для отправки cookies
2. **`credentials: 'include'`** - для fetch API
3. **Cookie domain** должен соответствовать домену запроса
4. **CORS** настроен правильно на бэкенде
5. **Refresh token endpoint** НЕ требует Authorization заголовок

## Пример полной настройки

```javascript
// api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.VUE_APP_API_URL || 'http://localhost:8000',
  withCredentials: true, // Отправляет cookies
  timeout: 10000,
});

// Request interceptor - добавляет токен
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('authToken');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor - обновляет токен при 401
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;

    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true;

      try {
        const { data } = await api.post('/api/v1/auth/refresh-token', {});
        localStorage.setItem('authToken', data.data.token);
        originalRequest.headers.Authorization = `Bearer ${data.data.token}`;
        return api(originalRequest);
      } catch (refreshError) {
        localStorage.removeItem('authToken');
        window.location.href = '/login';
        return Promise.reject(refreshError);
      }
    }

    return Promise.reject(error);
  }
);

export default api;
```

---

**Статус**: ✅ Готово к внедрению  
**Приоритет**: 🔴 Высокий (критично для работы refresh tokens)
