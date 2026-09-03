#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/serve.sh — start the dev server with full preflight checks.
# scripts/serve.sh — inicia el servidor de desarrollo con verificaciones previas.
#
# Usage:  ./run serve                 # 127.0.0.1:8000
#         ./run serve 8080            # custom port
#         ./run serve --host=0.0.0.0  # listen on all interfaces
# Env:    B2B_SERVE_PORT, B2B_SERVE_HOST (defaults: 8000, 127.0.0.1)
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

URL="http://${HOST}:${PORT}"
printf '%b\n' ""
printf '%b\n' "${C_BOLD}Presence Platform / Plataforma de Presencia${C_RESET}  ${C_DIM}$(php_version_string "$PHP_BIN") · ${PHP_BIN_SOURCE}${C_RESET}"
printf '%b\n' "  ${C_GREEN}➜${C_RESET} App:    ${URL}"
printf '%b\n' "  ${C_GREEN}➜${C_RESET} Health: ${URL}/up   ${C_DIM}(Laravel health route)${C_RESET}"
printf '%b\n' "  ${C_GREEN}➜${C_RESET} Login:  ${URL}/login ${C_DIM}admin@presence.test · password${C_RESET}"
printf '%b\n' "  ${C_DIM}Stop: Ctrl+C · Docs: docs/SCRIPTS.md (EN/ES) · Diagnóstico: ./run doctor${C_RESET}"
printf '%b\n' ""

# exec so Ctrl+C / signals reach the server process directly
exec "$PHP_BIN" artisan serve --host="$HOST" --port="$PORT"
