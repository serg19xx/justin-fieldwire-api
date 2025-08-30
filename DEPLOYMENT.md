# 🚀 Инструкции по деплою FieldWire API

## 📋 Подготовка к деплою

### 1. Локальная подготовка
```bash
# Запустите скрипт подготовки к деплою
./scripts/deploy.sh
```

Этот скрипт:
- Установит production зависимости
- Создаст необходимые директории
- Проверит код и тесты
- Настроит права доступа

### 2. Подготовка файлов для загрузки
```bash
# Создайте архив проекта (исключая ненужные файлы)
tar --exclude='.git' --exclude='node_modules' --exclude='tests' --exclude='*.log' -czf fieldwire-api.tar.gz .
```

## 🌐 Загрузка на сервер

### Вариант 1: FTP/SFTP
1. Подключитесь к серверу через FTP/SFTP
2. Загрузите файлы в корневую директорию сайта
3. Распакуйте архив: `tar -xzf fieldwire-api.tar.gz`

### Вариант 2: Git (рекомендуется)
```bash
# На сервере
git clone <your-repository-url>
cd fieldwire-api
composer install --no-dev --optimize-autoloader
```

## ⚙️ Настройка сервера

### 1. Настройка переменных окружения

#### Для разработки (локально):
```bash
# Используйте конфигурацию разработки
cp env.development .env
```

**Настройки разработки:**
```env
# Application
APP_NAME="FieldWire API"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database - Remote database on medicalcontractor.ca
DB_HOST=medicalcontractor.ca
DB_PORT=3306
DB_NAME=yjyhtqh8_fieldwire
DB_USERNAME=yjyhtqh8_fieldwire
DB_PASSWORD=your_development_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# CORS - Allow all localhost ports
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080,http://localhost:5173
```

#### Для продакшена (на сервере):
```bash
# Используйте конфигурацию продакшена
cp env.production .env
```

**Настройки продакшена:**
```env
# Application
APP_NAME="FieldWire API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database - Local database on same server
DB_HOST=localhost
DB_PORT=3306
DB_NAME=yjyhtqh8_fieldwire
DB_USERNAME=yjyhtqh8_fieldwire
DB_PASSWORD=your_production_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# CORS - Only production frontend domains
CORS_ALLOWED_ORIGINS=https://your-frontend-domain.com,https://www.your-frontend-domain.com
```

### 2. Настройка базы данных

#### Разработка (удаленная БД):
- База данных уже существует на `medicalcontractor.ca`
- Просто настройте подключение в `.env`

#### Продакшен (локальная БД):
```bash
# Создайте базу данных (если нужно)
mysql -u root -p
CREATE DATABASE yjyhtqh8_fieldwire CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yjyhtqh8_fieldwire'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON yjyhtqh8_fieldwire.* TO 'yjyhtqh8_fieldwire'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Запустите скрипт настройки БД
composer db:setup
```

### 3. Настройка веб-сервера

#### Apache
1. Убедитесь, что mod_rewrite включен
2. Создайте виртуальный хост или используйте .htaccess (уже создан)

#### Nginx
1. Скопируйте `nginx.conf` в `/etc/nginx/sites-available/fieldwire-api`
2. Отредактируйте домен и путь к проекту
3. Создайте символическую ссылку:
```bash
sudo ln -s /etc/nginx/sites-available/fieldwire-api /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 4. Настройка PHP
```bash
# Проверьте версию PHP (должна быть 8.1+)
php -v

# Настройте PHP-FPM (если используете Nginx)
sudo systemctl enable php8.1-fpm
sudo systemctl start php8.1-fpm
```

## 🔒 Настройка безопасности

### 1. Права доступа
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

### 2. SSL сертификат
```bash
# Установите Let's Encrypt SSL
sudo certbot --nginx -d your-domain.com
```

## 🧪 Тестирование после деплоя

### 1. Проверка эндпоинтов
```bash
# Проверка здоровья API
curl https://your-domain.com/api/v1/health

# Информация о версии
curl https://your-domain.com/api/v1/version

# Документация API
curl https://your-domain.com/api
```

### 2. Проверка Swagger UI
Откройте в браузере: `https://your-domain.com/api/docs`

### 3. Проверка CORS
```javascript
// В консоли браузера на фронтенде
fetch('https://your-domain.com/api/v1/health')
  .then(response => response.json())
  .then(data => console.log(data))
  .catch(error => console.error('CORS Error:', error));
```

## 📊 Мониторинг

### 1. Логи
```bash
# Логи приложения
tail -f logs/app.log

# Логи веб-сервера
tail -f /var/log/nginx/fieldwire-api.error.log
```

### 2. База данных
```bash
# Проверка подключения (разработка)
mysql -h medicalcontractor.ca -u yjyhtqh8_fieldwire -p yjyhtqh8_fieldwire -e "SELECT COUNT(*) FROM users;"

# Проверка подключения (продакшен)
mysql -u yjyhtqh8_fieldwire -p yjyhtqh8_fieldwire -e "SELECT COUNT(*) FROM users;"
```

## 🔄 Обновление

### 1. Обновление кода
```bash
# Если используете Git
git pull origin main
composer install --no-dev --optimize-autoloader

# Если используете FTP
# Загрузите новые файлы и перезапустите веб-сервер
```

### 2. Обновление базы данных
```bash
# Если добавили новые таблицы
composer db:setup
```

## 🆘 Устранение неполадок

### Частые проблемы:

1. **Ошибка 500**
   - Проверьте логи: `tail -f logs/app.log`
   - Убедитесь, что .env файл настроен правильно

2. **CORS ошибки**
   - Проверьте настройки CORS в .env
   - Убедитесь, что домен фронтенда добавлен в CORS_ALLOWED_ORIGINS

3. **Ошибки базы данных**
   - **Разработка**: Проверьте подключение к `medicalcontractor.ca`
   - **Продакшен**: Проверьте подключение к `localhost`
   - Запустите: `composer db:setup`

4. **Медленная работа**
   - Проверьте настройки PHP (memory_limit, max_execution_time)
   - Включите кеширование в веб-сервере

## 📞 Поддержка

При возникновении проблем:
1. Проверьте логи приложения и веб-сервера
2. Убедитесь, что все зависимости установлены
3. Проверьте права доступа к файлам
4. Убедитесь, что база данных доступна
5. Проверьте правильность конфигурации для окружения (разработка/продакшен)
