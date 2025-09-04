#!/usr/bin/env bash
set -euo pipefail

### === CONFIG === ###
PROJECT_NAME="fwapi"
LOCAL_ENV_FILE="./.env.production"     # локальный файл с прод-настройками
REMOTE_SUBDOMAIN_DIR="fwapi.medicalcontractor.ca"

# FTP доступ (данные твои из cPanel → FTP Accounts)
FTP_HOST="ftp.medicalcontractor.ca"
FTP_USER="fw-api1@fwapi.medicalcontractor.ca"
FTP_PASS="Medeli@AKX10"
FTP_REMOTE_DIR="/"   # обязательно со слэшем на конце!
### =============== ###

echo "==> Deploying ${PROJECT_NAME} to ${REMOTE_SUBDOMAIN_DIR} via FTP"

WORKDIR="$(pwd)"
BUILD_DIR="${WORKDIR}/.deploy_build"
PKG_DIR="${BUILD_DIR}/package"

rm -rf "${BUILD_DIR}"
mkdir -p "${PKG_DIR}"

# 1) Composer install локально
echo "==> Composer install (no-dev)"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# 2) Скопировать проект (исключая мусор)
echo "==> Preparing package"
rsync -a \
  --exclude ".git" \
  --exclude ".github" \
  --exclude "node_modules" \
  --exclude "tests" \
  --exclude "docs" \
  --exclude "*.md" \
  --exclude "logs" \
  --exclude ".env" \
  --exclude ".deploy_build" \
  ./ "${PKG_DIR}/"

# 3) Корневой .htaccess
cat > "${PKG_DIR}/.htaccess" <<'HTROOT'
Options -Indexes
DirectoryIndex public/index.php

RewriteEngine On
RewriteRule ^$ public/ [L]
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
HTROOT

# 4) .htaccess в public/ (если нет)
mkdir -p "${PKG_DIR}/public"
if [[ ! -f "${PKG_DIR}/public/.htaccess" ]]; then
cat > "${PKG_DIR}/public/.htaccess" <<'HTPUB'
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
HTPUB
fi

# 5) Подготовить .env
if [[ -f "${LOCAL_ENV_FILE}" ]]; then
  cp "${LOCAL_ENV_FILE}" "${PKG_DIR}/.env"
else
  echo "!! Файл ${LOCAL_ENV_FILE} не найден, создайте или измените путь в скрипте"
  exit 1
fi

# 6) Проверка, что index.php есть
if [[ ! -f "${PKG_DIR}/public/index.php" ]] && [[ ! -f "${PKG_DIR}/index.php" ]]; then
  echo "!! Не найден index.php (ни в public/, ни в корне)"
  exit 1
fi

# 7) Заливка через lftp
echo "==> Uploading via FTP to ${FTP_HOST}:${FTP_REMOTE_DIR}"
command -v lftp >/dev/null 2>&1 || { echo "!! lftp не установлен (sudo apt install lftp)"; exit 2; }

lftp -u "${FTP_USER}","${FTP_PASS}" "${FTP_HOST}" <<EOF
set ftp:ssl-allow true
set ssl:verify-certificate no
set net:max-retries 2
set net:timeout 20
mkdir -p "${FTP_REMOTE_DIR}"
mirror -R --delete \
  --exclude-glob .git* \
  --exclude-glob .github* \
  --exclude-glob node_modules* \
  --exclude-glob tests* \
  --exclude-glob docs* \
  --exclude-glob *.md \
  --exclude-glob logs* \
  "${PKG_DIR}/" "${FTP_REMOTE_DIR}"
bye
EOF

echo "==> Deploy finished!"