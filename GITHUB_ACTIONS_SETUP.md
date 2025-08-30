# 🚀 GitHub Actions - Полная автоматизация деплоя

## 📋 Что делает этот workflow:

1. **Автоматически запускается** при push в main ветку
2. **Подготавливает проект** (устанавливает зависимости, создает архив)
3. **Загружает файлы** на сервер через FTP
4. **Настраивает сервер** через SSH (устанавливает зависимости, настраивает БД)
5. **Тестирует деплой** (проверяет эндпоинты)
6. **Уведомляет о результате**

## ⚙️ Настройка GitHub Secrets

### 1. Перейдите в настройки репозитория:
- GitHub → ваш репозиторий → Settings → Secrets and variables → Actions

### 2. Добавьте следующие secrets:

#### FTP Secrets:
```
FTP_SERVER=ftp.medicalcontractor.ca
FTP_USERNAME=fw-api@medicalcontractor.ca
FTP_PASSWORD=Medeli@AKX10
```

#### SSH Secrets:
```
SSH_HOST=medicalcontractor.ca
SSH_USERNAME=yjyhtqh8_fieldwire
SSH_PRIVATE_KEY=-----BEGIN OPENSSH PRIVATE KEY-----
ваш_приватный_ssh_ключ
-----END OPENSSH PRIVATE KEY-----
```

## 🔑 Как получить SSH ключ:

### Если у вас нет SSH ключа:
```bash
# Создайте новый SSH ключ
ssh-keygen -t rsa -b 4096 -C "github-actions@medicalcontractor.ca"

# Скопируйте приватный ключ
cat ~/.ssh/id_rsa

# Добавьте публичный ключ на сервер
cat ~/.ssh/id_rsa.pub
```

### Добавьте публичный ключ на сервер:
1. SSH на сервер: `ssh yjyhtqh8_fieldwire@medicalcontractor.ca`
2. Добавьте ключ в `~/.ssh/authorized_keys`:
```bash
echo "ваш_публичный_ключ" >> ~/.ssh/authorized_keys
```

## 🚀 Как использовать:

### Автоматический деплой:
```bash
# Просто сделайте push в main ветку
git add .
git commit -m "Update API"
git push origin main
```

### Ручной запуск:
1. GitHub → Actions → Deploy to Production
2. Нажмите "Run workflow"

## 📊 Мониторинг:

### Проверка статуса:
- GitHub → Actions → Deploy to Production
- Посмотрите логи выполнения

### Уведомления:
- В логах Actions будет показан результат
- Успешный деплой: "✅ Deployment successful!"
- Ошибка: "❌ Deployment failed!"

## 🔧 Настройка сервера:

### Убедитесь, что на сервере установлены:
```bash
# PHP 8.2+
php -v

# Composer
composer -V

# MySQL
mysql -V

# SSH доступ
ssh yjyhtqh8_fieldwire@medicalcontractor.ca
```

## 🆘 Устранение проблем:

### Ошибка FTP:
- Проверьте FTP credentials в secrets
- Убедитесь, что FTP сервер доступен

### Ошибка SSH:
- Проверьте SSH ключ в secrets
- Убедитесь, что публичный ключ добавлен на сервер

### Ошибка тестов:
- Проверьте, что домен `fwapi.medicalcontractor.ca` работает
- Убедитесь, что SSL сертификат настроен

## 🎯 Преимущества:

✅ **Полная автоматизация** - никаких ручных действий  
✅ **Надежность** - автоматические тесты после деплоя  
✅ **Прозрачность** - полные логи в GitHub Actions  
✅ **Безопасность** - credentials в GitHub Secrets  
✅ **Масштабируемость** - легко добавить новые окружения  

## 📞 После настройки:

1. Добавьте все secrets в GitHub
2. Сделайте push в main ветку
3. Проверьте результат в Actions
4. Тестируйте API: `https://fwapi.medicalcontractor.ca/api/v1/health`

Готово! Теперь каждый push в main будет автоматически деплоить на продакшен! 🎉
