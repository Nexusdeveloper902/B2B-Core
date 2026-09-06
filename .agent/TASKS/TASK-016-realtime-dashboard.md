# TASK-016-realtime-dashboard

Owner directive (2026-09-06): "the dashboard, i want it updated to a
websockets connection so i can see in real time when a student taps
their card instead of having to f5."

## Decision (ADR-026)

A hand-rolled, zero-dependency pure-PHP WebSocket server
(`php artisan realtime:serve`) broadcasts tap events to the
dashboards. The `events` table IS the broadcast source: the server
polls it every ~300 ms and pushes new rows — the event-type spine
stays the single source of truth, the tap write path gains zero new
coupling, and the WS process can die without affecting any device
flow (the page degrades honestly to its server-rendered state).
Laravel Reverb + Echo was rejected: it adds composer + vendored-JS
dependencies and a Pusher protocol to a deliberately no-build,
no-NPM, hermetic-toolchain LAN stack (ADR-013/ADR-010 ethos) for a
school-scale payload.

Auth: the WS handshake requires an HMAC-SHA256 one-time-window token
(`userId.expiry.signature`, signed with APP_KEY) minted by
`GET /realtime/token` (session-authed, admin/teacher) and embedded
server-side in the dashboard — no session parsing in the socket
process, no cookie coupling across ports, LAN-eavesdropper-proof.

## Deliverables

1. `config/realtime.php` — port (8081), host, poll ms, history size,
   token TTL.
2. `app/Services/Realtime/` — `WsFrame` (RFC 6455 codec), `Handshake`
   (accept-key, request parse, responses), `RealtimeToken` (issue/
   verify), `RealtimeFeed` (events-after / recent, joined payload).
3. `app/Console/Commands/RealtimeServeCommand.php` — stream_select
   loop: accept + authenticate upgrades, hello frame with history,
   poll+broadcast taps, ping/pong/close handling, SQLite
   reconnect-on-error.
4. `GET /realtime/token` + `RealtimeTokenController` (web routes,
   auth + role admin/teacher).
5. Dashboard UI: live-activity feed panel on admin + teacher
   dashboards (server-rendered initial rows — SSR-first convention —
   then live), `public/js/realtime.js` (no-build client: connect,
   badge LIVE/connecting/offline, prepend+flash rows, auto-reconnect
   with token refresh, CustomEvent `realtime:tap` for page hooks),
   teacher attendance rows update live via student_id.
6. `./run serve` starts the WS server alongside the web server
   (background child + trap cleanup + honest bilingual degrade when
   the port is busy); `./run status` gains a realtime row;
   `scripts/_lib/realtime-probe.php` + 2 new e2e checks (24 total).
7. Tests: WsFrame (roundtrips, lengths 125/126/65536, masking),
   Handshake (RFC 6455 spec vector), RealtimeToken (verify/expiry/
   tamper), RealtimeFeed (payload shape), token route (auth/role/
   shape), **RealtimeServerTest — real sockets against the real
   command process** (hello + history, live broadcast after a new
   row, 401 on bad token).
8. Docs: SCRIPTS.md/.es.md (serve realtime line + env knobs),
   `.agent/ARCHITECTURE/realtime-feed.md` (protocol contract).
9. `.agent` records: ADR-026, this file, RUN + ledger, STATE, PROJECT.

## Acceptance

- [x] Real-socket integration test green (hello, broadcast, 401)
- [x] `./run e2e` 24/24 (realtime probe checks included)
- [x] Dashboards render the feed server-side (works with WS down) and
      go live when it connects (browser-verified in RUN record)
- [x] Device protocol byte-identical — tap endpoint untouched (empty diff)
- [x] `./run serve` boots both servers; Ctrl+C tears both down (SIGINT
      test: both ports open → both closed; sandbox web-child env note
      recorded in the RUN record — owner unaffected)
- [x] Full suite + quality PASS (211/3); fresh-clone green

## Out of scope (deliberately)

- Pairing-desk over WS (its 2 s/15 s polling is TASK-014-honest).
- Reconnecting a PAE/recycling "already counted" indicator (feed only
  reports what happened; derivations stay on the dashboard query).
- Any per-device push (readers are HTTP-only by design).
