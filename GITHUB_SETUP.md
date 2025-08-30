# GitHub Actions Setup - FTP Deployment

## 🔧 Настройка GitHub Secrets

Перейдите в ваш GitHub репозиторий → Settings → Secrets and variables → Actions

### 🔑 Создание SSH ключа (если нет):

```bash
# Создать новый SSH ключ (БЕЗ пароля для автоматизации)
ssh-keygen -t rsa -b 4096 -C "github-actions@medicalcontractor.ca" -f ~/.ssh/github_actions -N ""

# Показать публичный ключ (добавить на сервер)
cat ~/.ssh/github_actions.pub

# Показать приватный ключ (добавить в GitHub Secrets)
cat ~/.ssh/github_actions
```

**Важно:** 
- Добавьте публичный ключ в `~/.ssh/authorized_keys` на сервере!
- Для автоматизации лучше создавать ключ БЕЗ пароля (пустой passphrase)

### 📋 Добавьте следующие secrets:

#### FTP Credentials:
- `FTP_SERVER` = `medicalcontractor.ca`
- `FTP_USERNAME` = `yjyhtqh8_fieldwire`
- `FTP_PASSWORD` = `ваш_ftp_пароль`

#### SSH Credentials:
- `SSH_HOST` = `medicalcontractor.ca`
- `SSH_USERNAME` = `yjyhtqh8`
- `SSH_PRIVATE_KEY` = `ваш_приватный_ssh_ключ`
- `SSH_PASSPHRASE` = `пароль_от_ssh_ключа` (если есть)
- `SSH_PORT` = `22`

#### Environment File:
- `ENV_FILE` = `весь_содержимое_файла_.env`

## 🚀 Как использовать:

1. **Настройте secrets** (см. выше)
2. **Запушьте в main ветку** или используйте "Run workflow" в GitHub
3. **Дождитесь завершения** - всё происходит автоматически!

## ✅ Готово!

После выполнения всех шагов ваш API будет доступен по адресу:
- **Health Check**: https://fwapi.medicalcontractor.ca/api/v1/health
- **Database Tables**: https://fwapi.medicalcontractor.ca/api/v1/database/tables
- **API Docs**: https://fwapi.medicalcontractor.ca/api/docs
