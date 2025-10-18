# 🔄 Включение Refresh Tokens - Инструкция

## ✅ Текущий статус

- **Сайт работает** - пользователи могут логиниться и использовать приложение
- **Refresh tokens отключены** - при истечении токена пользователь разлогинивается
- **Бэкенд готов** - все endpoints работают правильно

## 🔧 Что было исправлено на бэкенде

1. **Cookie domain** - установлен пустой для localhost
2. **CORS** - настроен для credentials
3. **Cookie настройки** - исправлены для localhost

## 🚀 Включение Refresh Tokens на фронтенде

### 1. Включить в session-utils.ts

Раскомментировать и исправить код:

```javascript
// В session-utils.ts
export async function refreshAccessToken(): Promise<string | null> {
  try {
    console.log('🔄 Refreshing access token...');
    
    const response = await axios.post('/api/v1/auth/refresh-token', {}, {
      withCredentials: true // ВАЖНО: отправляет cookies
    });
    
    if (response.data?.data?.token) {
      const newToken = response.data.data.token;
      localStorage.setItem('authToken', newToken);
      console.log('✅ Token refreshed successfully');
      return newToken;
    }
    
    return null;
  } catch (error) {
    console.error('❌ Token refresh failed:', error);
    return null;
  }
}
```

### 2. Включить в session-manager.ts

Раскомментировать код:

```javascript
// В session-manager.ts
if (shouldRefresh) {
  console.log('🔄 Token expires soon - refreshing due to activity');
  const newToken = await refreshAccessToken();
  if (newToken) {
    console.log('✅ Token refreshed successfully');
  } else {
    console.log('❌ Token refresh failed - user will be logged out');
  }
}
```

### 3. Включить в api.ts (Response Interceptor)

Раскомментировать код:

```javascript
// В api.ts
axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;

    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true;

      try {
        const { data } = await axios.post('/api/v1/auth/refresh-token', {}, {
          withCredentials: true // ВАЖНО: отправляет cookies
        });

        const newToken = data.data.token;
        localStorage.setItem('authToken', newToken);
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        return axios(originalRequest);
      } catch (refreshError) {
        localStorage.removeItem('authToken');
        window.location.href = '/login';
        return Promise.reject(refreshError);
      }
    }

    return Promise.reject(error);
  }
);
```

## 🧪 Тестирование

### 1. Очистить браузер
- Очистить localStorage
- Очистить cookies
- Перезагрузить страницу

### 2. Войти в систему
- Должен создаться refresh token cookie
- Проверить в DevTools → Application → Cookies

### 3. Проверить автоматическое обновление
- Подождать 25+ минут (токен истекает через 30 минут)
- Должен автоматически обновиться без разлогина

## 🔍 Отладка

### Проверить cookies в DevTools:
```javascript
// В консоли браузера
console.log('Cookies:', document.cookie);
console.log('LocalStorage token:', localStorage.getItem('authToken'));
```

### Тест refresh token вручную:
```javascript
// В консоли браузера
fetch('http://localhost:8000/api/v1/auth/refresh-token', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json'
  },
  body: '{}'
}).then(r => r.json()).then(console.log);
```

## 📋 Чек-лист

- [ ] Раскомментировать код в `session-utils.ts`
- [ ] Раскомментировать код в `session-manager.ts`  
- [ ] Раскомментировать код в `api.ts`
- [ ] Добавить `withCredentials: true` во все запросы refresh token
- [ ] Очистить браузер (localStorage + cookies)
- [ ] Перелогиниться
- [ ] Проверить cookies в DevTools
- [ ] Протестировать автоматическое обновление

## ⚠️ Важные моменты

1. **`withCredentials: true`** - обязательно для отправки cookies
2. **Очистить браузер** - старые cookies могут мешать
3. **Проверить CORS** - должен быть настроен для credentials
4. **Тестировать постепенно** - сначала один файл, потом остальные

---

**Статус**: ✅ Бэкенд готов, фронтенд нужно включить  
**Приоритет**: 🟡 Средний (сайт работает без refresh tokens)
