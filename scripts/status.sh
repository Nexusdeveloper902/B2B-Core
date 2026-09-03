#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/status.sh — app state at a glance (informational; always exits 0).
# scripts/status.sh — estado de la app de un vistazo (informativo; sale 0).
#
# Usage:  ./run status
#
# Shows / Muestra: OS + distro, resolved PHP/Composer, .env + APP_KEY,
# classifier driver, database, app-server health (/up), model-server health,
# and the ./run commands that fix whatever is off.
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

row()  { printf '  %-14s %b\n' "$1:" "$2"; }
up()   { printf '%s\n' "${C_GREEN}running${C_RESET}"; }
down() { printf '%s\n' "${C_RED}not running${C_RESET}"; }

detect_distro
resolve_php report
resolve_composer report
printf '%b\n' "${C_BOLD}Presence Platform — status${C_RESET}"

if is_windows; then
    row "OS" "Windows (Git Bash fallback) · B2B_OS=windows"
else
    row "OS" "${DISTRO_LABEL} (${DISTRO_FAMILY}) · B2B_OS=${B2B_OS:-auto}"
fi
row "PHP"     "${PHP_BIN:-$(printf '%b' "${C_DIM}unresolved — ./run doctor${C_RESET}")}"
[ -n "${PHP_BIN:-}" ] && row "PHP source" "${PHP_BIN_SOURCE} · $(php_version_string "$PHP_BIN")"
row "Composer" "${COMPOSER_BIN:-$(printf '%b' "${C_DIM}unresolved — ./run toolchain${C_RESET}")}"

# .env / key / driver
if [ -f "$B2B_ROOT/.env" ]; then
    row ".env" "present"
    if app_key_set; then row "APP_KEY" "set"; else row "APP_KEY" "${C_RED}EMPTY — ./run setup${C_RESET}"; fi
    row "APP_ENV"    "$(env_value APP_ENV)"
    row "APP_URL"    "$(env_value APP_URL)"
    row "classifier" "$(env_value RECYCLING_CLASSIFIER_DRIVER) ${C_DIM}(stub | local | gemini)${C_RESET}"
else
    row ".env" "${C_RED}missing — ./run setup${C_RESET}"
fi

# Database
DB_FILE="$B2B_ROOT/database/database.sqlite"
if [ -f "$DB_FILE" ]; then
    row "database" "database/database.sqlite ($(du -h "$DB_FILE" | cut -f1))"
else
    row "database" "${C_YELLOW}no dev DB yet — ./run setup${C_RESET}"
fi
if [ -d "$B2B_ROOT/vendor" ]; then row "vendor" "present"; else row "vendor" "${C_YELLOW}missing — ./run setup${C_RESET}"; fi

# App server health
APP_URL="$(env_value APP_URL)"
[ -z "$APP_URL" ] && APP_URL="http://127.0.0.1:8000"
if http_probe "${APP_URL%/}/up"; then
    row "app server" "$(up) at ${APP_URL}"
else
    row "app server" "$(down) — start with: ./run serve"
fi

# Model server health
MODEL_URL="http://127.0.0.1:${B2B_MODEL_PORT:-8501}/healthz"
if http_probe "$MODEL_URL"; then
    row "model server" "$(up) at 127.0.0.1:${B2B_MODEL_PORT:-8501}"
else
    row "model server" "$(down) — start with: ./run model start"
fi

printf '%b\n' "${C_DIM}  Fixes: ./run doctor · ./run setup · ./run serve · ./run model start${C_RESET}"
exit 0
