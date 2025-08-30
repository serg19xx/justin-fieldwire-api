# Автоматический Деплой - Настройка

## 🔧 GitHub Secrets

Добавьте в GitHub → Settings → Secrets and variables → Actions:

### FTP (для загрузки файлов):
- `FTP_SERVER` = `medicalcontractor.ca`
- `FTP_USERNAME` = `yjyhtqh8_fieldwire`
- `FTP_PASSWORD` = `ваш_ftp_пароль`

### SSH (для ручного подключения):
- SSH доступ: `ssh yjyhtqh8@medicalcontractor.ca`
- Пароль: ваш SSH пароль

### Environment:
- `ENV_FILE` = `весь_содержимое_файла_.env`

## 🚀 Как использовать:

1. **Настройте secrets** (см. выше)
2. **Запушьте в main** или нажмите "Run workflow"
3. **Дождитесь загрузки файлов** (автоматически)
4. **Подключитесь по SSH** и запустите скрипт вручную

## ✅ Результат:

Ваш API будет доступен по адресам:
- **Health**: https://fwapi.medicalcontractor.ca/api/v1/health
- **Tables**: https://fwapi.medicalcontractor.ca/api/v1/database/tables
- **Docs**: https://fwapi.medicalcontractor.ca/api/docs
