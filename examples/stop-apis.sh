#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PIDS_DIR="$ROOT_DIR/examples/.pids"

stop_api() {
  local name="$1"
  local pid_file="$PIDS_DIR/$name.pid"

  if [[ ! -f "$pid_file" ]]; then
    echo "$name is not running"
    return
  fi

  local pid
  pid="$(cat "$pid_file")"
  if kill -0 "$pid" 2>/dev/null; then
    kill "$pid"
    echo "Stopped $name (pid=$pid)"
  else
    echo "$name pid file found, but process is not running"
  fi

  rm -f "$pid_file"
}

stop_api "blog-api"
stop_api "shop-api"
stop_api "oauth-idp-api"
stop_api "session-api"
