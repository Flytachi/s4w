#!/bin/sh

VOL=/tmp/flytachi.winter.volatile.html
mkdir -p "$VOL"
chown -R winter:winter "$VOL"
chmod 0700 "$VOL"

# ──────────────────────────────────────────────────────────────────────────────
#  s4w container entrypoint
#
#  upload_max_filesize / post_max_size — это PHP_INI_PERDIR/SYSTEM-директивы:
#  их НЕЛЬЗЯ менять через ini_set() в рантайме, PHP читает их до обработки тела
#  запроса. Поэтому применяем их из env здесь, на старте контейнера, ДО запуска
#  процессов (runit). Одна ручка для пользователя образа — UPLOAD_MAX_FILESIZE,
#  остальное выводится из неё.
#
#  Запуск:  docker run -e UPLOAD_MAX_FILESIZE=200M ...
# ──────────────────────────────────────────────────────────────────────────────
set -e

UPLOAD_MAX_FILESIZE="${UPLOAD_MAX_FILESIZE:-100M}"

# post_max_size должен быть больше upload (файл + поля формы + multipart-overhead).
# Берём то же число + 5 той же единицы. Единицу сохраняем как задал пользователь
# (PHP и nginx принимают и 'M', и 'm').
num="$(printf '%s' "$UPLOAD_MAX_FILESIZE" | tr -dc '0-9')"
unit="$(printf '%s' "$UPLOAD_MAX_FILESIZE" | tr -dc 'A-Za-z')"
if [ -z "$num" ]; then
    echo "[entrypoint] WARN: invalid UPLOAD_MAX_FILESIZE='$UPLOAD_MAX_FILESIZE', falling back to 100M" >&2
    UPLOAD_MAX_FILESIZE="100M"; num="100"; unit="M"
fi
POST_MAX_SIZE="$(( num + 5 ))${unit}"

# PHP-лимиты — отдельный conf.d файл (перекрывает дефолты php85 2M/8M).
cat > /etc/php85/conf.d/20-upload.ini <<EOF
upload_max_filesize = ${UPLOAD_MAX_FILESIZE}
post_max_size = ${POST_MAX_SIZE}
memory_limit = ${PHP_MEMORY_LIMIT:-256M}
max_execution_time = ${PHP_MAX_EXECUTION_TIME:-300}
max_input_time = ${PHP_MAX_INPUT_TIME:-300}
EOF

# nginx должен пропускать тело не меньше PHP — иначе 413 раньше PHP.
sed -i "s/client_max_body_size .*/client_max_body_size ${POST_MAX_SIZE};/" /etc/nginx/nginx.conf

echo "[entrypoint] upload_max_filesize=${UPLOAD_MAX_FILESIZE} post_max_size=${POST_MAX_SIZE} (nginx + php)"

exec runsvdir /etc/service