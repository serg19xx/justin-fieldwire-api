# 🚨 СРОЧНО: Исправление проблемы с аватарами

## Проблема
Фронтенд использует неправильный URL для аватара:
```html
<!-- ❌ НЕПРАВИЛЬНО - вызывает ошибку 400 -->
<img src="http://localhost:8000/api/v1/profile/avatar" alt="Avatar">
```

## ✅ Решение

### 1. Найдите в коде все места с неправильным URL:
```bash
# Поиск в проекте
grep -r "api/v1/profile/avatar" src/
grep -r "localhost:8000/api/v1/profile/avatar" src/
```

### 2. Замените на правильный подход:

#### ❌ Удалите:
```html
<img src="http://localhost:8000/api/v1/profile/avatar" alt="Avatar">
```

#### ✅ Добавьте:
```vue
<template>
  <img :src="avatarUrl" alt="Avatar" @error="handleError">
</template>

<script setup>
import { ref, onMounted } from 'vue'

const avatarUrl = ref('')

const getAvatarUrl = async () => {
  const token = localStorage.getItem('token')
  
  const response = await fetch(`/api/v1/profile/avatar?user_id=${userId}`, {
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

### 3. Быстрое исправление (если знаете filename):

```html
<!-- Если знаете filename аватара -->
<img src="http://localhost:8000/api/v1/avatar?file=user_1_1756655965.png" alt="Avatar">
```

## 🔍 Где искать в коде:

1. **Vue компоненты**: `AccountView.vue`, `ProfileView.vue`, `UserAvatar.vue`
2. **JavaScript файлы**: `auth.js`, `api.js`, `user.js`
3. **HTML шаблоны**: любые файлы с `<img>` тегами

## 📋 Чек-лист исправления:

- [ ] Найти все `<img src="...api/v1/profile/avatar">`
- [ ] Заменить на динамическое получение URL
- [ ] Добавить обработку ошибок `@error="handleError"`
- [ ] Добавить дефолтный аватар
- [ ] Протестировать загрузку

## 🎯 Ключевые моменты:

1. **НЕ используйте** `GET /api/v1/profile/avatar` без параметров
2. **Используйте** `GET /api/v1/profile/avatar?user_id={id}` для получения URL
3. **Используйте** `data.data.full_url` из ответа
4. **Отображайте** через `GET /api/v1/avatar?file={filename}`

## 🧪 Тест:
Откройте `test_avatar_frontend.html` в браузере для проверки правильной работы.

