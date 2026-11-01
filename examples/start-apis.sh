#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PIDS_DIR="$ROOT_DIR/examples/.pids"
LOGS_DIR="$ROOT_DIR/examples/.logs"

mkdir -p "$PIDS_DIR"
mkdir -p "$LOGS_DIR"

port_in_use() {
  (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null
}

start_api() {
  local name="$1"
  local port="$2"
  local dir="$3"
  local pid_file="$PIDS_DIR/$name.pid"
  local log_file="$LOGS_DIR/$name.log"

  if [[ -f "$pid_file" ]]; then
    local old_pid
    old_pid="$(cat "$pid_file")"
    if kill -0 "$old_pid" 2>/dev/null; then
      echo "$name already running on :$port (pid=$old_pid)"
      return
    fi
    rm -f "$pid_file"
  fi

  if port_in_use "$port"; then
    echo "$name not started: port $port is already in use" >&2
    return
  fi

  (
    cd "$dir"
    nohup php -S "127.0.0.1:$port" server.php >"$log_file" 2>&1 &
    echo $! >"$pid_file"
  )

  echo "Started $name on :$port"
}

start_api "blog-api" "9101" "$ROOT_DIR/examples/blog-api"
start_api "shop-api" "9102" "$ROOT_DIR/examples/shop-api"
start_api "oauth-idp-api" "9201" "$ROOT_DIR/examples/oauth-idp-api"
start_api "session-api" "9202" "$ROOT_DIR/examples/session-api"
