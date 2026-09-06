# STATE SNAPSHOT — after RUN-2026-09-06-core-014

## Repository state

- Branch: main at the TASK-016 merge commit (feature/
  TASK-016-realtime-dashboard merged --no-ff; see `git log` for the
  hash) — records docs commit on top
- Working tree: clean
- Test count: 211 passed / 3 skipped (was 178/3) — +33 realtime
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-013)

- **The dashboards update live** (TASK-016, ADR-026): `php artisan
  realtime:serve` (started by `./run serve`, port 8081, poll
  ~300 ms) broadcasts every new tap to all connected dashboards
  within sub-300 ms — no F5. Hand-rolled pure-PHP WebSocket server
  + RFC 6455 codec, zero composer/npm dependencies. The **events
  table is the broadcast source** — the device tap endpoint is
  byte-identical and the WS process dying degrades the dashboards
  honestly (SSR rows + Offline badge + reload hint; devices and API
  unaffected).
- Dashboards render the live-activity panel server-side first (both
  admin and teacher), then go live: badge live/connecting/offline,
  new-row prepend+flash, teacher attendance rows flip live
  (first-tap-wins, matching the server derivation).
- WS auth: HMAC-SHA256 one-time-window token (`userId.expiry.sig`,
  APP_KEY) minted session-side by `GET /realtime/token`
  (admin/teacher) into the page render; the socket process never
  parses sessions. 401 plain HTTP before any framing on bad tokens.
- `./run status` reports the realtime server row; `./run serve`
  degrades bilingually and honestly when the WS port is busy or
  B2B_REALTIME=0.

## Confirmed facts (cumulative, still current)

- Colombia school time (TASK-015/ADR-025) — live bench taps rendered
  19:31/19:32 Bogota wall clock
- Pairing desk honesty (TASK-014/ADR-024), unpair bench reset
  (TASK-013/ADR-023), LAN stateful access (TASK-012/ADR-022),
  ADR-020 pairing invariants: all unchanged
- Dev DB on this machine re-seeded post-merge → standard demo state

## Bench expectations after the owner pulls

- `git pull` + `./run serve` (nothing else) → login, open a
  dashboard, tap a paired card on any reader → the row appears
  without reloading; badge reads Live. Kill the terminal → badge
  flips to Offline honestly (page stays usable, refresh shows the
  newest taps).
- Phones on the LAN (TASK-012) get the same live feed: the client
  connects to `ws://<same-host-as-page>:8081`.
- Config knobs: `REALTIME_PORT`, `REALTIME_HOST`, `REALTIME_POLL_MS`,
  `REALTIME_HISTORY_LIMIT`, `REALTIME_TOKEN_TTL_SECONDS` env vars;
  `B2B_REALTIME=0` disables; `B2B_REALTIME_PORT` at the script layer.

## Open items

- Next: TASK-017 (UX overhaul) — owner directive of 2026-09-06,
  planned and accepted
- Deferred (unchanged): GET /api/v1/reader/me; firmware PAIRING.md
  pointers (desk rejection note, ./run unpair)
