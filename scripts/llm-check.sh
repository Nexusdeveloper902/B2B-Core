#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/llm-check.sh — self-diagnosing DeepSeek API connectivity check.
# scripts/llm-check.sh — autodiagnóstico de conectividad con la API de DeepSeek.
#
# Usage:  ./run llm-check
#
# Makes ONE bare call to the same endpoint the app uses and reports the
# EXACT DeepSeek verdict (key validity, account balance, model existence,
# rate limit) with actionable guidance — so "it doesn't work" becomes a
# named cause. No PHP required: pure bash + curl.
#
# Exit codes: 0 = live round-trip works · 1 = diagnosed failure
#             2 = not configured (no key in .env)
#
# Realiza UNA llamada directa al mismo endpoint que usa la app y reporta
# el veredicto EXACTO de DeepSeek (validez de la clave, saldo de la
# cuenta, modelo, límite de peticiones) con orientación accionable.
# No requiere PHP: bash + curl.
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

DEFAULT_MODEL="deepseek-flash"
DEFAULT_VISION_MODEL="deepseek-flash"
ENDPOINT="https://api.deepseek.com/chat/completions"
ERRORS_DOC="https://api-docs.deepseek.com/quick_start/error_codes"
PLATFORM_URL="https://platform.deepseek.com"

KEY=""
MODEL=""
VISION_MODEL=""
DRIVER=""
if [ -f "$B2B_ROOT/.env" ]; then
    KEY="$(env_value DEEPSEEK_API_KEY)"
    MODEL="$(env_value DEEPSEEK_MODEL)"
    VISION_MODEL="$(env_value DEEPSEEK_VISION_MODEL)"
    DRIVER="$(env_value RECYCLING_CLASSIFIER_DRIVER)"
fi
[ -n "$MODEL" ] || MODEL="$DEFAULT_MODEL"
[ -n "$VISION_MODEL" ] || VISION_MODEL="$DEFAULT_VISION_MODEL"

printf '%b\n' "${C_BOLD}Presence Platform — DeepSeek llm-check${C_RESET}"

# --- 1. configuration state ------------------------------------------------
if [ -z "$KEY" ]; then
    warn "DEEPSEEK_API_KEY is not set in .env / DEEPSEEK_API_KEY no está en .env"
    cat <<EOF
  To enable live NL queries / Para habilitar consultas NL en vivo:
    1. Create a key at ${PLATFORM_URL} (pay-as-you-go balance, no free tier)
       (Crea una clave en ${PLATFORM_URL} — saldo de pago por uso, sin capa gratuita)
    2. echo 'DEEPSEEK_API_KEY=your-key' >> .env
       (never commit it — .env is gitignored / nunca la commitees)
    3. Re-run: ./run llm-check
EOF
    exit 2
fi

printf '  %-16s %b\n' "key:" "set (${#KEY} chars, prefix ${KEY:0:4}…)"
printf '  %-16s %b\n' "nlq model:" "${MODEL}"
printf '  %-16s %b\n' "vision model:" "${VISION_MODEL}"
printf '  %-16s %b\n' "classifier:" "${DRIVER:-stub} ${C_DIM}(stub | local | deepseek)${C_RESET}"

# Model-name hints (ADR-046 — api-docs.deepseek.com, Models & Pricing:
# the model is deepseek-flash; deepseek-chat/deepseek-reasoner are dead
# since 2026-07-24, deepseek-v4-flash/deepseek-v4-flash-vision-exp are
# retired but still served).
if [ "$MODEL" = "deepseek-chat" ] || [ "$MODEL" = "deepseek-reasoner" ]; then
    warn "DEEPSEEK_MODEL=${MODEL} is a legacy name discontinued on 2026-07-24; current default is ${DEFAULT_MODEL}"
    warn "DEEPSEEK_MODEL=${MODEL} es un nombre antiguo discontinuado el 2026-07-24; el valor actual es ${DEFAULT_MODEL}"
elif [ "$MODEL" = "deepseek-v4-flash" ]; then
    warn "DEEPSEEK_MODEL=${MODEL} is retired (still served); canonical name is ${DEFAULT_MODEL}"
    warn "DEEPSEEK_MODEL=${MODEL} está retirado (aún servido); el nombre canónico es ${DEFAULT_MODEL}"
fi
if [ "$VISION_MODEL" = "deepseek-v4-flash-vision-exp" ]; then
    warn "DEEPSEEK_VISION_MODEL=${VISION_MODEL} is retired (still served); canonical vision model is ${DEFAULT_VISION_MODEL}"
    warn "DEEPSEEK_VISION_MODEL=${VISION_MODEL} está retirado (aún servido); el modelo de visión canónico es ${DEFAULT_VISION_MODEL}"
elif [ "$VISION_MODEL" != "deepseek-flash" ]; then
    warn "DEEPSEEK_VISION_MODEL=${VISION_MODEL} — only ${DEFAULT_VISION_MODEL} accepts images (others 400)"
    warn "DEEPSEEK_VISION_MODEL=${VISION_MODEL} — solo ${DEFAULT_VISION_MODEL} acepta imágenes (las demás dan 400)"
fi

# --- 2. one bare live call ---------------------------------------------------
log "Live probe: POST ${ENDPOINT}"
log "Sonda en vivo: POST ${ENDPOINT}"
BODY='{"model":"'"${MODEL}"'","messages":[{"role":"user","content":"Reply with exactly: OK"}],"max_tokens":8,"thinking":{"type":"disabled"}}'
HTTP_CODE="$(curl -s -o "$B2B_ROOT/.llm-check-probe.json" -w '%{http_code}' -m 30 \
    "$ENDPOINT" \
    -H "Authorization: Bearer ${KEY}" \
    -H "Content-Type: application/json" \
    -d "$BODY")" || HTTP_CODE="000"
PROBE="$(cat "$B2B_ROOT/.llm-check-probe.json" 2>/dev/null || true)"
rm -f "$B2B_ROOT/.llm-check-probe.json" 2>/dev/null || true

# --- 3. verdict ----------------------------------------------------------------
# NOTE: every grep pipeline below must tolerate "no match" — with
# set -Eeuo pipefail an unmatched grep inside a command substitution
# would kill the script BEFORE the diagnosis prints (found live).
gmsg()  { printf '%s' "$PROBE" | grep -oE '"message": *"[^"]*"' | head -1 | sed 's/.*: *"//;s/"$//' || true; }
gserved() { printf '%s' "$PROBE" | grep -oE '"model": *"[^"]*"' | head -1 | sed 's/.*: *"//;s/"$//' || true; }

if [ "$HTTP_CODE" = "200" ]; then
    ok "HTTP 200 — live DeepSeek round-trip WORKS from this machine"
    ok "HTTP 200 — la llamada en vivo a DeepSeek FUNCIONA desde esta máquina"
    SERVED="$(gserved)"
    [ -n "$SERVED" ] && printf '  %-16s %b\n' "served as:" "${SERVED}"
    ok "NL queries through the app will work / Las consultas NL de la app funcionarán"
    exit 0
fi

err "HTTP ${HTTP_CODE} — DeepSeek's verdict / el veredicto de DeepSeek:"
[ -n "$PROBE" ] && printf '  %s\n\n' "$PROBE"

case "$HTTP_CODE" in
    401)
        warn "DIAGNOSIS: the key is INVALID or revoked — create a fresh one."
        warn "DIAGNÓSTICO: la clave es INVÁLIDA o fue revocada — crea una nueva."
        cat <<EOF
  1. Create a new key at ${PLATFORM_URL}
     (Crea una clave nueva en ${PLATFORM_URL})
  2. Update .env: DEEPSEEK_API_KEY=<new key>
     (Actualiza .env: DEEPSEEK_API_KEY=<nueva clave>)
  3. Re-run: ./run llm-check
EOF
        ;;
    402)
        warn "DIAGNOSIS: insufficient balance — the key is VALID, the account has no credit left."
        warn "DIAGNÓSTICO: saldo insuficiente — la clave SÍ es válida, la cuenta no tiene crédito."
        cat <<EOF
  DeepSeek is pay-as-you-go (no free tier) — top up the balance:
  DeepSeek es de pago por uso (sin capa gratuita) — recarga el saldo:
    1. Top up at ${PLATFORM_URL}
    2. Re-run: ./run llm-check
EOF
        ;;
    404)
        warn "DIAGNOSIS: model not found — DEEPSEEK_MODEL=${MODEL} does not exist for this key."
        warn "DIAGNÓSTICO: modelo no encontrado — DEEPSEEK_MODEL=${MODEL} no existe para esta clave."
        printf '  Fix / Corrige:  echo "DEEPSEEK_MODEL=%s" >> .env\n' "$DEFAULT_MODEL"
        ;;
    429)
        warn "DIAGNOSIS: rate limit reached — pace the requests and retry."
        warn "DIAGNÓSTICO: límite de peticiones alcanzado — espacia las peticiones y reintenta."
        ;;
    000)
        warn "DIAGNOSIS: network failure — no response from DeepSeek (DNS/firewall/offline?)."
        warn "DIAGNÓSTICO: fallo de red — sin respuesta de DeepSeek (¿DNS/firewall/sin conexión?)."
        ;;
    *)
        warn "DIAGNOSIS: unexpected status — raw body above; see ${ERRORS_DOC}"
        warn "DIAGNÓSTICO: estado inesperado — cuerpo arriba; ver la página de códigos de error"
        MSG="$(gmsg)"
        [ -n "$MSG" ] && warn "message: ${MSG}"
        ;;
esac

exit 1
