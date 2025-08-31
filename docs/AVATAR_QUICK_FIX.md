# Быстрое исправление: Проблема с загрузкой аватара

## 🚨 Проблема
```
GET http://localhost:8000/api/v1/profile/avatar
Status: 400 Bad Request
```

## ✅ Решение

### ❌ Неправильно:
```html
<img src="http://localhost:8000/api/v1/profile/avatar" alt="Avatar">
```

### ✅ Правильно:
```html
<img src="http://localhost:8000/api/v1/avatar?file=user_1_1756655965.png" alt="Avatar">
```

## 🔧 Исправление в коде

### 1. Получите правильный URL аватара:

```javascript
// Получите URL аватара пользователя
const getAvatarUrl = async (userId) => {
    const token = localStorage.getItem('token');
    
    const response = await fetch(`/api/v1/profile/avatar?user_id=${userId}`, {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    const data = await response.json();
    
    if (data.error_code === 0) {
        return data.data.full_url; // ← Используйте full_url
    }
};

// Использование
const avatarUrl = await getAvatarUrl(userId);
imgElement.src = avatarUrl;
```

### 2. Vue.js компонент:

```vue
<template>
  <img 
    :src="avatarUrl" 
    :alt="userName"
    @error="handleError"
  >
</template>

<script setup>
import { ref, onMounted } from 'vue'

const props = defineProps(['userId', 'userName'])
const avatarUrl = ref('')

const getAvatarUrl = async () => {
  const token = localStorage.getItem('token')
  
  const response = await fetch(`/api/v1/profile/avatar?user_id=${props.userId}`, {
    headers: { 'Authorization': `Bearer ${token}` }
  })
  
  const data = await response.json()
  
  if (data.error_code === 0) {
    avatarUrl.value = data.data.full_url // ← Используйте full_url
  }
}

const handleError = () => {
  avatarUrl.value = '/default-avatar.png'
}

onMounted(() => {
  getAvatarUrl()
})
</script>
```

## 📋 Чек-лист

- [ ] Используйте `GET /api/v1/profile/avatar?user_id={id}` для получения URL
- [ ] Используйте `data.data.full_url` из ответа
- [ ] Не используйте `GET /api/v1/profile/avatar` без параметров
- [ ] Добавьте обработку ошибок `@error="handleError"`
- [ ] Предоставьте дефолтный аватар

## 🎯 Ключевые моменты

1. **URL для получения аватара**: `GET /api/v1/profile/avatar?user_id={id}` (требует авторизации)
2. **URL для отображения**: `GET /api/v1/avatar?file={filename}` (не требует авторизации)
3. **Используйте `full_url`** из ответа API
4. **Всегда обрабатывайте ошибки** загрузки изображения
