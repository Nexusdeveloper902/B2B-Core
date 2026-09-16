#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/seed-realistic.sh — fresh database + the full-semester realistic dataset.
# scripts/seed-realistic.sh — base de datos nueva + el dataset realista del semestre.
#
# Usage:  ./run seed-realistic                  # asks for confirmation first
#         ./run seed-realistic --force          # no prompt (scripting/CI)
#         ./run seed-realistic --students 300 --months 6
#         REALISTIC_STUDENTS=150 REALISTIC_MONTHS=3 ./run seed-realistic --force
#
# Wipes the DEV database only (database/database.sqlite, or the MariaDB
# database configured in .env — migrate:fresh) and rebuilds it with
# RealisticSeeder: ~300 students across grades 1-11, per-meal PAE
# enrollment, a teacher per class, and ~6 months of school-shaped taps,
# deposits, redemptions and operational history. The e2e throwaway DB is
# never affected. This is the analytics playground — NOT the setup path:
# `./run setup` / `./run reset` keep seeding the small DemoSeeder fixture
# (and --pilot the 10-day demo); this command is the heavy third option.
#
# Environment knobs (flags win when both are given):
#   REALISTIC_STUDENTS   default 300
#   REALISTIC_MONTHS     default 6 (1..12)
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

FORCE=0
STUDENTS="${REALISTIC_STUDENTS:-300}"
MONTHS="${REALISTIC_MONTHS:-6}"
while [ $# -gt 0 ]; do
    case "$1" in
        --help|-h) help_header "$0"; exit 0 ;;
        --force)   FORCE=1; shift ;;
        --students=*) STUDENTS="${1#--students=}"; shift ;;
        --months=*)   MONTHS="${1#--months=}"; shift ;;
        --students|--months)
            opt="$1"; shift
            [ $# -ge 1 ] || die "$opt needs a value / $opt necesita un valor"
            if [ "$opt" = "--students" ]; then STUDENTS="$1"; else MONTHS="$1"; fi
            shift ;;
        *) die "Unknown flag: $1 (see --help) / Bandera desconocida: $1 (ver --help)" ;;
    esac
done

case "$STUDENTS" in
    ''|*[!0-9]*) die "--students must be a positive number (got '${STUDENTS}') / --students debe ser un número positivo (recibido '${STUDENTS}')" ;;
esac
case "$MONTHS" in
    ''|*[!0-9]*) die "--months must be a positive number (got '${MONTHS}') / --months debe ser un número positivo (recibido '${MONTHS}')" ;;
esac
[ "$STUDENTS" -ge 22 ] || die "--students must be >= 22 (one per class) / --students debe ser >= 22 (uno por curso)"
[ "$MONTHS" -ge 1 ] && [ "$MONTHS" -le 12 ] || die "--months must be 1..12 / --months debe ser 1..12"

DB_FILE="$B2B_ROOT/database/database.sqlite"
DB_CONN="$(env_value DB_CONNECTION)"
if [ -z "$DB_CONN" ]; then DB_CONN="sqlite"; fi
case "$DB_CONN" in
    sqlite|mariadb) : ;; # ADR-049 — both are auto-managed dev databases
    *)
        die "DB_CONNECTION=${DB_CONN} — seed-realistic only manages sqlite/mariadb dev DBs (use artisan manually)
DB_CONNECTION=${DB_CONN} — seed-realistic solo gestiona BD de desarrollo sqlite/mariadb (usa artisan a mano)"
        ;;
esac

if [ "$FORCE" -eq 0 ]; then
    if [ "$DB_CONN" = "mariadb" ]; then
        printf '%b\n' "${C_BOLD}${C_YELLOW}This DELETES all data in the MariaDB dev database (migrate:fresh) and seeds ~${STUDENTS} students x ${MONTHS} months (slow: minutes).${C_RESET}"
        printf '%b\n' "${C_BOLD}${C_YELLOW}Esto BORRA todos los datos de la BD MariaDB de desarrollo (migrate:fresh) y siembra ~${STUDENTS} estudiantes x ${MONTHS} meses (lento: minutos).${C_RESET}"
    else
        printf '%b\n' "${C_BOLD}${C_YELLOW}This DELETES all data in database/database.sqlite and seeds ~${STUDENTS} students x ${MONTHS} months (slow: minutes).${C_RESET}"
        printf '%b\n' "${C_BOLD}${C_YELLOW}Esto BORRA todos los datos de database/database.sqlite y siembra ~${STUDENTS} estudiantes x ${MONTHS} meses (lento: minutos).${C_RESET}"
    fi
    confirm "Proceed? / ¿Continuar?" || die "Aborted / Cancelado"
fi

resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key
if [ "$DB_CONN" = "mariadb" ]; then
    mariadb_probe || { mariadb_remediation; exit 1; }
fi

log "Fresh migration + realistic seed (${STUDENTS} students x ${MONTHS} months) / Migración fresca + siembra realista (${STUDENTS} estudiantes x ${MONTHS} meses)"
if [ "$DB_CONN" = "sqlite" ]; then
    rm -f "$DB_FILE" "$DB_FILE-journal" "$DB_FILE-wal" "$DB_FILE-shm"
    touch "$DB_FILE"
fi
REALISTIC_STUDENTS="$STUDENTS" REALISTIC_MONTHS="$MONTHS" \
    "$PHP_BIN" artisan migrate:fresh --seeder=RealisticSeeder --force

# TASK-045 (ADR-064) — the single system administrator is seeded SEPARATELY
# and deliberately outside the school organization the seeder above built.
# TASK-045 (ADR-064) — el único administrador del sistema se siembra APARTE
# y deliberadamente fuera de la organización escolar sembrada arriba.
log "System administrator (outside the school) / Administrador del sistema (fuera del colegio)"
"$PHP_BIN" artisan db:seed --class='Database\Seeders\SystemAdminSeeder' --force

ok "Realistic seed complete — summary reprinted above / Siembra realista completa — resumen reimpreso arriba"
bi "Next: ./run serve" "Siguiente: ./run serve"
exit 0
