#!/usr/bin/env bash
# Usage: docker/production.sh <compose arguments...>
set -euo pipefail
ROOT=$(realpath "$(dirname "${BASH_SOURCE[0]}")/..")
COMPOSE=(docker compose --env-file "${PRODUCTION_ENV_FILE:-$ROOT/docker/.env.production}" -f "$ROOT/docker/compose.production.yml")
if [ -n "${PRODUCTION_COMPOSE_OVERRIDE:-}" ]
then
    COMPOSE+=(-f "$PRODUCTION_COMPOSE_OVERRIDE")
    echo '[FIX:installer-backup] Applying Compose override.' >&2
fi
exec "${COMPOSE[@]}" "$@"
