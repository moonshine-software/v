#!/usr/bin/env bash
# Isolated PostgreSQL/Redis/FPM/web persistence and restore test. Never point at production.
set -euo pipefail
ROOT=$(realpath "$(dirname "${BASH_SOURCE[0]}")/..")
SMOKE_DIR=$(mktemp -d)
export PRODUCTION_ENV_FILE="$SMOKE_DIR/environment"
SMOKE_PROJECT="moonshine-smoke-${RANDOM}-${RANDOM}"
umask 077
cat > "$PRODUCTION_ENV_FILE" <<EOF
COMPOSE_PROJECT_NAME=$SMOKE_PROJECT
APP_IMAGE=${APP_IMAGE:-moonshine-v:production-smoke}
WEB_IMAGE=${WEB_IMAGE:-moonshine-v:web-smoke}
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=${SMOKE_APP_URL:-https://localhost}
APP_PORT=${SMOKE_PORT:-18080}
DB_PASSWORD=$(openssl rand -hex 24)
REDIS_PASSWORD=$(openssl rand -hex 24)
EOF
COMPOSE=(bash "$ROOT/docker/production.sh")
cleanup() {
    "${COMPOSE[@]}" logs --no-color > "$SMOKE_DIR/compose.log"
    if [ "${KEEP_SMOKE_STACK:-0}" = '1' ]
    then
        echo "Stack retained for inspection. Environment: $PRODUCTION_ENV_FILE"
    else
        "${COMPOSE[@]}" down --volumes --remove-orphans
    fi
    echo "Smoke logs: $SMOKE_DIR/compose.log"
}
trap cleanup EXIT
"${COMPOSE[@]}" up -d --wait --wait-timeout 180
curl --fail --silent "http://127.0.0.1:${SMOKE_PORT:-18080}/up" > /dev/null
curl --fail --silent "http://127.0.0.1:${SMOKE_PORT:-18080}/admin/login" > "$SMOKE_DIR/login.html"
grep -q 'moonshine' "$SMOKE_DIR/login.html"
"${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php queue
"${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php write
PUBLIC_CONTENT=$(curl --fail --silent "http://127.0.0.1:${SMOKE_PORT:-18080}/storage/release-smoke.txt")
test "$PUBLIC_CONTENT" = 'public upload'
curl --fail --silent "http://127.0.0.1:${SMOKE_PORT:-18080}/storage/release-smoke.png" > "$SMOKE_DIR/upload.png"
test -s "$SMOKE_DIR/upload.png"
PRIVATE_STATUS=$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${SMOKE_PORT:-18080}/storage/.env")
test "$PRIVATE_STATUS" = '403'
PHP_STATUS=$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${SMOKE_PORT:-18080}/storage/release-smoke.php")
test "$PHP_STATUS" = '404'
"${COMPOSE[@]}" exec -T web test ! -e /var/www/html/storage/runtime/.env
"${COMPOSE[@]}" up -d --wait --force-recreate api-admin-fpm queue scheduler web
"${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php check
# Queue work must resume after a graceful worker recreation as well.
"${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php queue
bash "$ROOT/docker/backup.sh" "$SMOKE_DIR/backup"
"${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php damage
if "${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php check > "$SMOKE_DIR/expected-damage.log" 2>&1
then
    echo 'Damaged state incorrectly passed verification' >&2
    exit 1
fi
bash "$ROOT/docker/restore.sh" "$SMOKE_DIR/backup"
"${COMPOSE[@]}" exec -T api-admin-fpm php docker/smoke-state.php check
# A stopped backend must fail readiness, even though PHP/FPM is still running.
RESTORED_UPLOAD=$(curl --fail --silent "http://127.0.0.1:${SMOKE_PORT:-18080}/storage/release-smoke.txt")
test "$RESTORED_UPLOAD" = 'public upload'
"${COMPOSE[@]}" stop redis
if "${COMPOSE[@]}" exec -T api-admin-fpm sh docker/healthcheck.sh
then
    echo 'Readiness incorrectly passed with Redis stopped' >&2
    exit 1
fi
HTTP_STATUS=$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${SMOKE_PORT:-18080}/ready")
test "$HTTP_STATUS" = '503'
"${COMPOSE[@]}" up -d --wait redis
"${COMPOSE[@]}" exec -T api-admin-fpm sh docker/healthcheck.sh
echo 'Production smoke passed: PostgreSQL, Redis, HTTP, generated state, recreate, backup/restore, backend outage.'
