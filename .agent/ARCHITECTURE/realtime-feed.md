# Realtime feed — WebSocket contract (TASK-016, ADR-026; pairing channel TASK-020, ADR-029)

The dashboards update live: when a student taps a card, every open
dashboard sees the tap within ~300 ms — no F5. Since TASK-020 the
pairing desk is live too: a card paired at the reader (or a window
armed at any desk) reaches every open pairing desk within one poll
beat. This document is the protocol contract.

## Topology

```
reader (ESP32/curl)                any process writing taps
      │ POST /api/v1/events/tap           │ INSERT INTO events
      ▼                                    ▼
 artisan serve (web, :8000)  ──▶  events table (SQLite)  ◀── realtime:serve polls
                                            │  (every ~300 ms, id > head)
 dashboards (browser)  ◀──────── ws://<lan-host>:8081 ◀─┴─ broadcast frames
```

- **`php artisan realtime:serve`** (started by `./run serve` in the
  background; standalone works too) — hand-rolled pure-PHP WebSocket
  server, zero composer/npm dependencies (ADR-026): `stream_select`
  loop + RFC 6455 framing (`app/Services/Realtime/`).
- The **`events` table is the broadcast source** — the same event-type
  spine every dashboard derivation reads. The tap write path has NO
  new coupling; the WS process dying changes nothing for devices.
- Config (`config/realtime.php`, all env-overridable): port 8081,
  host, poll 300 ms, history 20, token TTL 900 s. `B2B_REALTIME_PORT`
  and `B2B_REALTIME=0` are honored by `./run serve`.

## Authentication

The HTTP upgrade must carry a feed token: `ws://host:8081/app?token=…`

- Token = `userId.expiry.hmac` — HMAC-SHA256 over `realtime:userId.
  expiry` keyed by `APP_KEY` (`App\Services\Realtime\RealtimeToken`).
- Minted server-side into the dashboard page render (SSR-first) and
  re-minted by **`GET /realtime/token`** (session-authed, role
  admin/teacher) on reconnect.
- Bad/expired token → plain `HTTP 401`, connection closed before any
  WS framing. No session parsing inside the socket process (sessions
  are not a stable cross-process contract; the HMAC is).

## Frames (JSON text frames)

Server → client:

- `{"type":"hello","last_id":N,"events":[…],"pairing":{…}}` — sent once
  after the 101; `events` = the recent history (oldest first,
  `history_limit` rows), the same rows the page server-renders;
  `pairing` = the pairing status snapshot (see below) so the pairing
  desk can reconcile its SSR state.
- `{"type":"tap","event":{…}}` — one per new events-table row.
- `{"type":"pairing","pending":…,"last_pairing":…,
  "recent_pairings":[…]}` — one per pending_pairings row change
  (arm / consume / reject), carrying EXACTLY the payload of
  `GET /api/v1/admin/pairing/status` (built by
  `PairingService::statusPayload()` — one truth, two transports).
  Row changes are detected by a cheap md5 signature over the mutable
  columns of the relevant rows (`RealtimePairing::signature()`);
  time-only transitions (the countdown draining, a window expiring)
  are deliberately NOT broadcasts — the desk's own client clock owns
  the passing seconds, and its poll remains the fallback truth.

Event row shape (identical in SSR and frames):

```json
{
  "id": 1042,
  "type": "CLASS_ATTENDANCE",
  "student_id": 7,
  "student_name": "Maria González",
  "class_name": "5° A",
  "reader_label": "Demo Reader — Classroom/PAE",
  "time": "07:50",
  "date": "2026-09-06"
}
```

`time`/`date` are Colombia school-local wall time (TASK-015/ADR-025).
No credential UIDs cross the wire — the feed shows what the
dashboards show, nothing more.

Client → server: only protocol frames (ping → pong, close). There are
no client messages; the feed is read-only.

Pairing frame payload shape (identical in the hello `pairing` key and
the `pairing` frames — and identical to the REST status endpoint):

```json
{
  "pending": {
    "student_id": 7,
    "student_name": "Maria González",
    "expires_at": "2026-09-06T07:15:45-05:00",
    "seconds_left": 44,
    "last_rejection": null
  },
  "last_pairing": {
    "card_uid": "E2EFRESHCARD1",
    "student_name": "Estudiante Nueva",
    "paired_at": "2026-09-06T07:14:02-05:00",
    "reader_label": "Demo Reader — Classroom/PAE"
  },
  "recent_pairings": [ { … } ]
}
```

## Client behavior (public/js/realtime.js)

- One client, two pages (TASK-020): any `[data-realtime]` element is
  a boot node — the dashboards' `#live-list` or the pairing desk's
  hidden `#pairing-realtime` div. Feed rendering gates on `#live-list`
  existing; pairing frames leave as a `realtime:pairing` CustomEvent
  the desk script applies through the same `applyStatus()` its poll
  uses.
- Badge honesty: `live` / `connecting` / `offline` states; offline
  shows "reload to see the latest taps" instead of pretending (the
  pairing desk has no hint node — its poll keeps it honest alone).
- Reconnect with exponential backoff (1 s → 15 s); token re-minted via
  `/realtime/token` when near expiry.
- `hello` re-renders the history (single rendering path with SSR);
  `tap` prepends a row (flash animation, capped at `max_rows`).
- Page hooks: `document` CustomEvent **`realtime:tap`** (detail = the
  event row) — the teacher dashboard listens and flips the student's
  attendance row to Present/Late (first tap wins, matching the
  server's earliest-event semantics) — and **`realtime:pairing`**
  (detail = the pairing payload) — the pairing desk listens and
  applies it instantly.

## The roster channel (TASK-029)

A fourth frame type rides the same wire: **`roster`** — committed
roster changes (student created, students imported, class created,
reader updated). Source: the append-only `roster_updates` table,
written INSIDE the same DB transaction as the change it describes
(the recycling channel's rule); `realtime:serve` polls rows newer than
its head and pushes `{"type":"roster","update":{id,type,payload,at}}`.
Delivery: ADMIN connections only (the payloads mirror the admin REST
endpoints' exposure — same discipline as pairing frames); the admin
hello carries the recent snapshot under `roster` so a freshly
connected page replays it through the same idempotent handlers live
frames use. Page hook: `document` CustomEvent **`realtime:roster`**
(detail = the update) — the students desk prepends roster rows and
adds class options, the readers desk and the admin dashboard's
readers table repaint reader changes.

## Degradation honesty

| Failure | Behavior |
|---|---|
| realtime:serve down / port busy | page still renders (SSR rows); badge shows Offline + hint; the pairing desk keeps polling (badge offline, desk still current within its own cadence) |
| DB briefly unavailable (`./run reset` mid-run) | server catches, purges the connection, retries next tick |
| Token expired while tab open | client re-mints via the session-authed endpoint; if the session itself died, reconnect keeps failing honestly |

## Testing map

- `tests/Unit/Realtime/*` — RFC 6455 spec vector, frame codec
  roundtrips (length forms 125/126/64-bit, masking), token issue/
  verify/tamper/expiry, feed row shaping, pairing signature/payload
  (`RealtimePairingTest`).
- `tests/Feature/Web/RealtimeTokenRouteTest` — auth + roles + shape.
- `tests/Feature/Realtime/RealtimeServerTest` — the REAL server
  process over real sockets: hello with history, live broadcast after
  a row written by another process, pairing frames on arm/consume,
  401 on bad token.
- `scripts/_lib/realtime-probe.php` — manual bench probe (exit 0 =
  hello ok, 2 = 401); used by e2e (2 checks).
