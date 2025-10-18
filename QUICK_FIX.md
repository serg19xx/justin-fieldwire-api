# 🚀 БЫСТРОЕ ИСПРАВЛЕНИЕ - Отключить Refresh Tokens

## Проблема
CORS не работает с refresh tokens, сайт не работает.

## Решение
Отключить refresh tokens на фронтенде и оставить только базовую аутентификацию.

## Что нужно сделать на фронтенде:

### 1. В session-utils.ts - ЗАКОММЕНТИРОВАТЬ:
```javascript
// export async function refreshAccessToken(): Promise<string | null> {
//   try {
//     console.log('🔄 Refreshing access token...');
//     
//     const response = await axios.post('/api/v1/auth/refresh-token', {}, {
//       withCredentials: true
//     });
//     
//     if (response.data?.data?.token) {
//       const newToken = response.data.data.token;
//       localStorage.setItem('authToken', newToken);
//       console.log('✅ Token refreshed successfully');
//       return newToken;
//     }
//     
//     return null;
//   } catch (error) {
//     console.error('❌ Token refresh failed:', error);
//     return null;
//   }
// }
```

### 2. В session-manager.ts - ЗАКОММЕНТИРОВАТЬ:
```javascript
// if (shouldRefresh) {
//   console.log('🔄 Token expires soon - refreshing due to activity');
//   const newToken = await refreshAccessToken();
//   if (newToken) {
//     console.log('✅ Token refreshed successfully');
//   } else {
//     console.log('❌ Token refresh failed - user will be logged out');
//   }
// }
```

### 3. В api.ts - ЗАКОММЕНТИРОВАТЬ response interceptor:
```javascript
// axios.interceptors.response.use(
//   (response) => response,
//   async (error) => {
//     const originalRequest = error.config;
//     if (error.response?.status === 401 && !originalRequest._retry) {
//       // ... refresh logic
//     }
//     return Promise.reject(error);
//   }
// );
```

## Результат:
- ✅ Сайт работает
- ✅ Логин/логаут работает  
- ✅ Сессии работают
- ❌ Refresh tokens отключены (пользователь разлогинивается при истечении токена)

## Время: 2 минуты
