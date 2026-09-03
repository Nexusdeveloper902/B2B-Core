#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/_lib/common.sh — shared library for the Presence Platform run suite.
# Biblioteca compartida para la suite de ejecución de Presence Platform.
#
# Sourced by `run` and every scripts/*.sh — NEVER executed directly.
# No lowercased `php` call may appear anywhere in the suite: every invocation
# goes through "$PHP_BIN" resolved here (ADR-010 resolution chain).
#
# Environment overrides (documented in docs/SCRIPTS.md):
#   B2B_PHP                  force a PHP binary path (must be >= 8.3 + exts)
#   B2B_COMPOSER             force a Composer binary path
#   B2B_STATIC_PHP_VERSION   pin the hermetic static-PHP version
#   B2B_SERVE_PORT/HOST      defaults for `run serve`
#   B2B_MODEL_PORT           default port for `run model`
#   NO_COLOR                 disable colored output
# ---------------------------------------------------------------------------
# shellcheck shell=bash
# shellcheck disable=SC2034  # shared library exports many "unused" vars

# --- Project root ------------------------------------------------------------
# common.sh lives at <root>/scripts/_lib/common.sh — derive root from its own
# location so every script works from any CWD, then cd there once.
_B2B_LIB_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
B2B_ROOT="$(cd -- "${_B2B_LIB_DIR}/../.." && pwd -P)"
cd -- "$B2B_ROOT" || { echo "fatal: cannot cd to $B2B_ROOT" >&2; exit 1; }

# --- Output helpers ------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET='\033[0m'; C_BOLD='\033[1m'; C_DIM='\033[2m'
    C_RED='\033[0;31m'; C_GREEN='\033[0;32m'; C_YELLOW='\033[0;33m'; C_BLUE='\033[0;34m'
else
    C_RESET=''; C_BOLD=''; C_DIM=''
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi

log()   { printf '%b\n' "${C_BLUE}==>${C_RESET} ${*}"; }
ok()    { printf '%b\n' "  ${C_GREEN}✔${C_RESET} ${*}"; }
warn()  { printf '%b\n' "  ${C_YELLOW}⚠${C_RESET} ${*}"; }
err()   { printf '%b\n' "  ${C_RED}✘${C_RESET} ${*}" >&2; }
die()   { err "${*}"; exit 1; }

# Bilingual line: English / Español on one line (project output convention).
bi() { printf '%b\n' "${1} / ${C_DIM}${2}${C_RESET}"; }

# --- OS / distro detection (Arch first) -----------------------------------------
# Sets DISTRO_ID, DISTRO_ID_LIKE, DISTRO_FAMILY (arch | debian | fedora | suse
# | unknown) and DISTRO_LABEL (human-readable).
detect_distro() {
    DISTRO_ID=""; DISTRO_ID_LIKE=""; DISTRO_FAMILY="unknown"; DISTRO_LABEL="unknown"
    [ -r /etc/os-release ] || return 0
    # shellcheck disable=SC1091
    . /etc/os-release
    DISTRO_ID="${ID:-}"
    DISTRO_ID_LIKE="${ID_LIKE:-}"
    DISTRO_LABEL="${PRETTY_NAME:-${DISTRO_ID:-unknown}}"
    local ids="${DISTRO_ID} ${DISTRO_ID_LIKE}"
    case "$ids" in
        *arch*|*cachyos*|*endeavour*|*manjaro*|*garuda*) DISTRO_FAMILY="arch" ;;
        *debian*|*ubuntu*|*mint*|*pop*)                  DISTRO_FAMILY="debian" ;;
        *fedora*|*rhel*|*rocky*|*almalinux*|*centos*)    DISTRO_FAMILY="fedora" ;;
        *suse*)                                          DISTRO_FAMILY="suse" ;;
    esac
}

# --- PHP / Composer resolution (ADR-010) ---------------------------------------
# Modules required by composer.json + Laravel 13 + PHPUnit 12. Kept in ONE
# place; tests/Unit/ScriptSuiteTest.php cross-checks this list against the
# CI extension list (drift fails the build).
B2B_PHP_MIN_VERSION="8.3"
PHP_REQUIRED_MODULES=(
    ctype curl dom fileinfo libxml mbstring openssl
    pdo_sqlite session sqlite3 tokenizer xml xmlwriter zip
)

# Candidate interpreters, in resolution order: env override -> PATH -> .tools
php_candidates() {
    if [ -n "${B2B_PHP:-}" ]; then printf '%s\n' "$B2B_PHP"; fi
    local p
    p="$(command -v php 2>/dev/null || true)"
    [ -n "$p" ] && printf '%s\n' "$p"
    [ -x "$B2B_ROOT/.tools/php" ] && printf '%s\n' "$B2B_ROOT/.tools/php"
    return 0
}

composer_candidates() {
    if [ -n "${B2B_COMPOSER:-}" ]; then printf '%s\n' "$B2B_COMPOSER"; fi
    local c
    c="$(command -v composer 2>/dev/null || true)"
    [ -n "$c" ] && printf '%s\n' "$c"
    [ -x "$B2B_ROOT/.tools/composer" ] && printf '%s\n' "$B2B_ROOT/.tools/composer"
    return 0
}

# php_version_ok <bin> — exit 0 when >= B2B_PHP_MIN_VERSION
php_version_ok() {
    "$1" -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' \
        "${B2B_PHP_MIN_VERSION}" >/dev/null 2>&1
}

# php_version_string <bin> — prints e.g. "PHP 8.4.23 (cli)"
php_version_string() { "$1" -r 'echo "PHP ", PHP_VERSION, " (cli)";' 2>/dev/null; }

# php_missing_modules <bin> — PURE: prints the required modules the binary
# lacks (nothing when complete). Never sets globals — safe inside $(…).
php_missing_modules() {
    local have m missing=""
    have="$("$1" -m 2>/dev/null | tr '[:upper:]' '[:lower:]' | sort -u)" || {
        printf '%s\n' "${PHP_REQUIRED_MODULES[@]}"
        return 0
    }
    for m in "${PHP_REQUIRED_MODULES[@]}"; do
        grep -qxF "$m" <<<"$have" || missing+="$m "
    done
    [ -n "$missing" ] && printf '%s\n' "${missing% }"
    return 0
}

# php_valid <bin> — exit 0 when the binary satisfies version + modules.
# Sets PHP_FAIL_REASON in the CALLER'S shell (must be called directly, not
# inside a command substitution) so diagnostics survive set -u.
php_valid() {
    PHP_FAIL_REASON=""
    if [ ! -x "$1" ] || ! "$1" -r 'echo PHP_VERSION;' >/dev/null 2>&1; then
        PHP_FAIL_REASON="binary is not executable"
        return 1
    fi
    if ! php_version_ok "$1"; then
        PHP_FAIL_REASON="$(php_version_string "$1") is below the required ${B2B_PHP_MIN_VERSION}"
        return 1
    fi
    local missing
    missing="$(php_missing_modules "$1")"
    if [ -n "$missing" ]; then
        PHP_FAIL_REASON="missing extensions: ${missing}"
        return 1
    fi
    return 0
}

# resolve_php [strict|report]
#   strict (default): die with actionable bilingual help when nothing valid.
#   report: never die; sets PHP_BIN="" when no valid candidate.
# On success sets PHP_BIN + PHP_BIN_SOURCE ("override"|"system"|"hermetic").
resolve_php() {
    local mode="${1:-strict}" c
    PHP_BIN=""; PHP_BIN_SOURCE=""
    while IFS= read -r c; do
        [ -n "$c" ] || continue
        if php_valid "$c"; then
            PHP_BIN="$c"
            case "$c" in
                "$B2B_ROOT/.tools/"*) PHP_BIN_SOURCE="hermetic (.tools/)" ;;
                "${B2B_PHP:-__none__}") PHP_BIN_SOURCE="override (B2B_PHP)" ;;
                *) PHP_BIN_SOURCE="system (PATH)" ;;
            esac
            return 0
        fi
        if [ "$c" = "${B2B_PHP:-__none__}" ]; then
            warn "B2B_PHP=$c is not usable ($PHP_FAIL_REASON) — ignoring it."
        fi
    done < <(php_candidates)

    if [ "$mode" = "report" ]; then return 0; fi

    err "No usable PHP found (need >= ${B2B_PHP_MIN_VERSION} + extensions)."
    err "No se encontró un PHP utilizable (se requiere >= ${B2B_PHP_MIN_VERSION} + extensiones)."
    detect_distro
    case "$DISTRO_FAMILY" in
        arch)
            err "On Arch install PHP and enable extensions:"
            err "En Arch instala PHP y habilita las extensiones:"
            err "  sudo pacman -S --needed php"
            err "  sudo sed -ri 's/^;(extension=(curl|fileinfo|mbstring|pdo_sqlite|sqlite3|zip))$/extension=\\1/' /etc/php/php.ini"
            ;;
        debian)
            err "On Debian/Ubuntu: sudo apt-get install php-cli php-sqlite3 php-curl php-mbstring php-xml php-zip"
            err "En Debian/Ubuntu: sudo apt-get install php-cli php-sqlite3 php-curl php-mbstring php-xml php-zip" ;;
        fedora)
            err "On Fedora/RHEL: sudo dnf install php-cli php-pdo php-mbstring php-xml php-zip curl"
            err "En Fedora/RHEL: sudo dnf install php-cli php-pdo php-mbstring php-xml php-zip curl" ;;
        *)
            err "Install PHP ${B2B_PHP_MIN_VERSION}+ with: pdo_sqlite sqlite3 mbstring curl dom xml xmlwriter zip fileinfo"
            err "Instala PHP ${B2B_PHP_MIN_VERSION}+ con: pdo_sqlite sqlite3 mbstring curl dom xml xmlwriter zip fileinfo" ;;
    esac
    err "…or run the hermetic zero-system toolchain: ./run toolchain"
    err "…o usa el toolchain hermético sin dependencias: ./run toolchain"
    die "Full diagnostics: ./run doctor / Diagnóstico completo: ./run doctor"
}

# resolve_composer — like resolve_php for Composer. IMPORTANT: Composer is a
# PHP phar, so it is validated AND invoked through the already-resolved
# "$PHP_BIN" — it must work on machines with no php on PATH at all.
# Call resolve_php first.
resolve_composer() {
    local mode="${1:-strict}" c
    COMPOSER_BIN=""
    if [ -z "${PHP_BIN:-}" ]; then
        if [ "$mode" = "report" ]; then return 0; fi
        die "resolve_php must run before resolve_composer / resolve_php debe ejecutarse antes de resolve_composer"
    fi
    while IFS= read -r c; do
        [ -n "$c" ] || continue
        if [ -f "$c" ] && "$PHP_BIN" "$c" --version >/dev/null 2>&1; then
            COMPOSER_BIN="$c"
            case "$c" in
                "$B2B_ROOT/.tools/"*) COMPOSER_BIN_SOURCE="hermetic (.tools/)" ;;
                "${B2B_COMPOSER:-__none__}") COMPOSER_BIN_SOURCE="override (B2B_COMPOSER)" ;;
                *) COMPOSER_BIN_SOURCE="system (PATH)" ;;
            esac
            return 0
        fi
    done < <(composer_candidates)
    if [ "$mode" = "report" ]; then return 0; fi
    die "No usable Composer found — run ./run toolchain to provision one.
No se encontró Composer — ejecuta ./run toolchain para instalar uno."
}

# composer_cmd <args…> — the ONLY sanctioned way to invoke Composer (goes
# through the resolved PHP so hermetic .tools/composer works everywhere).
composer_cmd() { "$PHP_BIN" "$COMPOSER_BIN" "$@"; }

# --- Laravel env helpers ---------------------------------------------------------
env_file_has()   { [ -f "$B2B_ROOT/.env" ] && grep -qE "^${1}=" "$B2B_ROOT/.env"; }

# env_value KEY — prints the value from .env ("" when absent); strips quotes.
env_value() {
    [ -f "$B2B_ROOT/.env" ] || return 0
    local v
    v="$(sed -n "s/^${1}=//p" "$B2B_ROOT/.env" | head -n 1)"
    v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
    printf '%s\n' "$v"
}

app_key_set() {
    [ -f "$B2B_ROOT/.env" ] || return 1
    grep -qE '^APP_KEY=base64:' "$B2B_ROOT/.env"
}

# ensure_env_and_key — idempotent: create .env from .env.example when absent
# (NEVER overwrite an existing one) and generate APP_KEY when empty.
# Requires a resolved PHP + vendor/ (artisan key:generate).
ensure_env_and_key() {
    if [ ! -f "$B2B_ROOT/.env" ]; then
        [ -f "$B2B_ROOT/.env.example" ] || die ".env.example missing — repository incomplete / falta .env.example"
        cp "$B2B_ROOT/.env.example" "$B2B_ROOT/.env"
        log "Created .env from .env.example / .env creado desde .env.example"
    fi
    if ! app_key_set; then
        [ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run ./run setup first / falta vendor/ — ejecuta ./run setup"
        "$PHP_BIN" artisan key:generate --force >/dev/null
        log "Generated APP_KEY / APP_KEY generada"
    fi
}

# --- Misc helpers ------------------------------------------------------------------
http_probe() { curl -sf -m 3 -o /dev/null "$1" 2>/dev/null; }

# wait_for_http <url> <timeout_seconds> [label] — exit 1 on timeout.
wait_for_http() {
    local url="$1" timeout="${2:-15}" label="${3:-$1}" i
    for ((i = 1; i <= timeout; i++)); do
        http_probe "$url" && return 0
        sleep 1
    done
    err "Timed out waiting for ${label} / Se agotó el tiempo esperando ${label}"
    return 1
}

# help_header <file> — print a script's leading comment block (its docs):
# skips the shebang, prints every '#' line (stripping the marker), stops at
# the first non-comment line. Used by every --help handler.
help_header() {
    awk 'NR==1 {next} !/^#/ {exit} {sub(/^# ?/, ""); print}' "$1"
}

confirm() {  # confirm "question" — yes/no, default No
    local reply
    read -r -p "$1 [y/N] " reply </dev/tty || reply="n"
    [[ "$reply" =~ ^[Yy](es)?$ ]]
}
