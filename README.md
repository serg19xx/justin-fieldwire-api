# FieldWire API

REST API для приложения FieldWire, построенный на FlightPHP с поддержкой аутентификации, 2FA и управления пользователями.

## 🚀 **Быстрый старт**

### **Локальная разработка:**
```bash
# Клонировать репозиторий
git clone https://github.com/your-username/fieldwire-api.git
cd fieldwire-api

# Установить зависимости
composer install

# Настроить окружение
cp env.development .env

# Запустить сервер
./scripts/start-server.sh 8000
```

### **Доступные endpoints:**
- **Health Check:** http://localhost:8000/api/v1/health
- **API Info:** http://localhost:8000/api
- **Swagger UI:** http://localhost:8000/docs
- **Swagger JSON:** http://localhost:8000/swagger.json

## 🔧 **Технологии**

- **PHP 8.2+** - основной язык
- **FlightPHP** - веб-фреймворк
- **Doctrine DBAL** - работа с базой данных
- **Monolog** - логирование
- **Twilio** - SMS сервис
- **SendGrid** - email сервис
- **Swagger/OpenAPI** - документация API

## 📚 **Документация**

- [API Endpoints Specification](docs/API_ENDPOINTS_SPECIFICATION.md)
- [2FA API Guide](docs/2FA_API.md)
- [Avatar Upload Guide](docs/AVATAR_USAGE_GUIDE.md)
- [Email Setup](docs/EMAIL_SETUP.md)

## 🚀 **Деплой**

### **Автоматический деплой через GitHub Actions:**

1. **Настройте GitHub Secrets:**
   - `FTP_SERVER` - ftp.medicalcontractor.ca
   - `FTP_USERNAME` - yjyhtqh8_fieldwire
   - `FTP_PASSWORD` - Medeli@2025

2. **Деплой происходит автоматически:**
   - **Production** - при push в `main` или `production`
   - **Staging** - при push в `develop` или `staging`

3. **Ручной деплой:**
   - GitHub → Actions → Deploy to Production → Run workflow

### **Ручной деплой:**
```bash
# Подготовка для продакшна
./scripts/deploy-shared-hosting.sh

# Создание архива
zip -r fieldwire-api-production.zip . -x "*.git*" "tests/*" "scripts/*" "docs/*" "*.md" "nginx.conf" "env.development" "env.example" "logs/*" ".env"

# Загрузить на хостинг через cPanel File Manager
```

## 🌐 **Production URLs**

- **Production:** https://fieldwire.medicalcontractor.ca
- **Health Check:** https://fieldwire.medicalcontractor.ca/api/v1/health
- **Swagger UI:** https://fieldwire.medicalcontractor.ca/docs
- **API Info:** https://fieldwire.medicalcontractor.ca/api

## 🧪 **Тестирование**

```bash
# Запуск тестов
composer test

# Проверка кода
composer cs-check

# Исправление стиля кода
composer cs-fix

# Анализ кода
composer analyze
```

## 📁 **Структура проекта**

```
fieldwire-api/
├── .github/workflows/          # GitHub Actions
├── docs/                       # Документация
├── public/                     # Публичные файлы
│   ├── index.php              # Точка входа
│   ├── .htaccess              # Apache конфигурация
│   ├── swagger.php            # Swagger JSON
│   └── swagger-ui.php         # Swagger UI
├── scripts/                    # Скрипты деплоя
├── src/                        # Исходный код
│   ├── Bootstrap/             # Инициализация приложения
│   ├── Config/                # Конфигурация
│   ├── Controllers/           # Контроллеры API
│   ├── Database/              # Работа с БД
│   ├── Middleware/            # Промежуточное ПО
│   ├── Routes/                # Маршруты
│   ├── Services/              # Сервисы
│   └── Swagger/               # OpenAPI спецификация
├── tests/                      # Тесты
├── vendor/                     # Зависимости Composer
├── .env                       # Переменные окружения
├── composer.json              # Зависимости
└── README.md                  # Этот файл
```

## 🔒 **Безопасность**

- JWT аутентификация
- 2FA поддержка
- CORS настройки
- Защита от SQL инъекций
- Валидация входных данных
- Логирование всех операций

## 📝 **Разработка**

### **Добавление новых endpoints:**
1. Создайте контроллер в `src/Controllers/`
2. Добавьте OpenAPI аннотации
3. Зарегистрируйте маршрут в `src/Routes/ApiRoutes.php`
4. Добавьте тесты в `tests/`

### **Обновление Swagger:**
- Аннотации автоматически генерируют документацию
- Swagger UI доступен по адресу `/docs`
- JSON спецификация по адресу `/swagger.json`

## 🚨 **Поддержка**

При возникновении проблем:

1. Проверьте логи в папке `logs/`
2. Убедитесь, что `.env` файл настроен
3. Проверьте права доступа к папкам
4. Обратитесь в поддержку хостинга

## 📄 **Лицензия**

Proprietary - все права защищены.

---

**FieldWire API** - мощное и надежное решение для управления полевыми работами и коммуникации.
