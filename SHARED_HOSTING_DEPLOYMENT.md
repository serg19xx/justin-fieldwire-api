# FieldWire API - Shared Hosting Deployment Guide

## 🚀 **Быстрый деплой на паблик хостинг**

### **Шаг 1: Подготовка проекта**
```bash
# Запустите скрипт подготовки для паблик хостинга
./scripts/deploy-shared-hosting.sh
```

### **Шаг 2: Загрузка файлов на хостинг**

1. **Создайте ZIP архив:**
   ```bash
   zip -r fieldwire-api-production.zip . -x "*.git*" "tests/*" "scripts/*" "docs/*" "*.md" "nginx.conf"
   ```

2. **Загрузите на хостинг:**
   - Войдите в cPanel или панель управления хостингом
   - Откройте File Manager
   - Перейдите в `public_html` (или корневую папку сайта)
   - Загрузите и распакуйте `fieldwire-api-production.zip`

### **Шаг 3: Настройка структуры папок**

После распаковки структура должна быть:
```
public_html/
├── .env                    # Конфигурация продакшна
├── .htaccess              # Apache конфигурация
├── index.php              # Главный файл
├── composer.json          # Зависимости
├── vendor/                # Библиотеки
├── src/                   # Исходный код
├── logs/                  # Логи
└── public/                # Публичные файлы
    ├── index.php          # Точка входа
    ├── .htaccess          # Apache правила
    ├── swagger.php        # Swagger JSON
    ├── swagger-ui.php     # Swagger UI
    ├── avatar.php         # Загрузка аватаров
    └── uploads/           # Загруженные файлы
        └── avatars/       # Аватары пользователей
```

### **Шаг 4: Настройка .env файла**

Отредактируйте `.env` файл на хостинге:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fieldwire.medicalcontractor.ca

# База данных (локальная на том же сервере)
DB_HOST=localhost
DB_NAME=yjyhtqh8_easyrx
DB_USERNAME=yjyhtqh8_fieldwire
DB_PASSWORD=Medeli@2025
```

### **Шаг 5: Проверка требований**

Убедитесь, что на хостинге:
- ✅ **PHP 8.2+** установлен
- ✅ **mod_rewrite** включен для Apache
- ✅ **composer** доступен (или загружены vendor файлы)
- ✅ **SSL сертификат** настроен

### **Шаг 6: Тестирование**

После загрузки проверьте:

1. **Health Check:**
   ```
   https://fieldwire.medicalcontractor.ca/api/v1/health
   ```

2. **API Info:**
   ```
   https://fieldwire.medicalcontractor.ca/api
   ```

3. **Swagger UI:**
   ```
   https://fieldwire.medicalcontractor.ca/docs
   ```

4. **Swagger JSON:**
   ```
   https://fieldwire.medicalcontractor.ca/swagger.json
   ```

## 🔧 **Что работает автоматически:**

### **Маршрутизация:**
- Все запросы идут через `public/index.php`
- `.htaccess` перенаправляет все на FlightPHP
- Swagger endpoints работают из коробки

### **Безопасность:**
- Доступ к чувствительным файлам заблокирован
- CORS настроен для API
- Заголовки безопасности включены

### **Производительность:**
- OPcache включен
- Статические файлы кешируются
- Автозагрузчик оптимизирован

## 🚨 **Возможные проблемы и решения:**

### **Ошибка 500:**
- Проверьте логи в папке `logs/`
- Убедитесь, что `.env` файл загружен
- Проверьте права доступа к папкам

### **404 ошибки:**
- Убедитесь, что `mod_rewrite` включен
- Проверьте `.htaccess` файл в `public_html`
- Проверьте, что `index.php` в корне

### **Swagger не работает:**
- Проверьте, что `swagger.php` и `swagger-ui.php` загружены
- Убедитесь, что `swagger.json` существует
- Проверьте права доступа к файлам

## 📞 **Поддержка:**

Если что-то не работает:
1. Проверьте логи в папке `logs/`
2. Убедитесь, что все файлы загружены
3. Проверьте настройки хостинга
4. Обратитесь в поддержку хостинга

## ✅ **Готово!**

После выполнения всех шагов ваш API будет работать на продакшн сервере точно так же, как локально!
