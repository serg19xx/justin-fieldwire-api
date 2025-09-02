#!/usr/bin/env bash
#!/usr/bin/env bash
# ===========================================================
# Deploy script for FlightPHP project to shared hosting (SSH)
#
# Usage:
#   ./deploy_fwapi_ssh.sh                # auto: пробует composer на сервере, если нет — зальёт vendor с локали
#   ./deploy_fwapi_ssh.sh --with-vendor  # принудительно зальёт vendor/ с локали
#   ./deploy_fwapi_ssh.sh --no-vendor    # пропустит vendor (ожидается composer на сервере)
#   ./deploy_fwapi_ssh.sh --update-env   # перезальёт .env из локального файла (env.production)
#
# Flags можно комбинировать:
#   ./deploy_fwapi_ssh.sh --with-vendor --update-env
#
# Требования:
#   - SSH доступ на сервер (логин, хост, порт)
#   - rsync и curl локально
#   - composer локально (если vendor будет заливаться с локали)
#   - env.production локально (для флага --update-env)
#
# Результат:
#   - Код синхронизирован в $REMOTE_BASE
#   - Корневой .htaccess + public/.htaccess созданы при необходимости
#   - vendor установлен (через composer на сервере или заливается с локали)
#   - .env обновлён (если указан флаг)
#   - выполняется health-check: $API_BASE_URL/api/v1/health
# ===========================================================
set -euo pipefail

# ====== CONFIG (под тебя) ======
SSH_HOST="173.209.33.163"
SSH_USER="yjyhtqh8"
SSH_PORT="27"
REMOTE_BASE="/home/${SSH_USER}/fwapi.medicalcontractor.ca"
API_BASE_URL="https://fwapi.medicalcontractor.ca"

# Локальный .env для опциональной заливки (--update-env)
LOCAL_ENV_FILE="./env.production"

# Вендор: auto|yes|no
WITH_VENDOR="auto"
UPLOAD_ENV="no"
for arg in "${@:-}"; do
  case "$arg" in
    --with-vendor) WITH_VENDOR="yes" ;;
    --no-vendor)   WITH_VENDOR="no" ;;
    --update-env)  UPLOAD_ENV="yes" ;;
  esac
done

# ====== SSH helpers (прибираем мотд/профили) ======
REMOTE_SSH=(ssh -p "${SSH_PORT}" -o StrictHostKeyChecking=accept-new "${SSH_USER}@${SSH_HOST}")
RUN_REMOTE() { "${REMOTE_SSH[@]}" "bash --noprofile --norc -lc '$*'"; }

echo "==> SSH deploy → ${SSH_USER}@${SSH_HOST}:${SSH_PORT}  base=${REMOTE_BASE}"
WORKDIR="$(pwd)"
BUILD_DIR="${WORKDIR}/.deploy_build"
PKG_DIR="${BUILD_DIR}/pkg"

rm -rf "${BUILD_DIR}"; mkdir -p "${PKG_DIR}"

# 1) Подготовим пакет (без мусора и без .env)
rsync -a \
  --exclude ".git" --exclude ".github" --exclude "node_modules" \
  --exclude "tests" --exclude "docs" --exclude "*.md" --exclude "logs" \
  --exclude ".deploy_build" --exclude ".env" \
  ./ "${PKG_DIR}/"

# 2) Корневой .htaccess (если есть public/index.php — ведём в public/, иначе на корневой index.php)
if [[ -f "${PKG_DIR}/public/index.php" ]]; then
  cat > "${PKG_DIR}/.htaccess" <<'HTROOT'
Options -Indexes
DirectoryIndex public/index.php
RewriteEngine On
RewriteRule ^$ public/ [L]
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
HTROOT
else
  cat > "${PKG_DIR}/.htaccess" <<'HTROOT'
Options -Indexes
DirectoryIndex index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
HTROOT
fi

# 3) public/.htaccess (если нет)
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

# 4) .env (по желанию)
if [[ "${UPLOAD_ENV}" == "yes" ]]; then
  [[ -f "${LOCAL_ENV_FILE}" ]] || { echo "No ${LOCAL_ENV_FILE}"; exit 2; }
  cp "${LOCAL_ENV_FILE}" "${PKG_DIR}/.env"
fi

# 5) Не шлём vendor в основном rsync
rm -rf "${PKG_DIR}/vendor"

# 6) Создать каталог на сервере (без мотд/профилей)
RUN_REMOTE "mkdir -p '${REMOTE_BASE}'"

# 7) Заливка кода (быстро)
rsync -az --delete -e "ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new" \
  --exclude "vendor" \
  "${PKG_DIR}/" "${SSH_USER}@${SSH_HOST}:${REMOTE_BASE}/"

# 8) Composer на сервере или заливка vendor с локали
if [[ "${WITH_VENDOR}" == "no" ]]; then
  echo "==> Skip vendor (--no-vendor)"
elif [[ "${WITH_VENDOR}" == "yes" ]]; then
  echo "==> Upload local vendor (--with-vendor)"
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  rsync -az -e "ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new" \
    vendor/ "${SSH_USER}@${SSH_HOST}:${REMOTE_BASE}/vendor/"
else
  echo "==> Try composer on server (auto)"
  set +e
  RUN_REMOTE "cd '${REMOTE_BASE}' && if command -v composer >/dev/null 2>&1; then composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; else exit 99; fi"
  RC=$?
  set -e
  if [[ $RC -eq 99 ]]; then
    echo "==> No composer on server — uploading local vendor"
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    rsync -az -e "ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new" \
      vendor/ "${SSH_USER}@${SSH_HOST}:${REMOTE_BASE}/vendor/"
  fi
fi

# 9) Права (если есть storage)
RUN_REMOTE "cd '${REMOTE_BASE}' && [[ -d storage ]] && chmod -R 755 storage || true"

# 10) Health-check
sleep 3
if curl -fsS "${API_BASE_URL}/api/v1/health" >/dev/null; then
  echo "✓ Health OK"
else
  echo "!! Health FAILED — проверь .env / vendor / путь автозагрузчика"
fi

echo "DONE"