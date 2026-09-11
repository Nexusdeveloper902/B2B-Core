# Presence Platform — API Reference (English)

> Also available in: [Español](API.es.md) · Collection: [Postman](postman_collection.json)

The core platform exposes a small, stable, versioned HTTP API. **Every
hardware-facing endpoint is a plain JSON/multipart HTTP endpoint** — anything
that can make an authenticated HTTP POST (Postman, curl, a test script, a
future ESP32) works today, and real hardware later requires **zero backend
changes**.

Base URL (local dev): `http://localhost:8000`

## Authentication models

| Endpoints | Auth | Notes |
|---|---|---|
| `POST /api/v1/events/tap`, `POST /api/v1/recycling/classify`, `POST /api/v1/admin/cards/pair` | `Authorization: Bearer <reader.api_key>` | Device-side. The key IS the reader identity — a client-supplied reader ID is never trusted. Keys are printed by the seeder. |
| `POST /api/v1/admin/readers/{id}/mode`, `PUT /api/v1/admin/readers/{id}`, `POST /api/v1/admin/readers`, `POST /api/v1/admin/readers/{reader}/rotate-key`, `POST /api/v1/admin/students`, `POST /api/v1/admin/students/import`, `POST /api/v1/admin/students/{student}/account`, `POST /api/v1/admin/classes`, `POST /api/v1/admin/students/{id}/arm-pairing`, `GET /api/v1/admin/pairing/status`, `DELETE /api/v1/admin/cards/{id}`, `POST /api/v1/students/{id}/redeem`, `GET /api/v1/admin/captures/{deposit}/image` | Session (dashboard user) or personal access token | Dashboard-side. Admin role enforced per endpoint. |
| `POST /api/v1/nl-query` | Session (dashboard user) or personal access token | Dashboard-side. **Admin AND teacher** (TASK-027): a teacher's questions are server-side fenced to their own classes (`StudentScope`); students stay 403. |

**Localization:** device-facing messages are bilingual. Send
`Accept-Language: es` for Spanish (e.g. `{"message": "Tarjeta no reconocida"}`);
English is the default and the fallback for any other language.

**Timezone (TASK-015 / ADR-025):** the platform runs on **Colombia local
time — `America/Bogota` (COT, fixed UTC−5, no DST)**. `occurred_at`
timestamps are Bogota wall time; ISO 8601 strings emitted by the API
carry the explicit `-05:00` offset. `client_timestamp` values may use
any ISO 8601 offset and are honored as-is.

**Using the dashboard from another device on your LAN (TASK-012).** The
dashboard pages and their `/api/*` fetches are session-authenticated for
same-origin "stateful" requests. Sanctum's stateful list defaults to
localhost/127.0.0.1/`APP_URL` **plus the host actually serving each
request** — so opening the dashboard from a phone on the same network
(e.g. `http://192.168.1.6:8000`) works out of the box: log in on the
phone and the "Arm pairing" button authenticates with that session.
Serve on all interfaces (`php artisan serve --host=0.0.0.0` or
`./run serve`) so the phone can reach the host. Before TASK-012 every
non-localhost origin answered `401 Unauthenticated` on the API routes
even though the web login itself had succeeded. To pin stateful access
to an explicit host list instead, set `SANCTUM_STATEFUL_DOMAINS` in
`.env` (this replaces the default entirely — include your desktop and
phone hosts) and restart the server. Device endpoints are unaffected:
readers never send a Referer/Origin, so their Bearer-key flows stay
stateless.

---

## POST /api/v1/events/tap — the core presence loop (Phase B)

Register a card tap. The reader is resolved from the Bearer key; the event
type comes from the reader's current `active_event_type`.

**Request** (JSON):

```json
{
  "credential_uid": "M9TN530AIT7N",
  "client_timestamp": "2026-09-02T07:58:00-05:00"
}
```

- `credential_uid` (required, string) — the card UID.
- `client_timestamp` (optional, ISO 8601) — future device clocks; absent →
  server time. Malformed values degrade gracefully to server time (a broken
  device clock never loses the tap).

**Responses**

`200 OK` — device feedback (drives LED/buzzer/display later):

```json
{
  "status": "ok",
  "event_id": 1042,
  "event_type": "CLASS_ATTENDANCE",
  "student_first_name": "Maria",
  "next_step": null
}
```

For a **recycling** reader, `next_step` is `"awaiting_classification"` and
`event_id` must be used in the follow-up classify call. **No points are
awarded at tap time.**

`401 Unauthorized` — missing/invalid Bearer key:
`{"status":"error","message":"Invalid bearer token"}`

`404 Not Found` — unknown card (`Card not recognized`) or non-active card
(`Card is not active`).

`422 Unprocessable Entity` — **PAE enrollment gate** (TASK-027): a valid,
active card whose student is **not enrolled in PAE** tapped a reader in
`PAE_BREAKFAST`/`PAE_LUNCH` mode. The meal is NOT recorded (keeps
`paeCount()` honest: attendance comes only from enrolled students' taps)
and the attempt is written to `storage/logs/laravel.log` for the
feeding-program audit trail:

```json
{"status":"error","reason":"student_not_pae","event_type":"PAE_BREAKFAST","message":"Ana is not enrolled in the feeding program"}
```

**Entry/exit pairs (TASK-027):** a reader in `ENTRY` mode logs every tap
as an `ENTRY` event — and the same reader in `EXIT` mode logs `EXIT`.
Multiple rows per student per day are the point (every entry is
registered); `AttendanceService::studentSessions()` pairs them on the
fly to derive time-in-school (an unmatched entry counts as an open
session). No schema change: both values ride the same `events.type` spine.

---

## POST /api/v1/admin/readers/{id}/mode — reader relabeling (Phase B)

Relabel a physical reader (e.g. the classroom reader becomes the PAE lunch
reader). **Admin role required** (teacher → 403, guest → 401). `PUT` also
accepted.

**Request**:

```json
{ "active_event_type": "PAE_LUNCH" }
```

Valid values: `CLASS_ATTENDANCE`, `PAE_BREAKFAST`, `PAE_LUNCH`,
`RECYCLING_DEPOSIT`, `ENTRY`, `EXIT` (anything else → 422).

**Response `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Demo Reader — Classroom/PAE", "type": "classroom", "active_event_type": "PAE_LUNCH" }
}
```

Renaming the reader too? Use the combined settings endpoint below
(`PUT /api/v1/admin/readers/{id}`) — one request updates the label AND
the active mode.

---

## POST /api/v1/recycling/classify — classification + points earn (Phase C)

**Auth: Bearer key of the recycling reader that owns the tap event.**
Request is `multipart/form-data`:

| Field | Type | Notes |
|---|---|---|
| `event_id` | int | From the tap response. Must belong to this reader and be a `RECYCLING_DEPOSIT` event. |
| `image` | file | Any image works for the MVP contract (classification runs behind the swappable `MaterialClassifier` interface). |

**Responses**

`200 OK`:

```json
{
  "status": "ok",
  "already_classified": false,
  "material_class": "plastic",
  "confidence": 0.87,
  "points_awarded": 10,
  "new_balance": 45
}
```

- **Idempotent:** re-submitting the same `event_id` returns `200` with
  `already_classified: true` and the original deposit values — **points are
  never awarded twice for one tap** (safe for device retries).
- Points table (config/recycling.php): plastic=10, paper=5, metal=15,
  glass=8, other=0.
- `403` — event belongs to another reader. `422` — event is not a recycling
  event / validation. `503` — classifier driver unavailable (e.g. local
  inference service down; nothing was awarded, retry later).

---

## POST /api/v1/students/{id}/redeem — points spend (Phase D)

Desk redemption. **Admin or teacher role required.**

**Request**: `{"reward_id": 2}`

**Responses**

`200 OK`:

```json
{
  "status": "ok",
  "student_id": 2,
  "reward": { "id": 2, "name": "Raffle entry", "point_cost": 20 },
  "new_balance": 5,
  "ledger_id": 7
}
```

`422` — insufficient balance, with the shortfall:

```json
{
  "status": "error",
  "message": "Insufficient points: 15 more needed",
  "current_balance": 5,
  "reward_cost": 20,
  "shortfall": 15
}
```

The `points_ledger` is append-only: every earn (+) and spend (−) is recorded;
the balance is always `SUM(delta)`, never a mutable counter.

---

## POST /api/v1/admin/students/{id}/arm-pairing — arm a card pairing (TASK-010, admin-only)

First step of the two-step pairing flow (ADR-020): arm a short-lived
**pending pairing** for a student. The next **fresh** card scanned at any
reader within the window is linked to that student (device side below).

The window is **45 seconds** by default (`PAIRING_WINDOW_SECONDS`,
see `config/presence.php`) — long enough to walk to the reader, short
enough that stray open sessions do not linger. If two students are armed
at the same time, the **most recent** armed pairing wins (the desk flow is
sequential by nature). Arming again simply creates a newer pairing.

**Request**: empty JSON body — the student comes from the URL.

**Response `200`**:

```json
{
  "status": "ok",
  "student_id": 3,
  "expires_at": "2026-09-05T14:02:31+00:00"
}
```

`401`/`403` — guest / non-admin (teacher cannot arm). `404` — unknown student.

> **Dashboard shortcut (TASK-011)**: the admin dashboard has a **Pair
> cards** page (`/admin/pairing`, admin session) with one-click **Arm
> pairing** buttons per student — the buttons call THIS endpoint with
> your logged-in session, so no PAT and no curl are needed. It polls
> `GET /api/v1/admin/pairing/status` (below) and shows the live
> countdown, the moment the card gets paired, and the recent history.

## GET /api/v1/admin/pairing/status — pairing desk state (TASK-011, admin-only, read-only)

Read-only state for the dashboard pairing desk: which session is armed
right now (if any), the last completed pairing, and the 8 most recent
completions. The page polls this every ~2 s while a session is armed, so
the operator sees the card→student link the moment the reader consumes
the session — no serial monitor needed.

**Response `200`** (nothing armed, nothing paired yet):

```json
{
  "status": "ok",
  "pending": null,
  "last_pairing": null,
  "recent_pairings": []
}
```

**Response `200`** (session armed; one card paired historically):

```json
{
  "status": "ok",
  "pending": {
    "student_id": 3,
    "student_name": "Maria González",
    "expires_at": "2026-09-05T14:03:41+00:00",
    "seconds_left": 23,
    "last_rejection": {
      "card_uid": "62041607",
      "reason": "already_paired",
      "at": "2026-09-05T14:03:12+00:00"
    }
  },
  "last_pairing": {
    "card_uid": "62041607",
    "student_name": "Carlos Pérez",
    "paired_at": "2026-09-05T13:58:02+00:00",
    "reader_label": "Demo Reader — Classroom/PAE"
  },
  "recent_pairings": [
    {
      "card_uid": "62041607",
      "student_name": "Carlos Pérez",
      "paired_at": "2026-09-05T13:58:02+00:00",
      "reader_label": "Demo Reader — Classroom/PAE"
    }
  ]
}
```

`pending` is `null` when nothing is armed (or the window already
expired). `pending.last_rejection` (TASK-014) is `null` until a pair
tap on this window is REJECTED — a `422 already_paired` answer to the
reader also stamps the armed session, so the desk can SHOW the rejected
UID, the reason, and the remediation (tap a different card or run
`./run unpair`) instead of counting down in silence; the window stays
armed, so a genuinely fresh card can still complete it. A tap with no
armed session answers `409` to the reader and stamps nothing (there is
no window to report on). `recent_pairings` entries come from completed
pairings whose `pending_pairings.card_id` audit column (TASK-011)
points at the exact `cards` row — seeded demo cards (fabricated by the
seeder, never paired) never appear here. `401`/`403` — guest /
non-admin. This endpoint never writes: arming stays a POST, pairing
stays reader-side.

## POST /api/v1/admin/cards/pair — pair a scanned card (TASK-010, device-side)

Second step: the reader (any reader — the path lives under `/admin/` for
discoverability, but authentication is the **reader Bearer key**, exactly
like the tap endpoint) submits a freshly scanned card UID. The most
recent unconsumed, unexpired pending pairing is consumed and the card is
linked to its student.

**Request** (JSON):

```json
{ "credential_uid": "A1B2C3D4E5" }
```

**Response `200`**:

```json
{
  "status": "ok",
  "paired_student_name": "Maria González",
  "student_id": 3
}
```

`409 Conflict` — no active pairing session (none armed, expired, or already
consumed): `{"status":"error","message":"No pairing session active"}`.

`422` — the `credential_uid` is already linked to an existing card row (any
status — a replacement card is a NEW credential; existing cards are never
reassigned): `{"status":"error","message":"Card already paired"}`. The
pending pairing **stays armed** so the operator can immediately scan a
different fresh card.

`401` — missing/invalid reader Bearer key. A pairing is one-shot: after a
successful pair, the next scan gets the 409. The newly paired card works
immediately for taps on the tap endpoint.

---

## POST /api/v1/nl-query — natural-language query (Phase E, admin + teacher)

**Request**: `{"question": "How many kids were late this week?"}`

**Roles (TASK-027):** admin (school-wide) AND teacher. A teacher's
questions are fenced **server-side** to the classes they teach — every
function execution applies the caller's `StudentScope`; a class or
student outside the wall answers with an explicit scope error, never
with data. Students stay 403.

Flow: the question + a fixed set of function schemas goes to the DeepSeek
model (default `deepseek-v4-flash`) → the model **selects a
function** → the backend executes the **real
Eloquent query** → the result returns to the model → the model phrases the
final answer. The LLM never computes or fabricates numbers. Answers are
**concise by contract** (at most three short sentences or a compact
bullet list) and use **light Markdown** (`**bold**`, `- ` bullets,
`` `backticks` ``) — the dashboards render it via `public/js/markdown.js`
(escaped-first, never raw HTML).

Callable functions: `get_attendance_count(date, class_id?)`,
`get_pae_count(meal, date, class_id?)`,
`get_recycling_totals(date_from, date_to)` (school-wide by design —
public competition board, spec §22),
`get_student_timeline(student_id)`, plus the **analytical half
(TASK-027)**: `get_absence_count(date, class_id?)`,
`get_absent_students(date, class_id?)`, `get_late_count(date,
class_id?)`, `get_attendance_trend(days)`,
`get_repeatedly_absent_students(days, min_absences, class_id?)`,
`get_student_time_in_school(student_id, days)`, and
`find_student(name)` (resolves a partial name to a `student_id` first).

**Responses**

`200 OK`:

```json
{
  "status": "ok",
  "answer": "Three students attended class today.",
  "functions_called": [{ "name": "get_attendance_count", "args": { "date": "2026-09-02" } }]
}
```

`503` — **honest blocker** — each refusal class has its own actionable
`blocked_reason` (per DeepSeek's documented error contract):

| `blocked_reason` | Meaning | Fix |
|---|---|---|
| `missing_llm_credential` | No `DEEPSEEK_API_KEY` in `.env` | Add the key, then `./run llm-check` |
| `llm_invalid_key` | DeepSeek rejected the key (401 Authentication Fails) | Create a fresh key at platform.deepseek.com, update `.env` |
| `llm_insufficient_balance` | 402 — the key is valid but the account balance is empty (pay-as-you-go) | Top up at platform.deepseek.com |
| `llm_model_not_found` | `DEEPSEEK_MODEL` unknown for this account/API (404 Model Not Exist) | Use the default `deepseek-v4-flash` |
| `llm_rate_limited` | Rate limit reached (429) | Retry later |
| `llm_unavailable` | Transport/server error | Retry; see `storage/logs/laravel.log` for the raw cause |

```json
{
  "status": "blocked",
  "blocked_reason": "missing_llm_credential",
  "message": "Natural-language query is not configured: no DEEPSEEK_API_KEY set (blocked, not failed)."
}
```

Run `./run llm-check` on the machine making the calls — it performs one
bare live request with the same key + model and prints DeepSeek's exact
verdict with bilingual fix guidance.

---

## PUT /api/v1/admin/readers/{id} — reader settings: name + mode (TASK-027, admin-only)

The backing endpoint of the `/admin/readers` management desk (the page
lost in the frontend redesign, now restored): rename a reader AND switch
its active mode in ONE request. **Admin role required** (teacher → 403,
guest → 401). The mode-only endpoint above stays untouched — its contract
is pinned by tests.

**Request**:

```json
{ "label": "Aula 12 — Entrada", "active_event_type": "ENTRY" }
```

`label`: required, 3–255 chars. `active_event_type`: required, same
valid values as the mode endpoint.

**Response `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Aula 12 — Entrada", "type": "classroom", "active_event_type": "ENTRY" }
}
```

`422` — validation errors (short label, unknown mode).

---

## POST /api/v1/admin/readers — create one reader (TASK-030-B, admin-only)

Reader provisioning without SQL: the `/admin/readers` desk creates the
row through this endpoint. **Admin role required.** The API key is
NEVER accepted from the client — the server generates a 32-char key
(the seeder's standard) and returns it EXACTLY ONCE (`api_key` +
`api_key_notice`, the student-login display-once rule). The reader
object, roster frames, logs and every later response never carry it.
Creation announces a `reader_created` roster frame (same transaction).

**Request**:

```json
{ "label": "Aula 12 — Entrada", "type": "entry", "active_event_type": "ENTRY" }
```

`label`: required, 3–255 chars. `type`: required, one of `classroom`
`pae` `recycling` `entry`. `active_event_type`: required, any event
type (same set as the settings endpoint).

**Response `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 7, "label": "Aula 12 — Entrada", "type": "entry", "active_event_type": "ENTRY" },
  "api_key": "…32 chars, shown here and never again…",
  "message": "Reader Aula 12 — Entrada created.",
  "api_key_notice": "API key (copy it now — it is never shown again)"
}
```

`422` — validation errors (nothing is created, no frame is written).

---

## POST /api/v1/admin/readers/{reader}/rotate-key — rotate one key (TASK-030-B, admin-only)

**Admin role required.** Lost-key / suspected-leak recovery without
SQL: replaces the key and returns the new one EXACTLY ONCE. The old key
401s from that moment (the deployed reader stops working until it is
re-flashed — the desk confirms before calling). The rotation is logged
(reader id + admin id, never key material); no roster frame is written
(no displayed state changes).

**Response `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 7, "label": "Aula 12 — Entrada" },
  "api_key": "…32 fresh chars, shown here and never again…",
  "message": "API key rotated for Aula 12 — Entrada.",
  "api_key_notice": "API key (copy it now — it is never shown again)"
}
```

`404` — unknown reader.

---

## POST /api/v1/admin/students — create one student (TASK-027, admin-only)

The end of hand-written SQL INSERTs: the `/admin/students` desk creates
students through this endpoint. **Admin role required.**

TASK-030-A (ADR-044) — enrollment mints the login: the 1:1 student
account is provisioned in the SAME transaction (convention email
`{firstname}@presence.test` + shared initial password + forced
first-login rotation). The response carries the credentials EXACTLY
ONCE (`account` + `account_notice` — the reader-API-key display-once
rule); roster frames carry only the email, never the password.

**Request**:

```json
{ "name": "Nueva Estudiante", "grade": "5°", "class_id": 1, "pae_enrolled": true }
```

**Response `200`**:

```json
{
  "status": "ok",
  "student": { "id": 9, "name": "Nueva Estudiante", "grade": "5°", "class_name": "5° B", "pae_enrolled": true, "account_email": "nueva@presence.test" },
  "account": { "email": "nueva@presence.test", "temporary_password": "password", "must_change_password": true },
  "message": "Student Nueva Estudiante created.",
  "account_notice": "Login ready: nueva@presence.test / initial password password — it must be changed on first login"
}
```

`422` — validation errors, or `{"status":"error","reason":"duplicate"}`
for the same name in the same class (a duplicate is never a silent skip;
no account is minted for rejected rows).

---

## POST /api/v1/admin/students/import — CSV roster bulk import (TASK-027, admin-only)

**Admin role required.** Multipart request: `file` (CSV, max 2 MB, up to
500 rows). Header row REQUIRED — columns are case-insensitive, order
free, extra columns ignored:

```csv
name,grade,class,pae_enrolled
María Pérez,5°,5° B,yes
```

`class` resolves by class NAME (the human workflow); `pae_enrolled`
accepts `yes`/`no`/`true`/`false`/`1`/`0`/`si`/`sí`. Row-level failures
are reported per row (row number + bilingual message) — a bad row never
blocks the good ones; duplicates are row errors.

**Response `200`**:

```json
{
  "status": "ok",
  "created": 2,
  "failed": 0,
  "errors": [],
  "students": [{ "id": 9, "name": "María Pérez", "class_name": "5° B", "account_email": "maria@presence.test" }],
  "accounts_created": 2,
  "message": "Import finished: 2 created, 0 failed."
}
```

`status` is `ok` (all rows in), `partial` (some in, some failed), or
`error` + 422 (nothing created — bad header, no data rows, unreadable
file). TASK-030-A: every created row leaves with a login
(`account_email` per row, `accounts_created` total); failed rows mint
nothing. Same-first-name collisions disambiguate (`maria@…`,
`maria2@…`).

---

## POST /api/v1/admin/students/{student}/account — backfill one login (TASK-030-A, admin-only)

**Admin role required.** One-click login for pre-TASK-030 rows (or any
account-less student): idempotent — a student that already has an
account keeps it (`already: true`, and the temporary password is NOT
re-issued). A freshly minted account carries the display-once pair.

**Response `200` (fresh)**:

```json
{
  "status": "ok",
  "already": false,
  "account": { "email": "legado@presence.test", "temporary_password": "password", "must_change_password": true },
  "message": "Login ready: legado@presence.test / initial password password — it must be changed on first login"
}
```

**Response `200` (existing)**: `{"status":"ok","already":true,
"account":{"email":"…"},"message":"This student already has a login
(…)"}`.

First login with the initial password lands on the student's dashboard
and is immediately bounced to `GET /password/change`: the account must
rotate to a personal password (current-password check, minimum 8,
confirmed) before any other page opens. JSON callers receive `403`
`password_change_required` instead of the redirect.

---

## POST /api/v1/admin/classes — create one class (TASK-029, admin-only)

The roster workflow's missing first step: the `/admin/students` desk's
class SELECT was read-only before — creating a class needed hand-written
SQL. **Admin role required.** Teacher assignment is OPTIONAL (a class
can exist before its homeroom teacher is chosen; only `role: teacher`
users may be assigned).

**Request**:

```json
{ "name": "6° A", "teacher_user_id": 2 }
```

**Response `200`**:

```json
{
  "status": "ok",
  "class": { "id": 5, "name": "6° A", "teacher_name": "Prof. Elena Ramírez" },
  "message": "Class 6° A created."
}
```

`422` — validation errors, or `{"status":"error","reason":"duplicate"}`
for an existing name (case-insensitive, mirroring the student rule).
Every committed create writes one `class_created` roster frame in the
same transaction (see Realtime roster frames below) — the students
desk's class select goes live the moment the class exists.

---

## DELETE /api/v1/admin/cards/{id} — per-card unpair (TASK-027, admin-only)

The GUI half of gap D1: the pairing desk's roster carries an **Unpair**
button per credential; this endpoint backs it. **Admin role required.**
Semantics mirror the bulk `cards:unpair` command (ADR-023) at single-card
granularity — "fresh" means the row does not exist, so unpairing DELETES
the cards row (never nulls `student_id` — a nulled row would still block
re-pairing). Tap events cascade with the card; `pending_pairings`
history rows survive with their card link cleared (audit trail). One
transaction, so the outcome is deterministic.

**Response `200`**:

```json
{
  "status": "ok",
  "unpaired": { "credential_uid": "M9TN530AIT7N", "student_name": "Maria González", "events_deleted": 3, "history_links_cleared": 1 },
  "message": "Card unpaired from Maria González — the credential is fresh again"
}
```

After a successful unpair the SAME credential can be paired again
immediately (the bench loop: pair → unpair → re-pair).

---

## GET /api/v1/admin/captures/{deposit}/image — stream a stored capture (TASK-027, gap E1, admin-only)

Capture images live on the **private** `local` disk
(`storage/app/private`) as audit artifacts and may contain students —
they are never put on a public disk. This admin-authed route is the
single authorized door (the EcoStation page fetches it same-origin with
the session cookie; a teacher/student request 403s at the role wall
before any byte of the file is read).

**Response `200`** — the image bytes (streamed, inline,
`Cache-Control: private, max-age=60`).

`404` — the deposit has no stored image, or the file is missing on disk.

---

## Error conventions

All errors return JSON with `{"status": "error", "message": "..."}` and an
accurate HTTP code (401/403/404/422/503). Validation errors (422) additionally
include Laravel's `errors` object. Messages localize via `Accept-Language`
(en/es).

## Demo credentials

Run `php artisan migrate --seed`. The seeder **prints** (bilingual EN/ES):

- Dashboard users: `admin@presence.test` / `teacher@presence.test` — password `password`
- One `credential_uid` per student
- One Bearer `api_key` per reader

These values are re-printed on every seed run — copy them straight into
Postman collection variables.


---

## POST /api/v1/recycling/capture — bottle-first image intake (TASK-025)

**Auth: Bearer key of a recycling reader (the camera station).**
Request is `multipart/form-data`:

| Field | Type | Notes |
|---|---|---|
| `image` | file | The captured image. Stored for the audit trail (storage disk `local`, `recycling-captures/`). |

Spec §3 Case B: a bottle placed BEFORE any card. The image is held in
state `awaiting_card` for `RECYCLING_CAPTURE_TTL` seconds (default 300).
**No classifier call happens here** — the cost gate (spec §4) forbids
any vision-API call before a student is associated.

`200 OK`:

```json
{
  "status": "ok",
  "capture_id": 12,
  "state": "awaiting_card",
  "expires_in": 300,
  "next_step": "present_card"
}
```

`422` — reader is not a recycling reader / validation.

---

## POST /api/v1/recycling/captures/{capture}/associate — card resolves the capture (TASK-025)

**Auth: Bearer key of the SAME recycling reader that stored the capture.**
Request is JSON:

```json
{"credential_uid": "A1B2C3D4E5F6"}
```

One call does the whole resolution: validates the card, creates the tap
event, classifies the stored image, awards points, marks the capture
`accepted`.

`200 OK`:

```json
{
  "status": "ok",
  "capture_id": 12,
  "capture_state": "accepted",
  "event_id": 88,
  "already_classified": false,
  "material_class": "plastic",
  "confidence": 0.91,
  "is_bottle": true,
  "is_recyclable": true,
  "points_awarded": 10,
  "new_balance": 45
}
```

- An unknown/inactive card returns `404` (device-displayable message) and
  **keeps the window open** — tap the right card and retry.
- `403` — the capture belongs to another reader. `404` — no usable capture
  (expired / already resolved). `503` — classifier unavailable (the capture
  stays resolvable; retry).

---

## GET /api/v1/recycling/leaderboard — ranking (TASK-025)

**Auth: session or PAT; admin, teacher, or student role.**
Query: `?limit=N` (default 10, max 100).

`200 OK`:

```json
{
  "status": "ok",
  "entries": [
    {"rank": 1, "student_id": 3, "student_name": "Carlos Pérez", "class_name": "5° B", "points": 25}
  ],
  "me": {"rank": 2, "points": 10, "student_id": 1, "student_name": "Maria González"}
}
```

`me` appears only for student accounts (resolved from the account, never a
parameter). Ranking derives exclusively from the points ledger; ties share
a rank (competition ranking: 1, 2, 2, 4).

---

## Realtime recycling frames (TASK-025)

The WebSocket feed (`realtime:serve`) now pushes `recycling` frames to
every authenticated connection (same token/handshake as tap frames):

```json
{"type": "recycling", "update": {"id": 7, "type": "points_awarded",
 "payload": {"student_id": 1, "student_name": "Maria González", "points": 10, "new_balance": 45},
 "at": "2026-09-07 10:15:03"}}
```

Frame types: `capture_created`, `validation_started`, `validated`,
`points_awarded`, `reward_redeemed`, `leaderboard_updated`. Rows are
written inside the same DB transaction as the state change they describe,
so a frame only ever reflects committed state. The hello frame carries the
recent snapshot under `recycling`.

**Per-connection scope (TASK-027):** the tap channel is now fenced per
connection, resolved once at handshake (fail closed): teachers see only
taps of students in THEIR classes, student accounts see only their OWN
taps, admins see the whole school. The hello snapshot honors the same
scope, and the EcoStation page consumes `recycling` frames live
(`realtime:recycling` CustomEvents — ledger rows, impact metrics and the
latest-capture panel update with no reload).

**Realtime roster frames (TASK-029):** roster changes broadcast on a
fourth channel, `roster` — admin connections only (the payloads mirror
the admin REST endpoints' exposure; same wire discipline as pairing
frames):

```json
{"type": "roster", "update": {"id": 3, "type": "student_created",
 "payload": {"id": 9, "name": "Nueva Estudiante", "grade": "5°", "class_id": 1, "class_name": "5° B", "pae_enrolled": false},
 "at": "2026-09-09 08:00:00"}}
```

Frame types: `student_created` (one row), `students_imported`
(`payload.students[]` — one frame per import), `class_created`, and
`reader_updated` (written by BOTH reader write endpoints — the settings
PUT and the mode-only POST). Rows ride the same transaction as the
change they describe; the admin hello carries the recent snapshot under
`roster` so a freshly connected page reconciles. The students desk
prepends live roster rows and adds class options; the readers desk and
the admin dashboard's readers table repaint reader changes.

Student self-service web desk (TASK-025): students log in (same login page;
demo accounts printed by the seeder, e.g. `carlos@presence.test` /
`password`) and land on `/student`, `/student/history`, `/student/rewards`
— server-side scoped to their own data only.

