# Инструкции для исправления JWT проблемы

## Проблема
AuthController генерировал простой base64 токен вместо правильного JWT с подписью.

## Что было исправлено
1. ✅ `src/Controllers/AuthController.php` - метод `generateToken()` теперь создает правильный JWT
2. ✅ `src/Middleware/AuthMiddleware.php` - добавлено детальное логирование
3. ✅ `src/Routes/ApiRoutes.php` - toggle2FA защищен AuthMiddleware
4. ✅ `src/Controllers/TwoFactorController.php` - время истечения кода 1 минута
5. ✅ `src/Services/EmailService.php` - SendGrid API по умолчанию

## Что нужно сделать ПРЯМО СЕЙЧАС

### 1. Скопировать файлы в .deploy_build
```bash
cd /Users/justinkearney/Documents/Projects/Justin/fieldwire-api

cp -f src/Middleware/AuthMiddleware.php .deploy_build/pkg/src/Middleware/AuthMiddleware.php
cp -f src/Controllers/AuthController.php .deploy_build/pkg/src/Controllers/AuthController.php
cp -f src/Routes/ApiRoutes.php .deploy_build/pkg/src/Routes/ApiRoutes.php
```

### 2. Перезапустить сервер
```bash
pkill -9 php
sleep 2
php -S localhost:8000 -t public > /dev/null 2>&1 &
```

### 3. На фронтенде
1. Выйти из системы (logout)
2. Войти заново
3. Открыть страницу проектов

## Проверка
После входа токен должен выглядеть так:
```
eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjo0NywiZW1haWwiOiJzZXJnLmtvc3R5dWtAZ21haWwuY29tIiwibmFtZSI6Ik1pa2UgRGF2aXMiLCJpYXQiOjE3NjA1NjkwNzYsImV4cCI6MTc2MDU3MjY3Nn0.6_Rumq8dAzijG8qMWfDNDu-uFvtW-h90zVKaGnAgH4I
```

Должно быть **2 точки** (три части: header.payload.signature)

## Если все еще не работает
Проверьте логи:
```bash
tail -50 logs/app.log | grep -E "(Token extracted|has_dots|JWT decoded)"
```

Должны увидеть:
- `"has_dots":2` 
- `"JWT decoded successfully"`
- `"Getting user from database"`

