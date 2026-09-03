#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/ci.sh — run locally everything CI runs, in CI order.
# scripts/ci.sh — corre localmente todo lo que corre CI, en el orden de CI.
#
# Usage:  ./run ci
#
# Pipeline mirror / Espejo del pipeline:
#   1. quality   (Pint + bash syntax + shellcheck + docs parity)
#   2. test all  (the full pytest pyramid: unit + feature + e2e)
#   3. e2e       (real-HTTP end-to-end against a throwaway DB)
# Fails fast at the first broken stage and says which one.
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

stages=(quality test e2e)
declare -A ran=()
for stage in "${stages[@]}"; do
    printf '%b\n' ""
    printf '%b\n' "${C_BOLD}${C_BLUE}══════════ stage: ${stage} ══════════${C_RESET}"
    case "$stage" in
        quality) bash "$SOURCE_DIR/quality.sh" ;;
        test)    bash "$SOURCE_DIR/test.sh" all ;;
        e2e)     bash "$SOURCE_DIR/e2e.sh" ;;
    esac
    ran["$stage"]=1
    ok "stage '${stage}' passed / etapa '${stage}' aprobada"
done

printf '%b\n' ""
printf '%b\n' "${C_GREEN}${C_BOLD}CI mirror complete: ${#ran[@]}/${#stages[@]} stages green${C_RESET}"
printf '%b\n' "${C_GREEN}${C_BOLD}Espejo de CI completo: ${#ran[@]}/${#stages[@]} etapas en verde${C_RESET}"
exit 0
