#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/llm-check.sh — self-diagnosing Gemini API connectivity check.
# scripts/llm-check.sh — autodiagnóstico de conectividad con la API de Gemini.
#
# Usage:  ./run llm-check
#
# Makes ONE bare call to the same endpoint the app uses and reports the
# EXACT Google verdict (key validity, region support, model existence,
# quota) with actionable guidance — so "it doesn't work" becomes a named
# cause. No PHP required: pure bash + curl.
#
# Exit codes: 0 = live round-trip works · 1 = diagnosed failure
#             2 = not configured (no key in .env)
#
# Realiza UNA llamada directa al mismo endpoint que usa la app y reporta
# el veredicto EXACTO de Google (validez de la clave, región, modelo,
# cuota) con orientación accionable. No requiere PHP: bash + curl.
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

DEFAULT_MODEL="gemini-3.1-flash-lite"
ENDPOINT_BASE="https://generativelanguage.googleapis.com/v1beta/models"
REGIONS_DOC="https://ai.google.dev/gemini-api/docs/available-regions"

KEY=""
MODEL=""
VISION_MODEL=""
DRIVER=""
if [ -f "$B2B_ROOT/.env" ]; then
    KEY="$(env_value GEMINI_API_KEY)"
    MODEL="$(env_value GEMINI_MODEL)"
    VISION_MODEL="$(env_value GEMINI_VISION_MODEL)"
    DRIVER="$(env_value RECYCLING_CLASSIFIER_DRIVER)"
fi
[ -n "$MODEL" ] || MODEL="$DEFAULT_MODEL"
[ -n "$VISION_MODEL" ] || VISION_MODEL="$DEFAULT_MODEL"

printf '%b\n' "${C_BOLD}Presence Platform — Gemini llm-check${C_RESET}"

# --- 1. configuration state ------------------------------------------------
if [ -z "$KEY" ]; then
    warn "GEMINI_API_KEY is not set in .env / GEMINI_API_KEY no está en .env"
    cat <<'EOF'
  To enable live NL queries / Para habilitar consultas NL en vivo:
    1. Create a key at https://aistudio.google.com/apikey
       (Crea una clave en https://aistudio.google.com/apikey)
    2. echo 'GEMINI_API_KEY=your-key' >> .env
       (never commit it — .env is gitignored / nunca la commitees)
    3. Re-run: ./run llm-check
EOF
    exit 2
fi

printf '  %-16s %b\n' "key:" "set (${#KEY} chars, prefix ${KEY:0:4}…)"
printf '  %-16s %b\n' "nlq model:" "${MODEL}"
printf '  %-16s %b\n' "vision model:" "${VISION_MODEL}"
printf '  %-16s %b\n' "classifier:" "${DRIVER:-stub} ${C_DIM}(stub | local | gemini)${C_RESET}"

# Stale-model hint (TASK-006 defaults moved to 3.1 flash-lite).
if [ "$MODEL" = "gemini-2.5-flash" ] || [ "$MODEL" = "gemini-2.0-flash" ]; then
    warn "GEMINI_MODEL=${MODEL} is the legacy default; current default is ${DEFAULT_MODEL}"
    warn "GEMINI_MODEL=${MODEL} es el valor antiguo; el valor actual es ${DEFAULT_MODEL}"
fi

# --- 2. one bare live call ---------------------------------------------------
log "Live probe: POST ${ENDPOINT_BASE}/${MODEL}:generateContent"
log "Sonda en vivo: POST ${ENDPOINT_BASE}/${MODEL}:generateContent"
BODY='{"contents":[{"parts":[{"text":"Reply with exactly: OK"}]}]}'
HTTP_CODE="$(curl -s -o "$B2B_ROOT/.llm-check-probe.json" -w '%{http_code}' -m 30 \
    "${ENDPOINT_BASE}/${MODEL}:generateContent" \
    -H "x-goog-api-key: ${KEY}" \
    -H "Content-Type: application/json" \
    -d "$BODY")" || HTTP_CODE="000"
PROBE="$(cat "$B2B_ROOT/.llm-check-probe.json" 2>/dev/null || true)"
rm -f "$B2B_ROOT/.llm-check-probe.json" 2>/dev/null || true

# --- 3. verdict ----------------------------------------------------------------
# NOTE: every grep pipeline below must tolerate "no match" — with
# set -Eeuo pipefail an unmatched grep inside a command substitution
# would kill the script BEFORE the diagnosis prints (found live).
gmsg()  { printf '%s' "$PROBE" | grep -oE '"message": *"[^"]*"' | head -1 | sed 's/.*: *"//;s/"$//' || true; }
greason() { printf '%s' "$PROBE" | grep -oE '"reason": *"[A-Z_]+"' | head -1 | sed 's/.*: *"//;s/"$//' || true; }

if [ "$HTTP_CODE" = "200" ]; then
    ok "HTTP 200 — live Gemini round-trip WORKS from this machine"
    ok "HTTP 200 — la llamada en vivo a Gemini FUNCIONA desde esta máquina"
    MODELVER="$(printf '%s' "$PROBE" | grep -oE '"modelVersion": *"[^"]*"' | head -1 | sed 's/.*: *"//;s/"$//' || true)"
    [ -n "$MODELVER" ] && printf '  %-16s %b\n' "served as:" "${MODELVER}"
    ok "NL queries through the app will work / Las consultas NL de la app funcionarán"
    exit 0
fi

err "HTTP ${HTTP_CODE} — Google's verdict / el veredicto de Google:"
[ -n "$PROBE" ] && printf '  %s\n\n' "$PROBE"

case "$HTTP_CODE" in
    400|401|403)
        MSG="$(gmsg)"; REASON="$(greason)"
        if printf '%s' "$MSG$REASON$PROBE" | grep -qi "location is not supported"; then
            warn "DIAGNOSIS: region unsupported. Your key is VALID — Google refuses this network/region."
            warn "DIAGNÓSTICO: región no soportada. Tu clave SÍ es válida — Google rechaza esta red/región."
            cat <<EOF
  Your key authenticated; the refusal is about WHERE this machine's
  traffic exits. Tu clave autenticó bien; el rechazo es por DÓNDE sale
  el tráfico de esta máquina.
  - Supported regions / Regiones soportadas: ${REGIONS_DOC}
  - Try another network (hotspot, no VPN/VPN off) and re-run
    ./run llm-check / Prueba otra red y vuelve a ejecutar ./run llm-check
EOF
        elif printf '%s' "$REASON$PROBE" | grep -q "API_KEY_INVALID\|API key not valid\|UNAUTHENTICATED"; then
            warn "DIAGNOSIS: the key is INVALID or revoked — create a fresh one."
            warn "DIAGNÓSTICO: la clave es INVÁLIDA o fue revocada — crea una nueva."
            cat <<'EOF'
  1. Create a new key at https://aistudio.google.com/apikey
     (Crea una clave nueva en https://aistudio.google.com/apikey)
  2. Update .env: GEMINI_API_KEY=<new key>
     (Actualiza .env: GEMINI_API_KEY=<nueva clave>)
  3. Re-run: ./run llm-check
EOF
        else
            warn "DIAGNOSIS: request rejected (see raw error above) — check the payload/model constraints."
            warn "DIAGNÓSTICO: petición rechazada (ver error arriba) — revisa el payload/el modelo."
        fi
        ;;
    404)
        warn "DIAGNOSIS: model not found — GEMINI_MODEL=${MODEL} does not exist for this key."
        warn "DIAGNÓSTICO: modelo no encontrado — GEMINI_MODEL=${MODEL} no existe para esta clave."
        printf '  Fix / Corrige:  echo "GEMINI_MODEL=%s" >> .env\n' "$DEFAULT_MODEL"
        ;;
    429)
        warn "DIAGNOSIS: quota exhausted — wait and retry (free tier is per-minute limited)."
        warn "DIAGNÓSTICO: cuota agotada — espera y reintenta (la capa gratuita limita por minuto)."
        ;;
    000)
        warn "DIAGNOSIS: network failure — no response from Google (DNS/firewall/offline?)."
        warn "DIAGNÓSTICO: fallo de red — sin respuesta de Google (¿DNS/firewall/sin conexión?)."
        ;;
    *)
        warn "DIAGNOSIS: unexpected status — raw body above; see https://ai.google.dev/gemini-api/docs/generate-content/api-errors"
        warn "DIAGNÓSTICO: estado inesperado — cuerpo arriba; ver la página de errores de la API"
        ;;
esac

exit 1
