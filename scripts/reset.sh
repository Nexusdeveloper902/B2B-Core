#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/reset.sh — fresh database + reseeded demo data (destructive, guarded).
# scripts/reset.sh — base de datos nueva + datos demo (destructivo, con guarda).
#
# Usage:  ./run reset            # asks for confirmation first
#         ./run reset --force    # no prompt (CI / scripting)
#         ./run reset --pilot    # rich 10-day pilot dataset instead of the small fixture
#
# Wipes the DEV database only (database/database.sqlite, or the MariaDB
# database configured in .env — migrate:fresh). The real-HTTP e2e
# suite uses its own throwaway DB and is never affected. DemoSeeder is
# idempotent, so this lands you in the exact post-setup state with fresh
# credentials printed bilingually. --pilot seeds PilotSeeder instead: three
# classes, 24 students and ten school days of taps (the human demo; the
# small DemoSeeder fixture stays the automated-test default).
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

FORCE=0
PILOT=0
for arg in "$@"; do
    case "$arg" in
        --help|-h) help_header "$0"; exit 0 ;;
        --force)   FORCE=1 ;;
        --pilot)   PILOT=1 ;;
        *) die "Unknown flag: $arg (see --help) / Bandera desconocida: $arg (ver --help)" ;;
    esac
done

DB_FILE="$B2B_ROOT/database/database.sqlite"
DB_CONN="$(env_value DB_CONNECTION)"
if [ -z "$DB_CONN" ]; then DB_CONN="sqlite"; fi
case "$DB_CONN" in
    sqlite|mariadb) : ;; # ADR-049 — both are auto-managed dev databases
    *)
        die "DB_CONNECTION=${DB_CONN} — reset only manages sqlite/mariadb dev DBs (use artisan manually)
DB_CONNECTION=${DB_CONN} — reset solo gestiona BD de desarrollo sqlite/mariadb (usa artisan a mano)"
        ;;
esac

if [ "$FORCE" -eq 0 ]; then
    if [ "$DB_CONN" = "mariadb" ]; then
        printf '%b\n' "${C_BOLD}${C_YELLOW}This DELETES all data in the MariaDB dev database (migrate:fresh) and reseeds demo data.${C_RESET}"
        printf '%b\n' "${C_BOLD}${C_YELLOW}Esto BORRA todos los datos de la BD MariaDB de desarrollo (migrate:fresh) y resembra datos demo.${C_RESET}"
    else
        printf '%b\n' "${C_BOLD}${C_YELLOW}This DELETES all data in database/database.sqlite and reseeds demo data.${C_RESET}"
        printf '%b\n' "${C_BOLD}${C_YELLOW}Esto BORRA todos los datos de database/database.sqlite y resembra datos demo.${C_RESET}"
    fi
    confirm "Proceed? / ¿Continuar?" || die "Aborted / Cancelado"
fi

resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key
if [ "$DB_CONN" = "mariadb" ]; then
    mariadb_probe || { mariadb_remediation; exit 1; }
fi

log "Fresh migration + seed / Migración fresca + siembra"
if [ "$DB_CONN" = "sqlite" ]; then
    rm -f "$DB_FILE" "$DB_FILE-journal" "$DB_FILE-wal" "$DB_FILE-shm"
    touch "$DB_FILE"
fi
if [ "$PILOT" -eq 1 ]; then
    "$PHP_BIN" artisan migrate:fresh --seed --seeder=PilotSeeder --force
else
    "$PHP_BIN" artisan migrate:fresh --seed --force
fi
ok "Reset complete — credentials reprinted above / Reset completo — credenciales reimpresas arriba"
bi "Next: ./run serve" "Siguiente: ./run serve"
exit 0
