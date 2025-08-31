# Руководство по использованию аватаров

## Проблема: Неправильные URL для аватаров

### ❌ Неправильное использование:

```html
<!-- НЕПРАВИЛЬНО - этот URL требует авторизации и параметры -->
<img src="http://localhost:8000/api/v1/profile/avatar" alt="Avatar">
```

### ✅ Правильное использование:

```html
<!-- ПРАВИЛЬНО - используйте endpoint для отображения изображений -->
<img src="http://localhost:8000/api/v1/avatar?file=user_1_1756655965.png" alt="Avatar">
```

## Полный процесс работы с аватарами

### 1. Получение URL аватара

Сначала получите URL аватара пользователя:

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
        return data.data.full_url; // Используйте full_url для отображения
    } else {
        throw new Error(data.message);
    }
};
```

### 2. Отображение аватара

Используйте полученный URL для отображения:

```javascript
const displayAvatar = async (userId) => {
    try {
        const avatarUrl = await getAvatarUrl(userId);
        
        // Обновите src изображения
        const imgElement = document.querySelector('#avatar-img');
        imgElement.src = avatarUrl;
        
    } catch (error) {
        console.error('Failed to load avatar:', error);
        // Покажите дефолтный аватар
        imgElement.src = '/default-avatar.png';
    }
};
```

## Vue.js пример

### Компонент для отображения аватара:

```vue
<template>
  <div class="avatar-container">
    <img 
      :src="avatarUrl" 
      :alt="userName"
      class="avatar-image"
      @error="handleImageError"
      @load="handleImageLoad"
    >
    <div v-if="loading" class="avatar-loading">Loading...</div>
    <div v-if="error" class="avatar-error">Failed to load</div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const props = defineProps({
  userId: {
    type: Number,
    required: true
  },
  userName: {
    type: String,
    default: 'User'
  }
})

const avatarUrl = ref('')
const loading = ref(false)
const error = ref(false)

const getAvatarUrl = async () => {
  loading.value = true
  error.value = false
  
  try {
    const token = localStorage.getItem('token')
    
    if (!token) {
      throw new Error('No authentication token')
    }
    
    const response = await fetch(`/api/v1/profile/avatar?user_id=${props.userId}`, {
      headers: {
        'Authorization': `Bearer ${token}`
      }
    })
    
    const data = await response.json()
    
    if (data.error_code === 0) {
      avatarUrl.value = data.data.full_url
    } else {
      throw new Error(data.message)
    }
  } catch (err) {
    console.error('Failed to get avatar URL:', err)
    error.value = true
    // Используйте дефолтный аватар
    avatarUrl.value = '/default-avatar.png'
  } finally {
    loading.value = false
  }
}

const handleImageError = () => {
  console.error('Avatar image failed to load')
  // Покажите дефолтный аватар
  avatarUrl.value = '/default-avatar.png'
}

const handleImageLoad = () => {
  console.log('Avatar loaded successfully')
}

onMounted(() => {
  getAvatarUrl()
})
</script>

<style scoped>
.avatar-container {
  position: relative;
  display: inline-block;
}

.avatar-image {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-loading {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(0, 0, 0, 0.7);
  color: white;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.avatar-error {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(255, 0, 0, 0.7);
  color: white;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
```

### Использование компонента:

```vue
<template>
  <div>
    <AvatarDisplay 
      :user-id="currentUser.id" 
      :user-name="currentUser.name"
    />
  </div>
</template>
```

## React пример

```jsx
import React, { useState, useEffect } from 'react'

const AvatarDisplay = ({ userId, userName }) => {
  const [avatarUrl, setAvatarUrl] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(false)

  const getAvatarUrl = async () => {
    setLoading(true)
    setError(false)
    
    try {
      const token = localStorage.getItem('token')
      
      if (!token) {
        throw new Error('No authentication token')
      }
      
      const response = await fetch(`/api/v1/profile/avatar?user_id=${userId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      })
      
      const data = await response.json()
      
      if (data.error_code === 0) {
        setAvatarUrl(data.data.full_url)
      } else {
        throw new Error(data.message)
      }
    } catch (err) {
      console.error('Failed to get avatar URL:', err)
      setError(true)
      setAvatarUrl('/default-avatar.png')
    } finally {
      setLoading(false)
    }
  }

  const handleImageError = () => {
    console.error('Avatar image failed to load')
    setAvatarUrl('/default-avatar.png')
  }

  useEffect(() => {
    getAvatarUrl()
  }, [userId])

  return (
    <div className="avatar-container">
      <img 
        src={avatarUrl} 
        alt={userName}
        className="avatar-image"
        onError={handleImageError}
      />
      {loading && <div className="avatar-loading">Loading...</div>}
      {error && <div className="avatar-error">Failed to load</div>}
    </div>
  )
}

export default AvatarDisplay
```

## Angular пример

```typescript
import { Component, Input, OnInit } from '@angular/core'
import { HttpClient, HttpHeaders } from '@angular/common/http'

@Component({
  selector: 'app-avatar-display',
  template: `
    <div class="avatar-container">
      <img 
        [src]="avatarUrl" 
        [alt]="userName"
        class="avatar-image"
        (error)="handleImageError()"
        (load)="handleImageLoad()"
      >
      <div *ngIf="loading" class="avatar-loading">Loading...</div>
      <div *ngIf="error" class="avatar-error">Failed to load</div>
    </div>
  `,
  styles: [`
    .avatar-container {
      position: relative;
      display: inline-block;
    }
    .avatar-image {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      object-fit: cover;
    }
    .avatar-loading, .avatar-error {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      color: white;
    }
    .avatar-loading {
      background: rgba(0, 0, 0, 0.7);
    }
    .avatar-error {
      background: rgba(255, 0, 0, 0.7);
    }
  `]
})
export class AvatarDisplayComponent implements OnInit {
  @Input() userId!: number
  @Input() userName: string = 'User'

  avatarUrl: string = ''
  loading: boolean = false
  error: boolean = false

  constructor(private http: HttpClient) {}

  ngOnInit() {
    this.getAvatarUrl()
  }

  async getAvatarUrl() {
    this.loading = true
    this.error = false
    
    try {
      const token = localStorage.getItem('token')
      
      if (!token) {
        throw new Error('No authentication token')
      }
      
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${token}`
      })
      
      const response: any = await this.http.get(`/api/v1/profile/avatar?user_id=${this.userId}`, { headers }).toPromise()
      
      if (response.error_code === 0) {
        this.avatarUrl = response.data.full_url
      } else {
        throw new Error(response.message)
      }
    } catch (err) {
      console.error('Failed to get avatar URL:', err)
      this.error = true
      this.avatarUrl = '/default-avatar.png'
    } finally {
      this.loading = false
    }
  }

  handleImageError() {
    console.error('Avatar image failed to load')
    this.avatarUrl = '/default-avatar.png'
  }

  handleImageLoad() {
    console.log('Avatar loaded successfully')
  }
}
```

## Важные моменты

### 1. Правильные URL:

- **Для получения URL аватара**: `GET /api/v1/profile/avatar?user_id={id}` (требует авторизации)
- **Для отображения аватара**: `GET /api/v1/avatar?file={filename}` (не требует авторизации)

### 2. Обработка ошибок:

```javascript
// Всегда обрабатывайте ошибки загрузки изображения
img.onerror = () => {
  img.src = '/default-avatar.png'
}
```

### 3. Кэширование:

```javascript
// Добавьте параметр времени для избежания кэширования
const avatarUrl = `${baseUrl}?file=${filename}&t=${Date.now()}`
```

### 4. Дефолтный аватар:

Всегда предоставляйте дефолтный аватар на случай, если загрузка не удалась:

```javascript
const defaultAvatar = '/default-avatar.png'
const finalAvatarUrl = avatarUrl || defaultAvatar
```

## Тестирование

Для тестирования используйте:

```bash
# Получить URL аватара
curl -X GET "http://localhost:8000/api/v1/profile/avatar?user_id=1" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Проверить отображение аватара
curl -I "http://localhost:8000/api/v1/avatar?file=user_1_1756655965.png"
```
