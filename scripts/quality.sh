#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/quality.sh — the lint gate: everything CI's lint stage checks.
# scripts/quality.sh — control de calidad: todo lo que revisa la etapa de lint.
#
# Usage:  ./run quality
#
# Steps / Pasos:
#   1. bash -n syntax check on `run` + every scripts/*.sh
#   2. shellcheck (when installed; required in CI's scripts-lint job)
#   3. Laravel Pint (code style) via the resolved PHP
#   4. Bilingual docs parity: every dispatcher command documented in
#      docs/SCRIPTS.md AND docs/SCRIPTS.es.md
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

# --- 1. bash syntax ----------------------------------------------------------------
log "1/4 bash syntax (bash -n) / sintaxis bash (bash -n)"
# `run` + the shared library + every top-level script in scripts/
SYNTAX_TARGETS=("$B2B_ROOT/run" "$SOURCE_DIR/_lib/common.sh")
while IFS= read -r f; do SYNTAX_TARGETS+=("$f"); done < <(find "$B2B_ROOT/scripts" -maxdepth 1 -name '*.sh' | sort)
for f in "${SYNTAX_TARGETS[@]}"; do
    bash -n "$f" || die "Syntax error in ${f} / Error de sintaxis en ${f}"
done
ok "${#SYNTAX_TARGETS[@]} files pass bash -n / archivos pasan bash -n"

# --- 2. shellcheck (optional locally, required in CI) ----------------------------------
log "2/4 shellcheck"
if command -v shellcheck >/dev/null 2>&1; then
    # Gate: zero WARNINGS. Remaining infos are intentional (php -r payloads
    # must live in single quotes so bash never expands $argv PHP vars).
    if shellcheck --severity=warning -x "${SYNTAX_TARGETS[@]}"; then
        ok "shellcheck clean (severity >= warning) / shellcheck limpio"
    else
        die "shellcheck findings above (fix them) / hallazgos arriba (corrigelos)"
    fi
else
    warn "shellcheck not installed — skipped locally (CI enforces it) / no instalado — omitido (CI lo exige)"
fi

# --- 3. Laravel Pint ---------------------------------------------------------------------
log "3/4 Laravel Pint (code style)"
resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
if "$PHP_BIN" "$B2B_ROOT/vendor/bin/pint" --test; then
    ok "Pint clean / Pint limpio"
else
    die "Pint found style issues — run: vendor/bin/pint (dirty mode applies fixes), then re-run / Pint encontró problemas de estilo — vendor/bin/pint aplica fixes, reintenta"
fi

# --- 4. Bilingual docs parity ---------------------------------------------------------------
log "4/4 docs parity (EN/ES) / paridad de documentación (EN/ES)"
DOC_EN="$B2B_ROOT/docs/SCRIPTS.md"
DOC_ES="$B2B_ROOT/docs/SCRIPTS.es.md"
[ -f "$DOC_EN" ] || die "docs/SCRIPTS.md missing / falta docs/SCRIPTS.md"
[ -f "$DOC_ES" ] || die "docs/SCRIPTS.es.md missing / falta docs/SCRIPTS.es.md"

# Canonical command list comes from `./run help` itself (single source of
# truth, ADR-009 — the dispatcher's own usage output).
mapfile -t COMMANDS < <(
    bash "$B2B_ROOT/run" help | grep -oE '^  [a-z-]+ ' | tr -d ' ' | grep -v '^help$' | sort -u
)
if [ "${#COMMANDS[@]}" -lt 10 ]; then
    die "could not parse commands from './run help' (found ${#COMMANDS[@]}) / no se pudieron extraer los comandos de './run help'"
fi
for cmd in "${COMMANDS[@]}"; do
    [ "$cmd" = "help" ] && continue
    if grep -qE "^## \`${cmd}\`" "$DOC_EN" && grep -qE "^## \`${cmd}\`" "$DOC_ES"; then
        ok "documented (EN+ES): ${cmd}"
    else
        fail_msg="command '${cmd}' is missing a '### \`${cmd}\`' section in"
        err "${fail_msg} docs/SCRIPTS.md or docs/SCRIPTS.es.md"
        err "el comando '${cmd}' no tiene su sección '### \`${cmd}\`' en docs/SCRIPTS.md o docs/SCRIPTS.es.md"
        exit 1
    fi
done
log "Quality gate passed / Control de calidad aprobado"
exit 0
