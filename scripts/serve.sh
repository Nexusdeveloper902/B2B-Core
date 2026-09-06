#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/serve.sh — start the dev server (web + realtime feed) with preflights.
# scripts/serve.sh — inicia el servidor de desarrollo (web + canal en vivo) con verificaciones.
#
# Usage:  ./run serve                 # 127.0.0.1:8000 (web) + :8081 (realtime)
#         ./run serve 8080            # custom web port (realtime stays 8081)
#         ./run serve --host=0.0.0.0  # listen on all interfaces (LAN phones)
# Env:    B2B_SERVE_PORT, B2B_SERVE_HOST (defaults: 8000, 127.0.0.1)
#         B2B_REALTIME_PORT  realtime feed port (default: 8081)
#         B2B_REALTIME=0     start WITHOUT the realtime feed server
#
# TASK-016 — the realtime WebSocket server (php artisan realtime:serve)
# starts in the background next to `artisan serve`: the dashboards'
# live-activity panels go LIVE instead of needing F5. Ctrl+C tears both
# down. If the realtime port is busy the web server still starts and the
# dashboard honestly shows the offline badge (graceful degrade).
#
# Fails BEFORE binding (not at request time) when setup is incomplete, and
# prints exactly which ./run command fixes it (ADR-011).
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

PORT="${B2B_SERVE_PORT:-8000}"
HOST="${B2B_SERVE_HOST:-127.0.0.1}"
for arg in "$@"; do
    case "$arg" in
        --help|-h) help_header "$0"; exit 0 ;;
        --host=*)  HOST="${arg#--host=}" ;;
        --port=*)  PORT="${arg#--port=}" ;;
        ''|*[!0-9]*) die "Port must be a number: '$arg' / El puerto debe ser un número: '$arg'" ;;
        *)         PORT="$arg" ;;
    esac
done

# --- Preflight: verify-then-act ---------------------------------------------------
resolve_php
[ -d "$B2B_ROOT/vendor" ]      || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key
if [ "$(env_value DB_CONNECTION)" != "mysql" ] && [ "$(env_value DB_CONNECTION)" != "pgsql" ]; then
    [ -f "$B2B_ROOT/database/database.sqlite" ] || die "database/database.sqlite missing — run: ./run setup / falta la BD — ejecuta: ./run setup"
fi

# --- TASK-016: realtime feed server (background child) ----------------------------
WS_PORT="${B2B_REALTIME_PORT:-8081}"
WS_ENABLED="${B2B_REALTIME:-1}"
WS_PID=""
WEB_PID=""

# port_open <port> — exit 0 when something is already listening on 127.0.0.1:<port>.
# Uses the resolved PHP (portable: no /dev/tcp, no ss, works on Git Bash too).
port_open() {
    "$PHP_BIN" -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $errno, $errstr, 0.5) === false ? 1 : 0);' "$1" 2>/dev/null
}

cleanup() {
    [ -n "$WEB_PID" ] && kill "$WEB_PID" 2>/dev/null || true
    [ -n "$WS_PID" ]  && kill "$WS_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

start_realtime() {
    [ "$WS_ENABLED" = "1" ] || { warn "Realtime disabled (B2B_REALTIME=0) — dashboards show offline / Realtime desactivado — el panel mostrará «Desconectado»"; return 1; }
    if port_open "$WS_PORT"; then
        warn "Realtime port ${WS_PORT} already busy — skipping (dashboard will show offline) / Puerto ${WS_PORT} ocupado — se omite (el panel mostrará «Desconectado»)"
        return 1
    fi

    "$PHP_BIN" artisan realtime:serve --host="$HOST" --port="$WS_PORT" \
        >>"$B2B_ROOT/storage/logs/realtime.log" 2>&1 &
    WS_PID=$!

    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        port_open "$WS_PORT" && break
        kill -0 "$WS_PID" 2>/dev/null || break   # server process died (check storage/logs/realtime.log)
        sleep 0.3
    done
    if port_open "$WS_PORT"; then
        return 0
    fi
    kill "$WS_PID" 2>/dev/null || true
    WS_PID=""
    warn "Realtime server did not come up — dashboards will show offline (see storage/logs/realtime.log) / El servidor en vivo no arrancó — el panel mostrará «Desconectado» (ver storage/logs/realtime.log)"
    return 1
}

URL="http://${HOST}:${PORT}"
printf '%b\n' ""
printf '%b\n' "${C_BOLD}Presence Platform / Plataforma de Presencia${C_RESET}  ${C_DIM}$(php_version_string "$PHP_BIN") · ${PHP_BIN_SOURCE}${C_RESET}"
printf '%b\n' "  ${C_GREEN}➜${C_RESET} App:    ${URL}"
printf '%b\n' "  ${C_GREEN}➜${C_RESET} Health: ${URL}/up   ${C_DIM}(Laravel health route)${C_RESET}"
printf '%b\n' "  ${C_GREEN}➜${C_RESET} Login:  ${URL}/login ${C_DIM}admin@presence.test · password${C_RESET}"

if start_realtime; then
    printf '%b\n' "  ${C_GREEN}➜${C_RESET} Live:   ws://${HOST}:${WS_PORT}  ${C_DIM}(realtime feed — dashboards update without F5 / el panel se actualiza sin F5)${C_RESET}"
fi

printf '%b\n' "  ${C_DIM}Stop: Ctrl+C · Docs: docs/SCRIPTS.md (EN/ES) · Diagnóstico: ./run doctor${C_RESET}"
printf '%b\n' ""

# Foreground (NOT exec) so the EXIT trap can tear both servers down.
"$PHP_BIN" artisan serve --host="$HOST" --port="$PORT" &
WEB_PID=$!
wait "$WEB_PID"
