# 🚀 Деплой на продакшен: fwapi.medicalcontractor.ca

## 📦 Подготовленные файлы

- **Архив проекта:** `fieldwire-api-production.tar.gz` (846KB)
- **Продакшен конфигурация:** `env.production`

## 🌐 Информация о сервере

- **Домен:** `fwapi.medicalcontractor.ca`
- **База данных:** Локальная MySQL на том же сервере
- **Веб-сервер:** Apache/Nginx (уточнить у администратора)

## 📋 Пошаговый деплой

### 1. Загрузка файлов на сервер

#### Вариант A: FTP/SFTP
```bash
# Подключитесь к серверу через FTP/SFTP
# Загрузите файл: fieldwire-api-production.tar.gz
# Распакуйте в корневую директорию сайта
tar -xzf fieldwire-api-production.tar.gz
```

#### Вариант B: Git (если доступен)
```bash
# На сервере
git clone <repository-url>
cd fieldwire-api
composer install --no-dev --optimize-autoloader
```

### 2. Настройка конфигурации

```bash
# Скопируйте продакшен конфигурацию
cp env.production .env

# Проверьте настройки в .env
nano .env
```

**Проверьте следующие настройки:**
```env
APP_URL=https://fwapi.medicalcontractor.ca
DB_HOST=localhost
DB_NAME=yjyhtqh8_easyrx
DB_USERNAME=yjyhtqh8_fieldwire
DB_PASSWORD=FieldWire2025
CORS_ALLOWED_ORIGINS=https://medicalcontractor.ca,https://www.medicalcontractor.ca,https://fwapi.medicalcontractor.ca
```

### 3. Настройка базы данных

```bash
# Проверьте подключение к MySQL
mysql -u yjyhtqh8_fieldwire -p yjyhtqh8_easyrx -e "SELECT 1;"

# Создайте таблицы (если нужно)
composer db:setup
```

### 4. Настройка веб-сервера

#### Apache (.htaccess уже включен)
Убедитесь, что mod_rewrite включен:
```bash
sudo a2enmod rewrite
sudo systemctl reload apache2
```

#### Nginx
Создайте конфигурацию:
```nginx
server {
    listen 80;
    server_name fwapi.medicalcontractor.ca;
    root /path/to/fieldwire-api/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Настройка SSL (HTTPS)

```bash
# Установите Let's Encrypt SSL
sudo certbot --nginx -d fwapi.medicalcontractor.ca
# или
sudo certbot --apache -d fwapi.medicalcontractor.ca
```

### 6. Права доступа

```bash
# Установите правильные права
chmod 644 .env
chmod 755 logs
chmod 755 public/uploads
chmod 755 scripts/*.sh

# Убедитесь, что веб-сервер может читать файлы
chown -R www-data:www-data .  # для Apache
# или
chown -R nginx:nginx .        # для Nginx
```

## 🧪 Тестирование после деплоя

### 1. Проверка эндпоинтов
```bash
# Проверка здоровья API
curl https://fwapi.medicalcontractor.ca/api/v1/health

# Информация о версии
curl https://fwapi.medicalcontractor.ca/api/v1/version

# Список таблиц БД
curl https://fwapi.medicalcontractor.ca/api/v1/database/tables

# Документация API
curl https://fwapi.medicalcontractor.ca/api
```

### 2. Проверка Swagger UI
Откройте в браузере: `https://fwapi.medicalcontractor.ca/api/docs`

### 3. Проверка CORS
```javascript
// В консоли браузера
fetch('https://fwapi.medicalcontractor.ca/api/v1/health')
  .then(response => response.json())
  .then(data => console.log(data))
  .catch(error => console.error('CORS Error:', error));
```

## 📊 Мониторинг

### Логи
```bash
# Логи приложения
tail -f logs/app.log

# Логи веб-сервера
tail -f /var/log/nginx/fwapi.medicalcontractor.ca.error.log
# или
tail -f /var/log/apache2/fwapi.medicalcontractor.ca.error.log
```

### База данных
```bash
# Проверка подключения
mysql -u yjyhtqh8_fieldwire -p yjyhtqh8_easyrx -e "SELECT COUNT(*) FROM users;"
```

## 🆘 Устранение неполадок

### Ошибка 500
```bash
# Проверьте логи
tail -f logs/app.log
tail -f /var/log/nginx/fwapi.medicalcontractor.ca.error.log
```

### CORS ошибки
- Проверьте настройки CORS в .env
- Убедитесь, что домен фронтенда добавлен в CORS_ALLOWED_ORIGINS

### Ошибки базы данных
```bash
# Проверьте подключение
mysql -u yjyhtqh8_fieldwire -p yjyhtqh8_easyrx -e "SELECT 1;"
```

## 📞 Контакты

При возникновении проблем:
1. Проверьте логи приложения и веб-сервера
2. Убедитесь, что все зависимости установлены
3. Проверьте права доступа к файлам
4. Убедитесь, что база данных доступна
