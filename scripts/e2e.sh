#!/usr/bin/env bash
#
# scripts/e2e.sh — REAL end-to-end test over HTTP.
# Boots `php artisan serve` against a THROWAWAY SQLite database, seeds demo
# data, and exercises the full platform story with plain curl:
# tap -> classify -> idempotency -> reader mode -> redeem -> NL-query blocked
# state + bilingual (EN/ES) device messages.
#
# Output is bilingual (EN/ES). Exits non-zero if ANY check fails.
#
# Usage:  ./run e2e [port]   |   bash scripts/e2e.sh [port]
# Never touches the dev database — uses database/e2e.sqlite exclusively.
#
# Part of the ./run suite (ADR-009): resolves PHP via scripts/_lib/common.sh
# (B2B_PHP -> PATH -> .tools/php), so it works with no system PHP at all.
set -euo pipefail

SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    help_header "$0"
    exit 0
fi

resolve_php
[ -d "$B2B_ROOT/vendor" ] || die "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup"
ensure_env_and_key

PORT="${1:-8089}"
BASE_URL="http://127.0.0.1:${PORT}"
E2E_DB="database/e2e.sqlite"
PASS=0
FAIL=0
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -f "$E2E_DB"
}
trap cleanup EXIT

say()  { printf '\n\033[1;34m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[0;32m✔ PASS\033[0m  %s\n' "$*"; PASS=$((PASS+1)); }
bad()  { printf '  \033[0;31m✘ FAIL\033[0m  %s\n' "$*"; FAIL=$((FAIL+1)); }

check() { # check <description> <haystack> <needle>
    if grep -qF "$3" <<<"$2"; then ok "$1"; else bad "$1 — expected [$3] in: $(head -c 300 <<<"$2")"; fi
}

# ---------------------------------------------------------------------------
say "== E2E — Presence Platform / Plataforma de Presencia (real HTTP) =="
say "== Preparando entorno / Preparing environment (throwaway DB) =="
rm -f "$E2E_DB"; touch "$E2E_DB"

export DB_DATABASE="$E2E_DB"
# Deterministic blocked-state for Phase E: run the e2e server WITHOUT a
# Gemini key regardless of what the developer's .env contains.
export GEMINI_API_KEY=""
"$PHP_BIN" artisan migrate --seed --force >/dev/null

# Extract demo credentials from the throwaway DB (seed printed them too).
eval "$("$PHP_BIN" -r '
$pdo = new PDO("sqlite:database/e2e.sqlite");
$classroom = $pdo->query("SELECT api_key, id FROM readers WHERE type = \"classroom\"")->fetch(PDO::FETCH_ASSOC);
$recycling = $pdo->query("SELECT api_key FROM readers WHERE type = \"recycling\"")->fetch(PDO::FETCH_ASSOC);
$card = $pdo->query("SELECT credential_uid FROM cards LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$student = $pdo->query("SELECT student_id FROM cards LIMIT 1")->fetch(PDO::FETCH_ASSOC);
printf("CLASSROOM_KEY=%s\nCLASSROOM_ID=%s\nRECYCLING_KEY=%s\nCARD_UID=%s\nSTUDENT_ID=%s\n",
    escapeshellarg($classroom["api_key"]), $classroom["id"], $recycling["api_key"],
    $card["credential_uid"], $student["student_id"]);
')"

# A test image (valid PNG).
IMG="$(mktemp /tmp/e2e_img_XXXX).png"
"$PHP_BIN" -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="));' "$IMG"

say "== Arrancando servidor / Starting server (${BASE_URL}) =="
# --no-reload passes the full host environment through (DB_DATABASE etc.),
# so the server uses the throwaway e2e database instead of .env's.
"$PHP_BIN" artisan serve --host=127.0.0.1 --port="$PORT" --no-reload >/dev/null 2>&1 &
SERVER_PID=$!

for i in $(seq 1 30); do
    if curl -sf "$BASE_URL/up" >/dev/null 2>&1; then break; fi
    sleep 1
    if [ "$i" = "30" ]; then echo "Server never became healthy."; exit 1; fi
done
ok "Server healthy / Servidor sano (GET /up)"

# ---------------------------------------------------------------------------
say "== Fase B — el bucle central / the core loop =="
R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$CARD_UID\"}")
check "Tap creates an event / El tap crea un evento" "$R" '"status":"ok"'
check "Tap returns event_type / El tap devuelve event_type" "$R" '"event_type":"CLASS_ATTENDANCE"'
check "Tap includes student first name / Incluye nombre del estudiante" "$R" '"student_first_name"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer nope-invalid-key" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"credential_uid": "X"}')
check "Invalid token rejected 401 / Token inválido 401" "$R" '401'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"credential_uid": "UNKNOWN"}')
check "Unknown card rejected 404 / Tarjeta desconocida 404" "$R" '404'
check "English device message / Mensaje de dispositivo EN" "$R" 'Card not recognized'

R=$(curl -s -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Accept-Language: es" -H "Content-Type: application/json" -d '{"credential_uid": "UNKNOWN"}')
check "Spanish device message / Mensaje de dispositivo ES" "$R" 'Tarjeta no reconocida'

# ---------------------------------------------------------------------------
say "== Fase C — reciclaje: tap → clasificar → ganar / classify → earn =="
R=$(curl -s -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $RECYCLING_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$CARD_UID\"}")
check "Recycling tap awaits classification / El tap de reciclaje espera clasificación" "$R" '"next_step":"awaiting_classification"'
REC_EVENT_ID=$(grep -oE '"event_id":[0-9]+' <<<"$R" | head -1 | grep -oE '[0-9]+')

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/recycling/classify" \
    -H "Authorization: Bearer $RECYCLING_KEY" -H "Accept: application/json" \
    -F "event_id=$REC_EVENT_ID" -F "image=@${IMG}")
check "Classify awards points / Clasificar otorga puntos" "$R" '"status":"ok"'
MATERIAL=$(grep -oE '"material_class":"[a-z]+"' <<<"$R" | head -1 | sed 's/.*:"//;s/"$//')
EXPECTED_POINTS=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (int) config("recycling.points.". $argv[1]);' "$MATERIAL")
check "Points match the config table / Puntos según config ($MATERIAL=$EXPECTED_POINTS)" "$R" "\"points_awarded\":${EXPECTED_POINTS}"

R2=$(curl -s -X POST "$BASE_URL/api/v1/recycling/classify" \
    -H "Authorization: Bearer $RECYCLING_KEY" -H "Accept: application/json" \
    -F "event_id=$REC_EVENT_ID" -F "image=@${IMG}")
check "Idempotent: no double award / Idempotente: sin doble otorgo" "$R2" '"already_classified":true'

# ---------------------------------------------------------------------------
say "== Fase B — cambiar modo del lector (admin) / reader relabeling =="
PAT=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $u=App\Models\User::where("email","admin@presence.test")->first(); echo $u->createToken("e2e")->plainTextToken;')

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/readers/$CLASSROOM_ID/mode" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"active_event_type":"PAE_LUNCH"}')
check "Admin relabels the reader / Admin reetiqueta el lector" "$R" '"active_event_type":"PAE_LUNCH"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$CARD_UID\"}")
check "Taps now register as PAE_LUNCH / Los taps ahora son PAE_LUNCH" "$R" '"event_type":"PAE_LUNCH"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/readers/$CLASSROOM_ID/mode" \
    -H "Authorization: Bearer nope" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"active_event_type":"ENTRY"}')
check "Guest cannot relabel (401) / Invitado no puede reetiquetar" "$R" '401'

# ---------------------------------------------------------------------------
say "== Fase D — canje / redemption =="
# Give the demo student exactly 25 points for deterministic assertions.
"$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); App\Models\PointsLedger::create(["student_id"=>$argv[1],"delta"=>25,"reason"=>"e2e_seed"]);' "$STUDENT_ID"

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/students/$STUDENT_ID/redeem" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"reward_id":2}')
check "Redeem 20-pt reward (balance 5 left) / Canje de 20 pts" "$R" '"new_balance":5'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/students/$STUDENT_ID/redeem" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Accept-Language: es" -H "Content-Type: application/json" -d '{"reward_id":1}')
check "Insufficient balance → 422 + shortfall / Saldo insuficiente → 422" "$R" '422'
check "Spanish shortfall message / Mensaje de faltante ES" "$R" 'Puntos insuficientes: faltan 45'

# ---------------------------------------------------------------------------
say "== Fase E — consulta NL: bloqueo honesto / NL query: honest blocker =="
R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/nl-query" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"question":"How many students attended today?"}')
check "No key → structured blocked 503 / Sin clave → bloqueado 503" "$R" '503'
check "Blocked reason reported / Razón del bloqueo reportada" "$R" '"blocked_reason":"missing_llm_credential"'

# ---------------------------------------------------------------------------
say "== Paneles web / web dashboards =="
R=$(curl -s -w '\n%{http_code}' "$BASE_URL/login")
check "Login page renders / La página de login responde" "$R" '200'

R=$(curl -s -w '\n%{http_code}' "$BASE_URL/admin")
check "Guests are redirected away from /admin / Invitados redirigidos" "$R" '302'

# ---------------------------------------------------------------------------
echo ""
echo "=================================================="
printf ' \033[1mRESULTADO / RESULT: %d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "=================================================="
if [ "$FAIL" -gt 0 ]; then exit 1; fi
