#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/doctor.sh — environment & toolchain diagnostics for the run suite.
# scripts/doctor.sh — diagnóstico de entorno y toolchain para la run suite.
#
# Works on machines with NO PHP installed (it is the tool that tells you how
# to fix that). Arch-aware: prints exact pacman + php.ini remediation.
#
# Usage:   ./run doctor          |  bash scripts/doctor.sh
# Exit:    0 = everything green · 1 = something needs fixing (see output)
#
# What it checks / Qué revisa:
#   1. OS + distro family (Arch/Debian/Fedora/…), bash, curl, git, python3
#   2. Every PHP candidate (B2B_PHP, PATH php, .tools/php): version + modules
#   3. Every Composer candidate
#   4. Project state: .env, APP_KEY, vendor/, sqlite file, migrations table
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ]; then
    help_header "$0"
    exit 0
fi

PASS=0; FAIL=0
note()  { printf '%b\n' "  ${*}"; }
pass()  { ok "$*";  PASS=$((PASS + 1)); }
fail()  { err "$*"; FAIL=$((FAIL + 1)); }

log "Doctor — Presence Platform environment check / Revisión del entorno"
detect_distro
printf '%b\n' "  OS: $(uname -s) $(uname -m) · Distro: ${C_BOLD}${DISTRO_LABEL}${C_RESET} (${DISTRO_FAMILY} family)"

# --- 1. Base tools -------------------------------------------------------------
for tool in bash curl git; do
    if command -v "$tool" >/dev/null 2>&1; then
        pass "${tool}: $(command -v "$tool")"
    else
        fail "${tool}: NOT FOUND — needed by the run suite / no encontrado — lo necesita la suite"
    fi
done
if command -v python3 >/dev/null 2>&1; then
    pass "python3: $(python3 --version 2>&1) — needed by ./run model / necesario para ./run model"
else
    warn "python3: not found — only needed for the local model server / solo se necesita para el servidor de modelo local"
fi

# --- 2. PHP candidates -----------------------------------------------------------
log "PHP candidates / Candidatos de PHP (need >= ${B2B_PHP_MIN_VERSION} + ${#PHP_REQUIRED_MODULES[@]} modules)"
PHP_FOUND=0
while IFS= read -r c; do
    [ -n "$c" ] || continue
    if [ ! -x "$c" ]; then
        note "  ${C_DIM}${c}: not present / no presente${C_RESET}"
        continue
    fi
    if php_valid "$c"; then
        pass "${c} → $(php_version_string "$c") — all modules present / todos los módulos presentes"
        PHP_FOUND=1
    else
        fail "${c} → ${PHP_FAIL_REASON}"
        local_missing="$(php_missing_modules "$c")"
        if [ -n "$local_missing" ]; then
            note "    ${C_DIM}missing / faltan: ${local_missing}${C_RESET}"
        fi
    fi
done < <(php_candidates)

if [ "$PHP_FOUND" -eq 0 ]; then
    fail "No usable PHP / Ningún PHP utilizable"
    detect_distro
    if [ "$DISTRO_FAMILY" = "arch" ]; then
        note "  ${C_BOLD}Arch remediation / Remediación en Arch:${C_RESET}"
        note "    sudo pacman -S --needed php composer"
        note "    sudo sed -ri 's/^;(extension=(curl|fileinfo|mbstring|pdo_sqlite|sqlite3|zip))$/extension=\\1/' /etc/php/php.ini"
    fi
    note "  ${C_BOLD}Or the zero-system hermetic toolchain / O el toolchain hermético sin dependencias:${C_RESET}"
    note "    ./run toolchain"
fi

# --- 3. Composer candidates --------------------------------------------------------
log "Composer candidates / Candidatos de Composer"
resolve_php report   # set PHP_BIN (quiet) for phar validation, if any PHP is valid
COMPOSER_FOUND=0
if [ -z "${PHP_BIN:-}" ]; then
    note "  ${C_DIM}cannot validate Composer without a usable PHP first / no se puede validar Composer sin un PHP utilizable${C_RESET}"
else
    while IFS= read -r c; do
        [ -n "$c" ] || continue
        if [ -f "$c" ] && "$PHP_BIN" "$c" --version >/dev/null 2>&1; then
            pass "$c → $("$PHP_BIN" "$c" --version 2>/dev/null | head -n 1) (via ${PHP_BIN})"
            COMPOSER_FOUND=1
        else
            note "  ${C_DIM}${c}: not usable / no utilizable${C_RESET}"
        fi
    done < <(composer_candidates)
fi
if [ "$COMPOSER_FOUND" -eq 0 ]; then
    if [ -n "${PHP_BIN:-}" ]; then
        fail "No usable Composer / Ningún Composer utilizable"
        note "  ${C_BOLD}Fix / Solución:${C_RESET} ./run toolchain   ${C_DIM}(hermetic, no root / hermético, sin root)${C_RESET}"
    else
        warn "Composer untestable until PHP is fixed above / Composer no verificable hasta arreglar PHP"
    fi
fi

# --- 4. Project state ----------------------------------------------------------------
log "Project state / Estado del proyecto"
if [ -f "$B2B_ROOT/.env" ]; then
    pass ".env: present / presente"
    if app_key_set; then pass "APP_KEY: set"; else fail "APP_KEY: empty — run ./run setup / vacía — ejecuta ./run setup"; fi
else
    fail ".env: missing — run ./run setup / falta — ejecuta ./run setup"
fi
if [ -d "$B2B_ROOT/vendor" ]; then
    pass "vendor/: present ($(ls "$B2B_ROOT/vendor" | wc -l | tr -d ' ') top-level packages)"
else
    fail "vendor/: missing — run ./run setup / falta — ejecuta ./run setup"
fi

DB_FILE="$B2B_ROOT/database/database.sqlite"
if [ -f "$DB_FILE" ]; then
    pass "database: $(du -h "$DB_FILE" | cut -f1) at database/database.sqlite"
    if [ "$PHP_FOUND" -eq 1 ] && [ -d "$B2B_ROOT/vendor" ]; then
        resolve_php report
        tables="$("$PHP_BIN" -r '
            try {
                $pdo = new PDO("sqlite:" . $argv[1]);
                $n = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type=\"table\" AND name LIKE \"%\"")->fetchColumn();
                echo $n;
            } catch (Throwable $e) { echo "err"; }
        ' "$DB_FILE" 2>/dev/null || echo err)"
        if [ "$tables" = "err" ]; then
            fail "database schema: unreadable — run ./run setup / ilegible — ejecuta ./run setup"
        elif [ "$tables" -ge 10 ]; then
            pass "database schema: ${tables} tables (migrations applied / migraciones aplicadas)"
        else
            fail "database schema: only ${tables} tables — run ./run setup / solo ${tables} tablas — ejecuta ./run setup"
        fi
    fi
else
    warn "database: no database.sqlite yet — run ./run setup / aún no hay BD — ejecuta ./run setup"
fi

# --- Verdict ---------------------------------------------------------------------------
printf '%b\n' ""
if [ "$FAIL" -gt 0 ]; then
    printf '%b\n' "${C_BOLD}Result: ${FAIL} issue(s) found — see fixes above.${C_RESET}"
    printf '%b\n' "${C_BOLD}Resultado: ${FAIL} problema(s) — mira las soluciones arriba.${C_RESET}"
    exit 1
fi
printf '%b\n' "${C_GREEN}${C_BOLD}All green — next: ./run serve${C_RESET} / Todo en orden — siguiente: ./run serve"
exit 0
