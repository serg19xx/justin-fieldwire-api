# GitHub Actions Setup для автоматического деплоя

## 🚀 **Настройка автоматического деплоя через GitHub Actions**

### **Шаг 1: Создание GitHub Secrets**

В вашем GitHub репозитории перейдите в:
**Settings → Secrets and variables → Actions**

Создайте следующие секреты:

#### **ENV_FILE**
```
Значение: [Содержимое вашего .env файла для продакшна]
Описание: Переменные окружения для продакшн сервера
```

#### **FTP_SERVER**
```
Значение: ftp.medicalcontractor.ca
Описание: FTP сервер хостинга
```

#### **FTP_USERNAME**
```
Значение: yjyhtqh8_fieldwire
Описание: FTP логин для хостинга
```

#### **FTP_PASSWORD**
```
Значение: Medeli@2025
Описание: FTP пароль для хостинга
```

### **Шаг 2: Настройка веток**

Убедитесь, что у вас есть ветка `main` или `production`:

```bash
# Создать ветку production (если нужно)
git checkout -b production

# Или использовать main
git checkout main
```

### **Шаг 3: Первый деплой**

После настройки секретов:

1. **Автоматический деплой** - при каждом push в `main` или `production`
2. **Ручной деплой** - через GitHub Actions → Run workflow

### **Шаг 4: Проверка деплоя**

После успешного деплоя проверьте:

- ✅ **Health Check:** https://fieldwire.medicalcontractor.ca/api/v1/health
- ✅ **Swagger UI:** https://fieldwire.medicalcontractor.ca/docs
- ✅ **API Info:** https://fieldwire.medicalcontractor.ca/api

## 🔧 **Как работает автоматический деплой:**

### **Триггеры:**
- **Push** в ветки `main` или `production`
- **Ручной запуск** через GitHub Actions

### **Процесс деплоя:**
1. **Checkout** кода
2. **Setup PHP 8.2** с нужными расширениями
3. **Install Composer** зависимости
4. **Setup production** окружение
5. **Run tests** (если доступны)
6. **Check code style** (если доступны)
7. **Create deployment** пакет
8. **Deploy via FTP** на хостинг
9. **Health check** продакшн endpoints
10. **Notify** о результате

### **Что деплоится:**
- ✅ Весь код приложения
- ✅ Vendor зависимости
- ✅ Production конфигурация
- ✅ .htaccess файлы
- ✅ Swagger файлы

### **Что НЕ деплоится:**
- ❌ Git файлы
- ❌ Тесты
- ❌ Скрипты разработки
- ❌ Документация
- ❌ Логи
- ❌ Development конфигурация

## 🚨 **Возможные проблемы и решения:**

### **Ошибка FTP подключения:**
- Проверьте правильность FTP_SERVER, FTP_USERNAME, FTP_PASSWORD
- Убедитесь, что FTP доступен на хостинге
- Проверьте firewall на хостинге

### **Ошибка прав доступа:**
- Убедитесь, что FTP пользователь имеет права на запись в public_html
- Проверьте права на папки logs и uploads

### **Health check не проходит:**
- Подождите больше времени после деплоя
- Проверьте логи на хостинге
- Убедитесь, что .env файл загружен

## 📝 **Полезные команды:**

### **Проверка статуса деплоя:**
```bash
# Посмотреть логи GitHub Actions
# Перейдите в Actions → Deploy to Production → View logs
```

### **Ручной запуск деплоя:**
```bash
# В GitHub: Actions → Deploy to Production → Run workflow
# Выберите ветку и нажмите Run workflow
```

### **Откат к предыдущей версии:**
```bash
# Создайте новую ветку с предыдущим коммитом
git checkout -b rollback
git reset --hard HEAD~1
git push origin rollback

# Запустите деплой с этой ветки
```

## ✅ **Готово!**

После настройки GitHub Actions ваш API будет автоматически деплоиться на продакшн при каждом push в main/production ветку!

### **Преимущества автоматического деплоя:**
- 🚀 **Быстро** - деплой за 2-3 минуты
- 🔒 **Безопасно** - через GitHub Secrets
- ✅ **Надежно** - с тестами и проверками
- 📊 **Прозрачно** - полные логи в GitHub Actions
- 🔄 **Автоматически** - при каждом push
