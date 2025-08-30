# 🌍 Настройка окружений FieldWire API

## 📋 Обзор конфигураций

Проект поддерживает два окружения с разными настройками базы данных:

### 🔧 Разработка (Development)
- **База данных**: Удаленная на `medicalcontractor.ca`
- **Хост**: `medicalcontractor.ca`
- **Использование**: Локальная разработка
- **Конфигурация**: `env.development`

### 🚀 Продакшен (Production)
- **База данных**: Локальная на том же сервере
- **Хост**: `localhost`
- **Использование**: Продакшен сервер
- **Конфигурация**: `env.production`

## ⚙️ Быстрая настройка

### Для разработки:
```bash
# Скопируйте конфигурацию разработки
cp env.development .env

# Запустите сервер
./scripts/start-server.sh
```

### Для продакшена:
```bash
# Скопируйте конфигурацию продакшена
cp env.production .env

# Подготовьте к деплою
./scripts/deploy.sh
```

## 🔑 Ключевые различия

| Настройка | Разработка | Продакшен |
|-----------|------------|-----------|
| `DB_HOST` | `medicalcontractor.ca` | `localhost` |
| `APP_ENV` | `development` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `LOG_LEVEL` | `debug` | `error` |
| `CORS_ORIGINS` | Все localhost порты | Только продакшен домены |

## 📝 Настройка паролей

### Разработка:
Отредактируйте `env.development` и установите:
```env
DB_PASSWORD=your_development_password
```

### Продакшен:
Отредактируйте `env.production` и установите:
```env
DB_PASSWORD=your_production_password
```

## 🧪 Тестирование подключений

### Разработка:
```bash
# Тест подключения к удаленной БД
mysql -h medicalcontractor.ca -u yjyhtqh8_fieldwire -p yjyhtqh8_fieldwire -e "SELECT 1;"
```

### Продакшен:
```bash
# Тест подключения к локальной БД
mysql -u yjyhtqh8_fieldwire -p yjyhtqh8_fieldwire -e "SELECT 1;"
```

## 🔄 Переключение между окружениями

```bash
# Переключиться на разработку
cp env.development .env
./scripts/restart-server.sh

# Переключиться на продакшен
cp env.production .env
./scripts/restart-server.sh
```

## 📊 Мониторинг

### Проверка текущего окружения:
```bash
# Через API
curl http://localhost:8000/api/v1/health | grep -o '"database":{"status":"[^"]*"'

# Через файл конфигурации
grep "DB_HOST\|APP_ENV" .env
```

## 🆘 Устранение проблем

### Ошибка подключения к БД:
1. Проверьте правильность конфигурации
2. Убедитесь, что пароль корректный
3. Проверьте доступность хоста

### CORS ошибки:
1. Проверьте `CORS_ALLOWED_ORIGINS` в .env
2. Убедитесь, что домен фронтенда добавлен

### Медленная работа:
1. Разработка: проверьте сетевое подключение к `medicalcontractor.ca`
2. Продакшен: проверьте локальную производительность БД
