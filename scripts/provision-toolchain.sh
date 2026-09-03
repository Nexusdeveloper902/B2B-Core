#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/provision-toolchain.sh — hermetic PHP + Composer into .tools/.
# scripts/provision-toolchain.sh — PHP + Composer herméticos en .tools/.
#
# Usage:  ./run toolchain            # idempotent: no-op when already valid
#         ./run toolchain --force    # re-download even if present
# Env:    B2B_STATIC_PHP_VERSION     # default: 8.4.23 (OBS-001-verified)
#
# Downloads a STATICALLY-LINKED PHP CLI (all required extensions compiled in)
# and composer.phar into .tools/ (gitignored). No root, no system packages,
# no php.ini editing — works on any x86_64 Linux, including a bare Arch
# install and containers. This is the fallback path of ADR-010; every script
# auto-detects .tools/php via the resolution chain.
#
# Exit codes: 0 ok · 1 download/verification failure
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

FORCE=0
for arg in "$@"; do
    case "$arg" in
        --help|-h) help_header "$0"; exit 0 ;;
        --force)   FORCE=1 ;;
        *) die "Unknown flag: $arg (see --help) / Bandera desconocida: $arg (ver --help)" ;;
    esac
done

PHP_VER="${B2B_STATIC_PHP_VERSION:-8.4.23}"
PHP_URL="https://dl.static-php.dev/static-php-cli/common/php-${PHP_VER}-cli-linux-x86_64.tar.gz"
COMPOSER_URL="https://getcomposer.org/download/latest-stable/composer.phar"
TOOLS="$B2B_ROOT/.tools"

# --- Preconditions --------------------------------------------------------------------
command -v curl >/dev/null 2>&1 || die "curl is required to download the toolchain / se necesita curl"
command -v tar  >/dev/null 2>&1 || die "tar is required to extract PHP / se necesita tar"
ARCH="$(uname -m)"
[ "$ARCH" = "x86_64" ] || die "Prebuilt static PHP exists for x86_64 only (found ${ARCH}); use your distro's PHP + ./run doctor.
El PHP estático precompilado solo existe para x86_64 (encontré ${ARCH}); usa el PHP de tu distro + ./run doctor."

# --- Idempotency -----------------------------------------------------------------------
if [ "$FORCE" -eq 0 ] && [ -x "$TOOLS/php" ] && [ -f "$TOOLS/composer" ] && php_valid "$TOOLS/php" \
   && "$TOOLS/php" "$TOOLS/composer" --version >/dev/null 2>&1; then
    ok "Toolchain already provisioned (use --force to redo) / Toolchain ya provisionado (usa --force para rehacer)"
    log "  $(php_version_string "$TOOLS/php") · $("$TOOLS/php" "$TOOLS/composer" --version 2>/dev/null | head -n 1)"
    exit 0
fi

# --- Provision ---------------------------------------------------------------------------
log "Provisioning hermetic toolchain into .tools/ / Provisionando toolchain hermético en .tools/"
printf '%b\n' "  ${C_DIM}PHP ${PHP_VER}      ← ${PHP_URL}${C_RESET}"
printf '%b\n' "  ${C_DIM}Composer    ← ${COMPOSER_URL}${C_RESET}"
mkdir -p "$TOOLS"

log "Downloading static PHP ${PHP_VER} …"
curl -fL --retry 3 --retry-delay 2 --connect-timeout 15 -o "$TOOLS/php.tar.gz" "$PHP_URL" \
    || die "PHP download failed — check network/URL or pin B2B_STATIC_PHP_VERSION / falló la descarga de PHP"

log "Downloading composer.phar …"
curl -fL --retry 3 --retry-delay 2 --connect-timeout 15 -o "$TOOLS/composer" "$COMPOSER_URL" \
    || die "Composer download failed — check network / falló la descarga de Composer"

log "Extracting …"
tar -xzf "$TOOLS/php.tar.gz" -C "$TOOLS" && rm -f "$TOOLS/php.tar.gz"
chmod +x "$TOOLS/php" "$TOOLS/composer"

# --- Verify -------------------------------------------------------------------------------
php_valid "$TOOLS/php" \
    || die "Downloaded PHP failed verification (${PHP_FAIL_REASON}) — pin another B2B_STATIC_PHP_VERSION / el PHP descargado falló la verificación"
"$TOOLS/php" "$TOOLS/composer" --version >/dev/null 2>&1 \
    || die "Downloaded Composer failed verification / el Composer descargado falló la verificación"

ok "Provisioned: $(php_version_string "$TOOLS/php") + $("$TOOLS/php" "$TOOLS/composer" --version 2>/dev/null | head -n 1)"
bi "Every ./run command now auto-detects .tools/php (order: B2B_PHP → PATH → .tools)." \
   "Todo comando ./run ahora autodetecta .tools/php (orden: B2B_PHP → PATH → .tools)."
bi "Next: ./run setup (or force it everywhere with: export B2B_PHP=$TOOLS/php)" \
   "Siguiente: ./run setup (o forzarlo siempre con: export B2B_PHP=$TOOLS/php)"
exit 0
