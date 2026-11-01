#!/usr/bin/env bash
# Usage: docker/production.sh <compose arguments...>
set -euo pipefail
ROOT=$(realpath "$(dirname "${BASH_SOURCE[0]}")/..")
exec docker compose --env-file "${PRODUCTION_ENV_FILE:-$ROOT/docker/.env.production}" -f "$ROOT/docker/compose.production.yml" "$@"
