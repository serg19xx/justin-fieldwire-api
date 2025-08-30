# FieldWire API

REST API построенный на базе FlightPHP с современной архитектурой и лучшими практиками.

## Особенности

- 🚀 **FlightPHP** - легкий и быстрый PHP фреймворк
- 📊 **Логирование** - Monolog для детального логирования
- 🔒 **CORS поддержка** - настраиваемая политика CORS
- 🧪 **Тестирование** - PHPUnit для unit тестов
- 📋 **Код стайл** - PHP CodeSniffer для стандартов кода
- 🔢 **Версионирование** - поддержка версий API

## Требования

- PHP 8.1 или выше
- Composer
- MySQL 5.7 или выше (для продакшена)
- Веб-сервер (Apache/Nginx) или встроенный PHP сервер

## Установка

1. Клонируйте репозиторий:
```bash
git clone <repository-url>
cd fieldwire-api
```

2. Установите зависимости:
```bash
composer install
```

3. Скопируйте файл конфигурации:
```bash
cp env.example .env
```

4. Настройте переменные окружения в файле `.env`:
```env
# Application Configuration
APP_NAME="FieldWire API"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

# Logging
LOG_LEVEL=debug
LOG_CHANNEL=file

# CORS Configuration
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080
CORS_ALLOWED_METHODS=GET,POST,PUT,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With
```

5. Создайте директории для логов:
```bash
mkdir -p logs
chmod 755 logs
```

6. Настройте базу данных в файле `.env`:
```env
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_NAME=fieldwire_api
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

7. Установите базу данных:
```bash
composer db:setup
```

## Запуск

### Разработка

#### Использование скриптов (рекомендуется):
```bash
# Запуск сервера
./scripts/start-server.sh

# Запуск на другом порту
./scripts/start-server.sh 8080

# Остановка сервера
./scripts/stop-server.sh

# Перезапуск сервера
./scripts/restart-server.sh
```

#### Использование Composer:
```bash
# Запуск сервера
composer server:start

# Остановка сервера
composer server:stop

# Перезапуск сервера
composer server:restart
```

#### Ручной запуск:
```bash
php -S localhost:8000 -t public
```

### Продакшн
Настройте веб-сервер (Apache/Nginx) для работы с директорией `public/`.

## API Endpoints

### Health Check
- `GET /api/v1/health` - Check API health status
- `GET /api/v1/version` - Get API version information
- `GET /api/health` - Legacy health check (backward compatibility)

### Documentation
- `GET /api` - API documentation overview
- `GET /api/docs` - Swagger UI interface
- `GET /api/swagger/spec` - OpenAPI specification (JSON)

### Examples

#### Health Check
```bash
curl http://localhost:8000/api/v1/health
```

Response:
```json
{
    "status": "healthy",
    "timestamp": "2025-08-28T21:30:00+00:00",
    "uptime": {
        "seconds": 12345,
        "formatted": "3h 25m 45s"
    },
    "memory_usage": {
        "current": 2097152,
        "peak": 3145728,
        "limit": "512M"
    },
    "version": "1.0.0"
}
```

#### API Version
```bash
curl http://localhost:8000/api/v1/version
```

Response:
```json
{
    "api_version": "v1",
    "status": "stable",
    "released": "2025-08-28",
    "endpoints": {
        "health": "GET /api/v1/health",
        "version": "GET /api/v1/version"
    }
}
```

#### API Documentation
```bash
curl http://localhost:8000/api
```

Response:
```json
{
    "name": "FieldWire API",
    "version": "1.0.0",
    "description": "REST API built with FlightPHP",
    "documentation": {
        "swagger_ui": "/api/docs",
        "openapi_spec": "/api/swagger/spec"
    },
    "versions": {
        "v1": {
            "status": "stable",
            "endpoints": {
                "health": "GET /api/v1/health",
                "version": "GET /api/v1/version"
            }
        }
    }
}
```

## Версионирование API

API использует версионирование в URL:
- **v1** - текущая стабильная версия
- **Legacy** - старые маршруты для обратной совместимости

### Структура версий:
```
/api/v1/     - API версии 1
/api/        - Legacy маршруты
```

## Примеры запросов

### Проверка здоровья API (v1)
```bash
curl -X GET http://localhost:8000/api/v1/health
```

### Информация о версии API (v1)
```bash
curl -X GET http://localhost:8000/api/v1/version
```

### Проверка здоровья API (legacy)
```bash
curl -X GET http://localhost:8000/api/health
```

### Получение документации API
```bash
curl -X GET http://localhost:8000/api
```

## Структура проекта

```
fieldwire-api/
├── public/                 # Публичная директория
│   └── index.php          # Точка входа
├── src/                   # Исходный код
│   ├── Bootstrap/         # Инициализация приложения
│   ├── Config/           # Конфигурация
│   ├── Controllers/      # Контроллеры
│   ├── Middleware/       # Middleware
│   └── Routes/           # Маршруты
├── tests/                # Тесты
├── logs/                 # Логи
├── composer.json         # Зависимости
├── env.example           # Пример конфигурации
└── README.md            # Документация
```

## Разработка

### Запуск тестов
```bash
composer test
```

### Проверка кода
```bash
composer analyze
composer cs
```

### Исправление стиля кода
```bash
composer cs-fix
```

## Лицензия

MIT License

## Деплой на продакшен

### GitHub Actions (рекомендуется) ⭐
```bash
# Полная автоматизация - деплой при каждом push в main
git add .
git commit -m "Update API"
git push origin main
```

**Настройка:**
1. Добавьте secrets в GitHub (см. `GITHUB_ACTIONS_SETUP.md`)
2. Каждый push в main автоматически деплоит на продакшен
3. Проверяйте статус в GitHub → Actions

### Автоматический деплой через SSH
```bash
# Полностью автоматический деплой на сервер через SSH
./scripts/auto-deploy.sh
```

### Автоматический деплой через FTP
```bash
# Автоматическая загрузка через FTP + ручная настройка на сервере
./scripts/ftp-deploy.sh
```

### Простой деплой (ручная загрузка)
```bash
# Подготовка файлов для ручной загрузки
./scripts/simple-deploy.sh
```

### Ручной деплой
```bash
# Подготовка к деплою
./scripts/deploy.sh

# Загрузка файлов на сервер и настройка
# См. PRODUCTION_DEPLOY.md для подробных инструкций
```
# Force redeploy
