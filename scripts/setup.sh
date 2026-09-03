#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/setup.sh — one-command, idempotent bootstrap of the whole platform.
# scripts/setup.sh — arranque completo, idempotente, en un solo comando.
#
# Replaces: composer install + cp .env.example .env + key:generate +
#           touch database.sqlite + migrate --seed
#
# Usage:  ./run setup                 # full interactive bootstrap
#         ./run setup --ci            # quiet CI mode: deps + .env + key only
#         ./run setup --hermetic      # provision .tools/ PHP first, then setup
#         ./run setup --no-seed       # migrate without demo data
#         ./run setup --fresh         # wipe the dev DB and reseed from zero
#
# Safe to re-run at ANY time (ADR-011): existing .env is never overwritten,
# APP_KEY is generated only when empty, migrations/seeder are no-ops when
# current (DemoSeeder is firstOrCreate-idempotent and re-prints credentials).
#
# Exit codes: 0 ok · 1 failure (with a suggested fix printed)
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

MODE_CI=0; MODE_HERMETIC=0; SEED=1; FRESH=0
for arg in "$@"; do
    case "$arg" in
        --ci)       MODE_CI=1 ;;
        --hermetic) MODE_HERMETIC=1 ;;
        --no-seed)  SEED=0 ;;
        --fresh)    FRESH=1 ;;
        --help|-h)  help_header "$0"; exit 0 ;;
        *) die "Unknown flag: $arg (see --help) / Bandera desconocida: $arg (ver --help)" ;;
    esac
done

# --- 0. Optional hermetic toolchain first ----------------------------------------
if [ "$MODE_HERMETIC" -eq 1 ]; then
    log "Hermetic mode: provisioning .tools/ first / Modo hermético: provisionando .tools/ primero"
    bash "$SOURCE_DIR/provision-toolchain.sh"
fi

# --- 1. Toolchain (verify-then-act, ADR-010/011) ----------------------------------
resolve_php
resolve_composer
if [ "$MODE_CI" -eq 0 ]; then
    log "PHP: ${PHP_BIN} ($(php_version_string "$PHP_BIN")) — ${PHP_BIN_SOURCE}"
    log "Composer: ${COMPOSER_BIN} — ${COMPOSER_BIN_SOURCE}"
fi

# --- 2. Dependencies ----------------------------------------------------------------
if [ ! -d "$B2B_ROOT/vendor" ]; then
    log "Installing dependencies (composer install) / Instalando dependencias (composer install)"
else
    log "Dependencies: present, verifying they are current / Dependencias: presentes, verificando que estén al día"
fi
if ! composer_cmd install --no-interaction --prefer-dist --no-progress >/dev/null; then
    die "composer install failed — check network, then re-run / falló composer install — revisa la red y reintenta"
fi
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ still missing after install / vendor/ sigue faltando tras install"

# --- 3. Environment (never overwrite an existing .env) -------------------------------
if [ ! -f "$B2B_ROOT/.env" ]; then
    [ -f "$B2B_ROOT/.env.example" ] || die ".env.example missing / falta .env.example"
    cp "$B2B_ROOT/.env.example" "$B2B_ROOT/.env"
    log "Created .env from .env.example / .env creado desde .env.example"
else
    log ".env: exists (kept untouched) / .env: existe (sin tocar)"
fi
if ! app_key_set; then
    "$PHP_BIN" artisan key:generate --force >/dev/null
    log "Generated APP_KEY / APP_KEY generada"
else
    log "APP_KEY: already set / ya está definida"
fi

# --- 4. Database (CI mode stops here: tests use in-memory sqlite) ---------------------
if [ "$MODE_CI" -eq 1 ]; then
    log "CI mode: skipping database steps / Modo CI: omitiendo pasos de base de datos"
    ok "Setup complete (CI mode) / Setup completo (modo CI)"
    exit 0
fi

DB_FILE="$B2B_ROOT/database/database.sqlite"
DB_CONN="$(env_value DB_CONNECTION)"
if [ -z "$DB_CONN" ]; then DB_CONN="sqlite"; fi
if [ "$DB_CONN" != "sqlite" ]; then
    warn "DB_CONNECTION=${DB_CONN} — only sqlite is auto-managed; run migrations manually"
    warn "DB_CONNECTION=${DB_CONN} — solo sqlite se gestiona automáticamente; corre las migraciones a mano"
else
    if [ "$FRESH" -eq 1 ] && [ -f "$DB_FILE" ]; then
        rm -f "$DB_FILE"
        log "Removed existing database (--fresh) / Base de datos eliminada (--fresh)"
    fi
    if [ ! -f "$DB_FILE" ]; then
        mkdir -p "$B2B_ROOT/database"
        touch "$DB_FILE"
        log "Created database/database.sqlite / Base de datos creada"
    fi
fi

# --- 5. Migrations + demo data ----------------------------------------------------------
log "Running migrations / Ejecutando migraciones"
"$PHP_BIN" artisan migrate --force
if [ "$SEED" -eq 1 ]; then
    log "Seeding demo data (credentials are printed below) / Sembrando datos demo (las credenciales se imprimen abajo)"
    "$PHP_BIN" artisan db:seed --force
else
    log "Seeding skipped (--no-seed) / Siembra omitida (--no-seed)"
fi

# --- 6. Summary -----------------------------------------------------------------------------
printf '%b\n' ""
printf '%b\n' "${C_GREEN}${C_BOLD}Setup complete / Setup completo${C_RESET}"
bi "Next: ./run serve  →  http://127.0.0.1:${B2B_SERVE_PORT:-8000}" "Siguiente: ./run serve  →  http://127.0.0.1:${B2B_SERVE_PORT:-8000}"
bi "Demo logins: admin@presence.test · teacher@presence.test (password: password)" "Logins demo: admin@presence.test · teacher@presence.test (contraseña: password)"
bi "Diagnostics anytime: ./run doctor · docs: docs/SCRIPTS.md (EN/ES)" "Diagnóstico cuando quieras: ./run doctor · docs: docs/SCRIPTS.es.md"
exit 0
