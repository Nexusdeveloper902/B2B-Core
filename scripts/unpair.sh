#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/unpair.sh — unpair EVERY card (testing reset; guarded, bilingual).
# scripts/unpair.sh — desvincula TODAS las tarjetas (reset de pruebas; con guarda, bilingüe).
#
# Usage:  ./run unpair            # asks for confirmation first
#         ./run unpair --force    # no prompt (scripting / bench loops)
#
# Deletes every cards row (+ its tap events; pairing-history card links
# are cleared, history rows survive) so every credential_uid is FRESH
# again and can be re-paired through the normal arm-then-pair flow
# (ADR-020/023). Students, readers, users, points and recycling data are
# untouched. ./run reset restores the seeded demo cards if you want them
# back. The e2e throwaway DB is never affected.
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

resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key

if [ "$FORCE" -eq 0 ]; then
    printf '%b\n' "${C_BOLD}${C_YELLOW}This deletes ALL cards (and their tap events) so every card becomes fresh and pairable again.${C_RESET}"
    printf '%b\n' "${C_BOLD}${C_YELLOW}Esto BORRA TODAS las tarjetas (y sus eventos) para que cada tarjeta vuelva a ser fresca y emparejable.${C_RESET}"
    confirm "Proceed? / ¿Continuar?" || die "Aborted / Cancelado"
fi

log "Unpairing every card / Desvinculando todas las tarjetas"
"$PHP_BIN" artisan cards:unpair --force
ok "Every credential is fresh — arm a pairing (dashboard) and tap any card / Cada credencial está fresca — arma un emparejamiento (panel) y toca cualquier tarjeta"
exit 0
