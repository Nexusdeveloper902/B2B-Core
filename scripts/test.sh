#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/test.sh — run the test pyramid without memorizing suite names.
# scripts/test.sh — ejecuta la pirámide de pruebas sin memorizar nombres.
#
# Usage:   ./run test                  # all suites (unit + feature + e2e)
#          ./run test unit|feature|e2e # a single suite
#          ./run test unit --filter=PointsServiceTest
#          ./run test all --stop-on-failure
# Anything after the suite name is passed through to `php artisan test`.
# Live-LLM tests stay opt-in (RUN_LIVE_LLM_TESTS=1 + GEMINI_API_KEY).
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

SUITE="${1:-all}"
if [ "$SUITE" = "unit" ] || [ "$SUITE" = "feature" ] || [ "$SUITE" = "e2e" ] || [ "$SUITE" = "all" ]; then
    shift || true
else
    die "Unknown suite '$SUITE' (unit | feature | e2e | all) / Suite desconocida (unit | feature | e2e | all)"
fi

resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key

if [ "$SUITE" = "all" ]; then
    log "Running the full test pyramid / Ejecutando la pirámide completa de pruebas"
    exec "$PHP_BIN" artisan test "$@"
fi
log "Running testsuite: ${SUITE} / Ejecutando testsuite: ${SUITE}"
exec "$PHP_BIN" artisan test --testsuite="$SUITE" "$@"
