#!/usr/bin/env bash
# Restore an isolated stack or deliberately roll back a quiescent deployment.
set -euo pipefail
ROOT=$(realpath "$(dirname "${BASH_SOURCE[0]}")/..")
BACKUP_DIR=${1:?Supply the backup directory}
test -s "$BACKUP_DIR/database.dump"
test -s "$BACKUP_DIR/state.tar.gz"
test -s "$BACKUP_DIR/redis.tar.gz"
COMPOSE=(bash "$ROOT/docker/production.sh")
"${COMPOSE[@]}" stop web queue scheduler api-admin-fpm redis
"${COMPOSE[@]}" exec -T postgres pg_restore -U moonshine_v -d moonshine_v --clean --if-exists --exit-on-error < "$BACKUP_DIR/database.dump"
"${COMPOSE[@]}" run --rm --no-deps -T --user root init sh docker/restore-state.sh < "$BACKUP_DIR/state.tar.gz"
"${COMPOSE[@]}" run --rm --no-deps -T --entrypoint sh redis -ec 'find /data -mindepth 1 -maxdepth 1 -exec rm -rf {} +
tar -C /data -xzf -
chown -R redis:redis /data' < "$BACKUP_DIR/redis.tar.gz"
"${COMPOSE[@]}" up -d --wait redis
"${COMPOSE[@]}" up -d --wait api-admin-fpm queue scheduler web
echo "Restore complete. Verify credentials, generated panels and queued jobs before opening ingress."
