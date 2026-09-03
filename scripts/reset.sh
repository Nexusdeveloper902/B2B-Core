#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/reset.sh — fresh database + reseeded demo data (destructive, guarded).
# scripts/reset.sh — base de datos nueva + datos demo (destructivo, con guarda).
#
# Usage:  ./run reset            # asks for confirmation first
#         ./run reset --force    # no prompt (CI / scripting)
#
# Wipes the DEV database only (database/database.sqlite). The real-HTTP e2e
# suite uses its own throwaway DB and is never affected. DemoSeeder is
# idempotent, so this lands you in the exact post-setup state with fresh
# credentials printed bilingually.
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

DB_FILE="$B2B_ROOT/database/database.sqlite"
if [ "$(env_value DB_CONNECTION)" != "sqlite" ] && [ -n "$(env_value DB_CONNECTION)" ]; then
    die "DB_CONNECTION is not sqlite — reset only manages the dev sqlite DB (use artisan manually)
DB_CONNECTION no es sqlite — reset solo gestiona la BD sqlite de desarrollo (usa artisan a mano)"
fi

if [ "$FORCE" -eq 0 ]; then
    printf '%b\n' "${C_BOLD}${C_YELLOW}This DELETES all data in database/database.sqlite and reseeds demo data.${C_RESET}"
    printf '%b\n' "${C_BOLD}${C_YELLOW}Esto BORRA todos los datos de database/database.sqlite y resembra datos demo.${C_RESET}"
    confirm "Proceed? / ¿Continuar?" || die "Aborted / Cancelado"
fi

resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key

log "Fresh migration + seed / Migración fresca + siembra"
rm -f "$DB_FILE" "$DB_FILE-journal" "$DB_FILE-wal" "$DB_FILE-shm"
touch "$DB_FILE"
"$PHP_BIN" artisan migrate:fresh --seed --force
ok "Reset complete — credentials reprinted above / Reset completo — credenciales reimpresas arriba"
bi "Next: ./run serve" "Siguiente: ./run serve"
exit 0
