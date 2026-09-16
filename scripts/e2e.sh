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
# Test image kept PROJECT-RELATIVE (not /tmp): native Windows curl.exe cannot
# read msys /tmp paths, but every curl accepts a cwd-relative path (ADR-017).
IMG="database/.e2e_img.png"
PASS=0
FAIL=0
SERVER_PID=""
WS_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [ -n "$WS_PID" ]; then
        kill "$WS_PID" 2>/dev/null || true
        wait "$WS_PID" 2>/dev/null || true
    fi
    rm -f "$E2E_DB" "$IMG"
}
trap cleanup EXIT

say()  { printf '\n\033[1;34m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[0;32m✔ PASS\033[0m  %s\n' "$*"; PASS=$((PASS+1)); }
bad()  { printf '  \033[0;31m✘ FAIL\033[0m  %s\n' "$*"; FAIL=$((FAIL+1)); }

check() { # check <description> <haystack> <needle>
    if grep -qF "$3" <<<"$2"; then ok "$1"; else bad "$1 — expected [$3] in: $(head -c 300 <<<"$2")"; fi
}

# ---------------------------------------------------------------------------
say "== E2E — Pulse (real HTTP) =="
say "== Preparando entorno / Preparing environment (throwaway DB) =="
rm -f "$E2E_DB"; touch "$E2E_DB"

export DB_DATABASE="$E2E_DB"
# Hermetic driver: the e2e is a THROWAWAY-SQLITE contract suite. With the
# app's .env now allowed to be mariadb (ADR-049), an unpinned
# DB_CONNECTION would point "database/e2e.sqlite" at a MariaDB server and
# explode. Pin the whole storage triple, not just the file.
export DB_CONNECTION="sqlite"
# Deterministic blocked-state for Phase E: run the e2e server WITHOUT a
# DeepSeek key regardless of what the developer's .env contains.
export DEEPSEEK_API_KEY=""
# Hermetic classify phase for the same reason: a developer .env pointing
# RECYCLING_CLASSIFIER_DRIVER at deepseek/local (no local model up) made
# the "classify awards points" check depend on live credentials. The e2e
# is a contract suite — the stub driver is its deterministic classifier.
export RECYCLING_CLASSIFIER_DRIVER="stub"
# TASK-037 — full-day meal windows (breakfast 00:00–12:00, lunch 12:00–23:59;
# touching, never overlapping) so the meal phases are deterministic at any
# wall-clock time. Exported BEFORE the seed so the demo seeder writes them
# as the settings rows the engine reads.
export PAE_BREAKFAST_START="00:00"
export PAE_BREAKFAST_END="12:00"
export PAE_LUNCH_START="12:00"
export PAE_LUNCH_END="23:59"
"$PHP_BIN" artisan migrate --seed --force >/dev/null

# Extract demo credentials from the throwaway DB (seed printed them too).
eval "$("$PHP_BIN" -r '
$pdo = new PDO("sqlite:database/e2e.sqlite");
$classroom = $pdo->query("SELECT api_key, id FROM readers WHERE type = \"classroom\"")->fetch(PDO::FETCH_ASSOC);
$recycling = $pdo->query("SELECT api_key FROM readers WHERE type = \"recycling\"")->fetch(PDO::FETCH_ASSOC);
// Deterministic + PAE-valid card pick. LIMIT 1 without ORDER BY is
// planner-dependent (SQLite may serve it from the credential_uid
// unique-index scan, and the seeded UIDs are RANDOM — the owner then
// varies per run; CI run 34285994702 drew the one non-PAE student and
// the relabel-phase tap correctly hit the TASK-027 PAE gate). One
// ordered, PAE-filtered query also keeps CARD_UID and STUDENT_ID
// coherent for the redemption phase.
$card = $pdo->query("SELECT c.credential_uid, c.student_id FROM cards c JOIN students s ON s.id = c.student_id WHERE s.pae_lunch_enrolled = 1 AND s.pae_breakfast_enrolled = 1 ORDER BY c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
// TASK-037 — the Ana card: the meal-specific not-enrolled message check
// (Ana is lunch-only, so a breakfast-window tap gets the breakfast message).
$ana = $pdo->query("SELECT c.credential_uid FROM cards c JOIN students s ON s.id = c.student_id WHERE s.name LIKE \"Ana%\" ORDER BY c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
// TASK-044 — the meal-phase card: first-tap-counts dedup is per
// student/type/day, and Fase B already tapped CARD_UID today — so the
// relabel phase needs a DIFFERENT both-enrolled student whose
// backdated attendance tap actually creates its row (else the meal
// prerequisite sees only the later Fase B tap and flags no_attendance).
$meal = $pdo->query("SELECT c.credential_uid FROM cards c JOIN students s ON s.id = c.student_id WHERE s.pae_lunch_enrolled = 1 AND s.pae_breakfast_enrolled = 1 AND c.student_id != ".(int) $card["student_id"]." ORDER BY c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
printf("CLASSROOM_KEY=%s\nCLASSROOM_ID=%s\nRECYCLING_KEY=%s\nCARD_UID=%s\nSTUDENT_ID=%s\nANA_UID=%s\nMEAL_UID=%s\n",
    escapeshellarg($classroom["api_key"]), $classroom["id"], $recycling["api_key"],
    $card["credential_uid"], $card["student_id"], $ana["credential_uid"], $meal["credential_uid"]);
')"

# A test image (valid PNG) — created project-relative so BOTH Linux curl and
# native Windows curl.exe can open it (msys /tmp is invisible to curl.exe).
rm -f "$IMG"
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

# TASK-037 — the meal-serving engine over real HTTP. A relabeled PAE-mode
# reader routes its taps through the engine: the meal is AUTO-DETECTED from
# the windows (the label is not the meal authority), and the eligibility
# rules apply (attendance prerequisite, per-meal enrollment, duplicates).
# Deterministic at any wall-clock time: attendance is tapped with a
# day-safe client_timestamp 10 minutes before the meal tap — BEFORE the
# relabel, while the classroom reader still records CLASS_ATTENDANCE.
# TASK-044 — MEAL_UID (not CARD_UID): Fase B already tapped CARD_UID
# today and first-tap-counts dedup would collapse the backdated
# attendance tap into it.
eval "$("$PHP_BIN" -r '
$now = new DateTime("now", new DateTimeZone("America/Bogota"));
$att = (clone $now)->modify("-10 minutes");
if ($att->format("Y-m-d") !== $now->format("Y-m-d")) { $att = (clone $now)->setTime(0, 1, 0); }
$meal = (clone $att)->modify("+5 minutes");
printf("ATT_TS=%s\nMEAL_TS=%s\nDOW=%s\nEXPECTED_MEAL=%s\n",
    escapeshellarg($att->format("Y-m-d H:i:s")), escapeshellarg($meal->format("Y-m-d H:i:s")),
    $now->format("N"), ((int) $meal->format("H")) < 12 ? "breakfast" : "lunch");
')"

if [ "$DOW" -le 5 ]; then
    R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
        -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
        -H "Content-Type: application/json" -d "{\"credential_uid\": \"$MEAL_UID\", \"client_timestamp\": \"$ATT_TS\"}")
    check "Attendance tap for the meal prerequisite / Tap de asistencia previo" "$R" '"event_type":"CLASS_ATTENDANCE"'
fi

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/readers/$CLASSROOM_ID/mode" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"active_event_type":"PAE_LUNCH"}')
check "Admin relabels the reader / Admin reetiqueta el lector" "$R" '"active_event_type":"PAE_LUNCH"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$MEAL_UID\", \"client_timestamp\": \"$MEAL_TS\"}")
if [ "$DOW" -le 5 ]; then
    check "Meal tap succeeds (not flagged) / El toque de comida es servido" "$R" '"status":"ok"'
    check "Meal auto-detected from the windows / Comida auto-detectada ($EXPECTED_MEAL)" "$R" "\"meal\":\"$EXPECTED_MEAL\""
    EXPECTED_TYPE="PAE_$(printf '%s' "$EXPECTED_MEAL" | tr '[:lower:]' '[:upper:]')"
    check "Served meal returns the meal type / La comida servida devuelve el tipo" "$R" "\"event_type\":\"$EXPECTED_TYPE\""

    R2=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
        -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
        -H "Content-Type: application/json" -d "{\"credential_uid\": \"$MEAL_UID\", \"client_timestamp\": \"$MEAL_TS\"}")
    check "Duplicate meal is flagged 422 / Comida duplicada queda marcada 422" "$R2" '"reason":"duplicate"'
else
    check "Weekend taps are flagged / Toques de fin de semana marcados" "$R" '"reason":"weekend"'
fi

# The engine's rejection speaks Spanish and NAMES THE MEAL (TASK-037):
# Ana is lunch-only, so an in-window breakfast tap gets the breakfast message.
R=$(curl -s -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Accept-Language: es" -H "Content-Type: application/json" \
    -d "{\"credential_uid\": \"$ANA_UID\", \"client_timestamp\": \"$ATT_TS\"}")
if [ "$DOW" -le 5 ] && [ "$EXPECTED_MEAL" = "breakfast" ]; then
    check "Not-enrolled rejection names the meal (ES) / Rechazo nombra la comida" "$R" 'no está inscrito para el Desayuno'
fi

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/readers/$CLASSROOM_ID/mode" \
    -H "Authorization: Bearer nope" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"active_event_type":"PAE_ATTEMPT"}')
check "Guest cannot relabel (401) / Invitado no puede reetiquetar" "$R" '401'

# ---------------------------------------------------------------------------
say "== Fase B-HCE — el teléfono como credencial / phone-as-credential =="
# The Android HCE path over real HTTP with a PER-CREDENTIAL key (TASK-049,
# ADR-068): arm (admin PAT) → pair = key hand-off (reader-wrapped key +
# proof of possession) → proven taps resolve the student; an unproven or
# replayed tap is refused; revoke destroys the key. No hardware needed —
# the APDU exchange is proven by the firmware native suite + bench
# checklist §10; this PHP stand-in computes exactly what the reader relays.
# TASK-048 — the demo seed now owns a school (ADR-064): the student joins
# its class's school, or the school-scoped admin could not see it to arm.
HCE_STUDENT_ID=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=App\Models\SchoolClass::first(); $s=App\Models\Student::create(["name"=>"E2E HCE Student","grade"=>"5°","class_id"=>$c->id,"school_id"=>$c->school_id,"pae_breakfast_enrolled"=>false,"pae_lunch_enrolled"=>false]); echo $s->id;')
HCE_CRED="E2E-HCE-PHONE-01"
# The phone's own key (test-only, generated per run — never a constant).
HCE_KEY=$("$PHP_BIN" -r 'echo bin2hex(random_bytes(32));')

# hce_body <tap|pair> — the JSON a reader relays for this phone.
hce_body() {
    "$PHP_BIN" -r 'require "vendor/autoload.php";
        [, $mode, $cred, $keyHex, $readerKey] = $argv;
        $key = hex2bin($keyHex); $nonce = random_bytes(8);
        $b = ["credential_uid" => $cred, "hce_nonce" => bin2hex($nonce),
              "hce_mac" => bin2hex(App\Services\Hce\HceCredentialAuth::challengeMac($key, $cred, $nonce))];
        if ($mode === "pair") {
            $wn = bin2hex(random_bytes(16));
            $b += ["credential_kind" => "hce", "hce_key_nonce" => $wn,
                   "hce_key_wrapped" => App\Services\Hce\HceCredentialAuth::wrapKey($readerKey, $cred, $wn, $key)];
        }
        echo json_encode($b);' "$1" "$HCE_CRED" "$HCE_KEY" "$CLASSROOM_KEY"
}

# The relabel phase above left the classroom reader in PAE_LUNCH mode (its
# taps now run the meal engine — and on weekends they flag 422). Phone taps
# must prove the PLAIN tap path, so relabel back first.
R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/readers/$CLASSROOM_ID/mode" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"active_event_type":"CLASS_ATTENDANCE"}')
check "Reader back to classroom mode / Lector de vuelta a modo aula" "$R" '"active_event_type":"CLASS_ATTENDANCE"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/students/$HCE_STUDENT_ID/arm-pairing" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{}')
check "Arm pairing for the phone student / Armar emparejamiento" "$R" '"status":"ok"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/cards/pair" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{"credential_uid": "'"$HCE_CRED"'", "credential_kind": "hce"}')
check "Keyless phone pairing is refused 422 / Emparejar sin llave se rechaza" "$R" '422'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/cards/pair" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$(hce_body pair)")
check "Pair the phone with its own key / Emparejar con su propia llave" "$R" "\"student_id\":$HCE_STUDENT_ID"

HCE_STORED=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=App\Models\Card::where("credential_uid", $argv[1])->first(); echo $c->kind->value, ":", App\Models\HceCredentialKey::where("card_id", $c->id)->value("fingerprint") === App\Services\Hce\HceCredentialAuth::fingerprint(hex2bin($argv[2])) ? "key-ok" : "key-bad";' "$HCE_CRED" "$HCE_KEY")
check "Backend stores kind=hce + that key / El backend guarda kind=hce + la llave" "$HCE_STORED" 'hce:key-ok'

HCE_TAP=$(hce_body tap)
R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$HCE_TAP")
check "Proven phone tap resolves the student / El toque probado resuelve" "$R" '"status":"ok"'
check "Phone tap names the student / El toque nombra al estudiante" "$R" '"student_first_name":"E2E"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$HCE_TAP")
check "Replayed phone proof is refused 403 / Prueba repetida se rechaza 403" "$R" '403'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$HCE_CRED\"}")
check "Unproven phone tap is refused 403 / Toque sin prueba se rechaza 403" "$R" '"reason":"hce_auth_failed"'

HCE_CARD_ID=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Card::where("credential_uid", $argv[1])->value("id");' "$HCE_CRED")
R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/cards/$HCE_CARD_ID/revoke" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{}')
check "Revoke the lost phone / Revocar el teléfono perdido" "$R" '"key_destroyed":true'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/events/tap" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$(hce_body tap)")
check "Revoked phone tap is refused 404 / Teléfono revocado se rechaza" "$R" '404'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/students/$HCE_STUDENT_ID/arm-pairing" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d '{}')
R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/admin/cards/pair" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$(hce_body pair)")
check "Revoked phone cannot be re-keyed 422 / No se re-emite llave a un revocado" "$R" '422'

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
say "== Fase G — canal en vivo / realtime feed (TASK-016) =="
WS_PORT=8091
WS_PID=""
"$PHP_BIN" artisan realtime:serve --host=127.0.0.1 --port="$WS_PORT" >/dev/null 2>&1 &
WS_PID=$!
# Wait for the listener with the PHP TCP probe ONLY — curl's telnet://
# mode relays STDIN to the socket and hangs forever when stdin is an
# open pipe (a harness/CI session); the fsockopen probe is portable
# (works on Git Bash too) and honest.
for i in $(seq 1 30); do
    "$PHP_BIN" -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $errno, $errstr, 0.5) === false ? 1 : 0);' "$WS_PORT" 2>/dev/null && break
    kill -0 "$WS_PID" 2>/dev/null || break
    sleep 0.3
done

WS_TOKEN=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $u=App\Models\User::where("email","admin@presence.test")->first(); echo App\Services\Realtime\RealtimeToken::issue((int)$u->id);')

if "$PHP_BIN" scripts/_lib/realtime-probe.php "ws://127.0.0.1:${WS_PORT}" "$WS_TOKEN" >/dev/null 2>&1; then
    ok "Realtime feed answers hello / El canal en vivo responde hello"
else
    bad "Realtime feed answers hello / El canal en vivo responde hello"
fi

BAD_RC=0
"$PHP_BIN" scripts/_lib/realtime-probe.php "ws://127.0.0.1:${WS_PORT}" "definitely-not-a-token" >/dev/null 2>&1 || BAD_RC=$?
if [ "$BAD_RC" = "2" ]; then
    ok "Realtime rejects invalid tokens 401 / El canal rechaza tokens inválidos 401"
else
    bad "Realtime rejects invalid tokens 401 / El canal rechaza tokens inválidos 401"
fi

kill "$WS_PID" 2>/dev/null || true
wait "$WS_PID" 2>/dev/null || true
WS_PID=""

# ---------------------------------------------------------------------------
say "== Fase H - botella-primero + tablero + cuenta estudiante / bottle-first + leaderboard + student account (TASK-025) =="
# The bottle-first flow (spec sec 3 Case B): capture WITHOUT a card, then a
# card association resolves it. Uses a SECOND student for the
# association so the leaderboard has two earners.
STUDENT2_ID=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Student::where("name","like","%Carlos%")->first()->id;')
CARD2_UID=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Student::find((int)$argv[1])->cards()->first()->credential_uid;' "$STUDENT2_ID")

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/recycling/capture" \
    -H "Authorization: Bearer $RECYCLING_KEY" -H "Accept: application/json" \
    -F "image=@${IMG}")
check "Capture without a card is held / La captura sin tarjeta queda en espera" "$R" '"state":"awaiting_card"'
check "Capture says present card next / La captura pide presentar tarjeta" "$R" '"next_step":"present_card"'
CAPTURE_ID=$(grep -oE '"capture_id":[0-9]+' <<<"$R" | head -1 | grep -oE '[0-9]+')

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    -H "Authorization: Bearer $CLASSROOM_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$CARD2_UID\"}")
check "Another reader cannot associate / Otro lector no puede asociar" "$R" '403'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    -H "Authorization: Bearer $RECYCLING_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$CARD2_UID\"}")
check "Association resolves and awards / La asociacion resuelve y otorga" "$R" '"capture_state":"accepted"'
check "Boundary semantics surfaced / Semantica de frontera expuesta" "$R" '"is_recyclable"'

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE_URL/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    -H "Authorization: Bearer $RECYCLING_KEY" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "{\"credential_uid\": \"$CARD2_UID\"}")
check "A resolved capture is terminal / Una captura resuelta es terminal" "$R" '404'

# The leaderboard (spec sec 22): two earners now exist.
R=$(curl -s -w '\n%{http_code}' -X GET "$BASE_URL/api/v1/recycling/leaderboard?limit=3" \
    -H "Authorization: Bearer $PAT" -H "Accept: application/json")
check "Leaderboard ranks the earners / El tablero clasifica a los ganadores" "$R" '"rank":1'

# A student logs into their self-service desk (spec sec 11/30). The
# login POST needs the session CSRF token (session-first auth is the
# design; curl must play the same game a browser does).
COOKIE=$(mktemp)
LOGIN_PAGE=$(curl -s -c "$COOKIE" "$BASE_URL/login")
CSRF=$(grep -oE 'name="csrf-token" content="[^"]+' <<<"$LOGIN_PAGE" | sed 's/.*content="//')
R=$(curl -s -w '\n%{http_code}' -b "$COOKIE" -c "$COOKIE" -X POST "$BASE_URL/login" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "_token=$CSRF" \
    --data-urlencode "email=carlos@presence.test" \
    --data-urlencode "password=password")
check "Student login lands on /student / Login del estudiante aterriza en /student" "$R" '302'

R=$(curl -s -w '\n%{http_code}' -b "$COOKIE" "$BASE_URL/student")
check "Student desk renders / El escritorio del estudiante responde" "$R" '200'
rm -f "$COOKIE"


# ---------------------------------------------------------------------------
echo ""
echo "=================================================="
printf ' \033[1mRESULTADO / RESULT: %d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
echo "=================================================="
if [ "$FAIL" -gt 0 ]; then exit 1; fi
