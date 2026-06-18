#!/bin/sh
# ──────────────────────────────────────────────────────────────────────────────
#  s4w container entrypoint (public image — конфиг ТОЛЬКО через env)
#
#  Делает на старте, до запуска процессов:
#   1) Генерит WINTER_KEY / TOKEN, если не заданы (и сохраняет в volume, чтобы
#      пережить рестарт — JWT/токены останутся валидными при смонтированном storage).
#   2) Если DB_HOST не задан — поднимает встроенный PostgreSQL и подключается к нему
#      (initdb + create db/user + db migrate на первом запуске). Если DB_HOST задан —
#      использует внешнюю БД как есть.
#   3) Применяет лимиты загрузки из UPLOAD_MAX_FILESIZE (php + nginx).
# ──────────────────────────────────────────────────────────────────────────────
set -e

# Volatile-кэш фреймворка (route/DI cache, jobs) — владелец winter.
VOL=/tmp/flytachi.winter.volatile.html
mkdir -p "$VOL"
chown -R winter:winter "$VOL"
chmod 0700 "$VOL"

# ── 1. Секреты: WINTER_KEY и TOKEN ────────────────────────────────────────────
SECRETS_FILE=/var/www/html/storage/.runtime_secrets
gen_hex() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'; }   # 64 hex

# env имеет приоритет над persisted-значениями.
_env_winter="$WINTER_KEY"
_env_token="$TOKEN"
if [ -z "$WINTER_KEY" ] || [ -z "$TOKEN" ]; then
    [ -f "$SECRETS_FILE" ] && . "$SECRETS_FILE" 2>/dev/null || true
fi
[ -n "$_env_winter" ] && WINTER_KEY="$_env_winter"
[ -n "$_env_token" ]  && TOKEN="$_env_token"

genflag=0
if [ -z "$WINTER_KEY" ]; then WINTER_KEY="$(gen_hex)"; genflag=1; echo "[entrypoint] WINTER_KEY auto-generated"; fi
if [ -z "$TOKEN" ];      then TOKEN="$(gen_hex)";      genflag=1; echo "[entrypoint] TOKEN auto-generated"; fi
export WINTER_KEY TOKEN

# Сохраняем только если что-то сгенерили (секреты из env на диск не пишем).
if [ "$genflag" = "1" ]; then
    mkdir -p "$(dirname "$SECRETS_FILE")"
    { printf 'WINTER_KEY=%s\n' "$WINTER_KEY"; printf 'TOKEN=%s\n' "$TOKEN"; } > "$SECRETS_FILE"
    chmod 600 "$SECRETS_FILE" 2>/dev/null || true
fi

# ── 2. БД: внешняя (DB_HOST задан) или встроенный PostgreSQL ───────────────────
PGDATA=/var/lib/postgresql/data
if [ -z "$DB_HOST" ]; then
    echo "[entrypoint] DB_HOST не задан → поднимаю встроенный PostgreSQL"
    DB_HOST=127.0.0.1
    DB_PORT="${DB_PORT:-5432}"
    DB_NAME="${DB_NAME:-s4w}"
    DB_USER="${DB_USER:-s4w}"
    DB_PASS="${DB_PASS:-s4w}"
    DB_SCHEMA="${DB_SCHEMA:-public}"
    export DB_HOST DB_PORT DB_NAME DB_USER DB_PASS DB_SCHEMA

    mkdir -p "$PGDATA" /run/postgresql
    chown -R postgres:postgres /var/lib/postgresql /run/postgresql

    FRESH=0
    if [ ! -s "$PGDATA/PG_VERSION" ]; then
        FRESH=1
        su-exec postgres initdb -D "$PGDATA" --auth-local=trust --auth-host=md5 -E UTF8 >/dev/null
        printf "listen_addresses = '127.0.0.1'\nport = %s\n" "$DB_PORT" >> "$PGDATA/postgresql.conf"
    fi

    # Стартуем daemonized (pg_ctl форкает) — postgres остаётся жив после exec runsvdir.
    su-exec postgres pg_ctl -D "$PGDATA" -w -t 60 -l "$PGDATA/startup.log" start

    if [ "$FRESH" = "1" ]; then
        su-exec postgres sh -c "psql -p $DB_PORT -tAc \"SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'\" | grep -q 1" \
            || su-exec postgres psql -p "$DB_PORT" -c "CREATE ROLE \"$DB_USER\" LOGIN PASSWORD '$DB_PASS';"
        su-exec postgres sh -c "psql -p $DB_PORT -tAc \"SELECT 1 FROM pg_database WHERE datname='$DB_NAME'\" | grep -q 1" \
            || su-exec postgres psql -p "$DB_PORT" -c "CREATE DATABASE \"$DB_NAME\" OWNER \"$DB_USER\";"
        echo "[entrypoint] fresh local DB → ./call db migrate"
        su-exec winter sh -c "cd /var/www/html && ./call db migrate" || echo "[entrypoint] WARN: db migrate не прошёл — запусти вручную"
    fi
    echo "[entrypoint] local PostgreSQL ready (db=$DB_NAME user=$DB_USER port=$DB_PORT)"
else
    echo "[entrypoint] external DB: $DB_HOST:${DB_PORT:-5432}/${DB_NAME:-s4w}"
fi

# ── 3. Лимиты загрузки (UPLOAD_MAX_FILESIZE → php + nginx) ─────────────────────
UPLOAD_MAX_FILESIZE="${UPLOAD_MAX_FILESIZE:-100M}"
num="$(printf '%s' "$UPLOAD_MAX_FILESIZE" | tr -dc '0-9')"
unit="$(printf '%s' "$UPLOAD_MAX_FILESIZE" | tr -dc 'A-Za-z')"
if [ -z "$num" ]; then
    echo "[entrypoint] WARN: invalid UPLOAD_MAX_FILESIZE='$UPLOAD_MAX_FILESIZE', fallback 100M" >&2
    UPLOAD_MAX_FILESIZE="100M"; num="100"; unit="M"
fi
POST_MAX_SIZE="$(( num + 5 ))${unit}"

cat > /etc/php85/conf.d/20-upload.ini <<EOF
upload_max_filesize = ${UPLOAD_MAX_FILESIZE}
post_max_size = ${POST_MAX_SIZE}
memory_limit = ${PHP_MEMORY_LIMIT:-256M}
max_execution_time = ${PHP_MAX_EXECUTION_TIME:-300}
max_input_time = ${PHP_MAX_INPUT_TIME:-300}
EOF

sed -i "s/client_max_body_size .*/client_max_body_size ${POST_MAX_SIZE};/" /etc/nginx/nginx.conf
echo "[entrypoint] upload_max_filesize=${UPLOAD_MAX_FILESIZE} post_max_size=${POST_MAX_SIZE}"

exec runsvdir /etc/service
