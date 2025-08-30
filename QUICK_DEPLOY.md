# ⚡ Быстрый деплой на fwapi.medicalcontractor.ca

## 📦 Файлы для загрузки

1. **`fieldwire-api-production.tar.gz`** - архив проекта (846KB)
2. **`env.production`** - конфигурация продакшена

## 🚀 Быстрые команды для деплоя

### 1. Загрузка и распаковка
```bash
# Загрузите fieldwire-api-production.tar.gz на сервер
# Распакуйте в корневую директорию сайта
tar -xzf fieldwire-api-production.tar.gz
```

### 2. Настройка конфигурации
```bash
# Скопируйте продакшен конфигурацию
cp env.production .env

# Установите зависимости
composer install --no-dev --optimize-autoloader
```

### 3. Настройка базы данных
```bash
# Создайте таблицы
composer db:setup
```

### 4. Права доступа
```bash
# Создайте директории и установите права
mkdir -p logs public/uploads
chmod 644 .env
chmod 755 logs public/uploads scripts/*.sh
chown -R www-data:www-data .  # для Apache
```

### 5. Настройка веб-сервера

#### Apache
```bash
# Убедитесь, что mod_rewrite включен
sudo a2enmod rewrite
sudo systemctl reload apache2
```

#### Nginx
Создайте конфигурацию в `/etc/nginx/sites-available/fwapi.medicalcontractor.ca`:
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

### 6. SSL сертификат
```bash
sudo certbot --nginx -d fwapi.medicalcontractor.ca
```

## 🧪 Быстрое тестирование

```bash
# Проверка здоровья API
curl https://fwapi.medicalcontractor.ca/api/v1/health

# Список таблиц БД
curl https://fwapi.medicalcontractor.ca/api/v1/database/tables

# Swagger UI
curl https://fwapi.medicalcontractor.ca/api/docs
```

## 📋 Конфигурация в .env

```env
APP_URL=https://fwapi.medicalcontractor.ca
DB_HOST=localhost
DB_NAME=yjyhtqh8_easyrx
DB_USERNAME=yjyhtqh8_fieldwire
DB_PASSWORD=FieldWire2025
CORS_ALLOWED_ORIGINS=https://medicalcontractor.ca,https://www.medicalcontractor.ca,https://fwapi.medicalcontractor.ca
```

## 🆘 Если что-то не работает

1. **Проверьте логи:** `tail -f logs/app.log`
2. **Проверьте права:** `ls -la .env logs/`
3. **Проверьте БД:** `mysql -u yjyhtqh8_fieldwire -p yjyhtqh8_easyrx -e "SELECT 1;"`
4. **Проверьте веб-сервер:** `sudo systemctl status nginx` или `sudo systemctl status apache2`

## 📞 Полная документация

См. `PRODUCTION_DEPLOY.md` для подробных инструкций.
