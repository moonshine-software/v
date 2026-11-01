#!/usr/bin/env bash
# Quiescent backup. The external env/APP_KEY must be escrowed separately.
set -euo pipefail
ROOT=$(realpath "$(dirname "${BASH_SOURCE[0]}")/..")
BACKUP_DIR=${1:?Supply a new backup directory}
umask 077
mkdir "$BACKUP_DIR"
COMPOSE=(bash "$ROOT/docker/production.sh")
"${COMPOSE[@]}" stop web queue scheduler api-admin-fpm
# Failure deliberately leaves writers stopped until the operator investigates.
"${COMPOSE[@]}" exec -T postgres pg_dump -U api_admin -d api_admin -Fc > "$BACKUP_DIR/database.dump"
"${COMPOSE[@]}" run --rm --no-deps -T migrate tar -C /var/www/html/storage -czf - . > "$BACKUP_DIR/state.tar.gz"
"${COMPOSE[@]}" exec -T redis redis-cli SAVE
"${COMPOSE[@]}" stop redis
"${COMPOSE[@]}" run --rm --no-deps -T --entrypoint tar redis -C /data -czf - . > "$BACKUP_DIR/redis.tar.gz"
"${COMPOSE[@]}" images --format json > "$BACKUP_DIR/images.json"
"${COMPOSE[@]}" up -d --wait api-admin-fpm queue scheduler web
echo "Backup created at $BACKUP_DIR. Escrow APP_KEY and deployment environment separately."
