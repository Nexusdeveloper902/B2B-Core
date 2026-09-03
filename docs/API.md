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
| `POST /api/v1/events/tap`, `POST /api/v1/recycling/classify` | `Authorization: Bearer <reader.api_key>` | Device-side. The key IS the reader identity — a client-supplied reader ID is never trusted. Keys are printed by the seeder. |
| `POST /api/v1/admin/readers/{id}/mode`, `POST /api/v1/students/{id}/redeem`, `POST /api/v1/nl-query` | Session (dashboard user) or personal access token | Dashboard-side. Admin/teacher roles enforced per endpoint. |

**Localization:** device-facing messages are bilingual. Send
`Accept-Language: es` for Spanish (e.g. `{"message": "Tarjeta no reconocida"}`);
English is the default and the fallback for any other language.

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
`RECYCLING_DEPOSIT`, `ENTRY` (anything else → 422).

**Response `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Demo Reader — Classroom/PAE", "type": "classroom", "active_event_type": "PAE_LUNCH" }
}
```

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

## POST /api/v1/nl-query — natural-language query (Phase E, admin-only)

**Request**: `{"question": "How many kids were late this week?"}`

Flow: the question + a fixed set of function schemas goes to the Gemini
flash-lite model (default `gemini-3.1-flash-lite`) → the model **selects a
function** → the backend executes the **real
Eloquent query** → the result returns to the model → the model phrases the
final answer. The LLM never computes or fabricates numbers.

Callable functions: `get_attendance_count(date, class_id?)`,
`get_pae_count(meal, date)`, `get_recycling_totals(date_from, date_to)`,
`get_student_timeline(student_id)`.

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
`blocked_reason` (per Google's documented error contract):

| `blocked_reason` | Meaning | Fix |
|---|---|---|
| `missing_llm_credential` | No `GEMINI_API_KEY` in `.env` | Add the key, then `./run llm-check` |
| `llm_invalid_key` | Google rejected the key (400 `API_KEY_INVALID` / 401 / 403) | Create a fresh key in AI Studio, update `.env` |
| `llm_region_unsupported` | "User location is not supported" — the key is valid; Google refuses the region | Run `./run llm-check` from this machine; see Google's Available regions page |
| `llm_model_not_found` | `GEMINI_MODEL` unknown for this account/API (404) | Use the default `gemini-3.1-flash-lite` |
| `llm_rate_limited` | Quota exhausted (429) | Retry later |
| `llm_unavailable` | Transport/server error | Retry; see `storage/logs/laravel.log` for the raw cause |

```json
{
  "status": "blocked",
  "blocked_reason": "missing_llm_credential",
  "message": "Natural-language query is not configured: no GEMINI_API_KEY set (blocked, not failed)."
}
```

Run `./run llm-check` on the machine making the calls — it performs one
bare live request with the same key + model and prints Google's exact
verdict with bilingual fix guidance.

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
