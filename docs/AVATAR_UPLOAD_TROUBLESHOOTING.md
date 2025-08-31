# Руководство по устранению неполадок: Загрузка аватара

## Проблема: Ошибка 401 Unauthorized при загрузке аватара

### Возможные причины:

1. **Токен не передается в заголовке**
2. **Токен истек**
3. **Неправильный формат заголовка Authorization**
4. **CORS проблемы**

### Решения:

#### 1. Проверка токена

Убедитесь, что токен действителен:

```javascript
// Проверьте, что токен существует и не истек
const token = localStorage.getItem('token');
if (!token) {
    console.error('Token not found');
    return;
}

// Проверьте токен на сервере
const response = await fetch('/api/v1/profile', {
    headers: {
        'Authorization': `Bearer ${token}`
    }
});

if (!response.ok) {
    console.error('Token is invalid or expired');
    // Перенаправьте на страницу логина
    return;
}
```

#### 2. Правильная передача токена

Убедитесь, что токен передается в правильном формате:

```javascript
const uploadAvatar = async (file) => {
    const token = localStorage.getItem('token');
    
    if (!token) {
        throw new Error('No token found');
    }
    
    const formData = new FormData();
    formData.append('avatar', file);
    
    const response = await fetch('/api/v1/profile/avatar', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}` // Важно: пробел после "Bearer"
        },
        body: formData
    });
    
    if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Upload failed');
    }
    
    return await response.json();
};
```

#### 3. Обновление токена

Если токен истек, получите новый:

```javascript
const refreshToken = async () => {
    try {
        const response = await fetch('/api/v1/auth/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                email: 'user@example.com',
                password: 'password123'
            })
        });
        
        const data = await response.json();
        
        if (data.error_code === 0) {
            localStorage.setItem('token', data.data.token);
            return data.data.token;
        }
    } catch (error) {
        console.error('Failed to refresh token:', error);
        // Перенаправьте на страницу логина
    }
};
```

#### 4. Обработка ошибок

Добавьте правильную обработку ошибок:

```javascript
const uploadAvatar = async (file) => {
    try {
        const token = localStorage.getItem('token');
        
        if (!token) {
            throw new Error('No authentication token');
        }
        
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
        
        if (response.ok && data.error_code === 0) {
            return data.data.avatar_url;
        } else {
            if (response.status === 401) {
                // Токен истек или недействителен
                throw new Error('Authentication failed. Please login again.');
            } else {
                throw new Error(data.message || 'Upload failed');
            }
        }
    } catch (error) {
        console.error('Avatar upload error:', error);
        throw error;
    }
};
```

#### 5. Валидация файла

Убедитесь, что файл соответствует требованиям:

```javascript
const validateFile = (file) => {
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    const maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!allowedTypes.includes(file.type)) {
        throw new Error('Invalid file type. Please select an image file.');
    }
    
    if (file.size > maxSize) {
        throw new Error('File too large. Maximum size is 5MB.');
    }
    
    return true;
};

// Использование
const handleFileUpload = async (file) => {
    try {
        validateFile(file);
        const avatarUrl = await uploadAvatar(file);
        console.log('Avatar uploaded:', avatarUrl);
    } catch (error) {
        console.error('Upload failed:', error.message);
    }
};
```

#### 6. Vue.js пример

```vue
<template>
  <div>
    <input 
      type="file" 
      @change="handleFileSelect" 
      accept="image/*"
      ref="fileInput"
    >
    <button @click="uploadAvatar" :disabled="!selectedFile || uploading">
      {{ uploading ? 'Uploading...' : 'Upload Avatar' }}
    </button>
    <div v-if="error" class="error">{{ error }}</div>
    <div v-if="success" class="success">{{ success }}</div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const selectedFile = ref(null)
const uploading = ref(false)
const error = ref('')
const success = ref('')

const handleFileSelect = (event) => {
  const file = event.target.files[0]
  if (file) {
    // Валидация файла
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif']
    const maxSize = 5 * 1024 * 1024
    
    if (!allowedTypes.includes(file.type)) {
      error.value = 'Invalid file type. Please select an image file.'
      return
    }
    
    if (file.size > maxSize) {
      error.value = 'File too large. Maximum size is 5MB.'
      return
    }
    
    selectedFile.value = file
    error.value = ''
  }
}

const uploadAvatar = async () => {
  if (!selectedFile.value) return
  
  uploading.value = true
  error.value = ''
  success.value = ''
  
  try {
    const token = localStorage.getItem('token')
    
    if (!token) {
      throw new Error('No authentication token')
    }
    
    const formData = new FormData()
    formData.append('avatar', selectedFile.value)
    
    const response = await fetch('/api/v1/profile/avatar', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`
      },
      body: formData
    })
    
    const data = await response.json()
    
    if (response.ok && data.error_code === 0) {
      success.value = 'Avatar uploaded successfully!'
      // Обновите профиль пользователя
      emit('avatar-uploaded', data.data.avatar_url)
    } else {
      if (response.status === 401) {
        throw new Error('Authentication failed. Please login again.')
      } else {
        throw new Error(data.message || 'Upload failed')
      }
    }
  } catch (err) {
    error.value = err.message
  } finally {
    uploading.value = false
  }
}
</script>
```

#### 7. Axios пример

```javascript
import axios from 'axios'

const uploadAvatar = async (file) => {
  const token = localStorage.getItem('token')
  
  if (!token) {
    throw new Error('No authentication token')
  }
  
  const formData = new FormData()
  formData.append('avatar', file)
  
  try {
    const response = await axios.post('/api/v1/profile/avatar', formData, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'multipart/form-data'
      }
    })
    
    return response.data.data.avatar_url
  } catch (error) {
    if (error.response?.status === 401) {
      throw new Error('Authentication failed. Please login again.')
    }
    throw new Error(error.response?.data?.message || 'Upload failed')
  }
}
```

### Тестирование

Используйте предоставленный HTML файл `test_avatar_upload.html` для тестирования:

1. Откройте файл в браузере
2. Вставьте JWT токен
3. Нажмите "Test Token" для проверки токена
4. Выберите файл изображения
5. Нажмите "Upload Avatar"

### Логи сервера

Если проблема не решается, проверьте логи сервера:

```bash
tail -f logs/app.log
```

Логи покажут:
- Успешную аутентификацию
- Детали загруженного файла
- Ошибки валидации
- Результат сохранения файла

### Контакты

Если проблема не решается, обратитесь к бэкенд-разработчику с:
1. Описанием ошибки
2. Логами сервера
3. Примером кода, который вызывает ошибку
