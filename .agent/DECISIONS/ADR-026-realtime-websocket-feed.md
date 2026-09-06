# ADR-026 — Hand-rolled pure-PHP WebSocket feed (realtime:serve)

- **Status:** accepted (2026-09-06, TASK-016)
- **Context:** the owner directed "the dashboard, i want it updated to
  a websockets connection so i can see in real time when a student
  taps their card instead of having to f5." The dashboards previously
  had no tap feed at all — attendance tables were only recomputed on
  full page loads.
- **Decision:** a hand-rolled, zero-dependency WebSocket server
  (`php artisan realtime:serve`) using PHP stream sockets +
  `stream_select` and our own RFC 6455 codec
  (`app/Services/Realtime/`). The **events table is the broadcast
  source**: the server polls it (~300 ms) and pushes rows newer than
  the head to all connected dashboards. `./run serve` starts it
  alongside the web server and tears both down on Ctrl+C; the
  dashboards render the feed server-side first (SSR-first) and go
  live when the socket connects.
- **Rejected — Laravel Reverb (+ Echo/pusher-js):** first-party but
  adds composer packages, a vendored Pusher-protocol client, and (in
  the standard path) an npm build to a stack that is deliberately
  no-build, no-NPM, hermetic-toolchain (ADR-013's hand-rolled CSS is
  the precedent). For a school-scale LAN payload the dependency cost
  buys nothing the 300-line server doesn't.
- **Rejected — SSE / long-polling:** the owner asked for a WebSocket
  connection explicitly; SSE would also block the single-threaded
  `artisan serve` worker per connection.
- **Rejected — broadcasting from the tap endpoint** (event listeners
  pushing into the socket server): couples the device write path to a
  UI concern. Polling the spine keeps the tap endpoint byte-identical
  (ADR-020 invariant territory) and works for taps written by ANY
  process sharing the database (tests included).
- **Auth design:** the socket process never parses Laravel sessions
  (cross-process internals, not a stable contract). The HTTP upgrade
  must carry an HMAC-SHA256 token (`userId.expiry.signature`, keyed
  by APP_KEY) minted session-side by `GET /realtime/token` and
  embedded in the page render. Short TTL; re-minted on reconnect.
- **Consequences:**
  - We own ~300 lines of RFC 6455 (handshake, frames, ping/pong) —
    pinned by the spec's own worked test vector plus length/masking
    roundtrips, and by a real-socket integration test that boots the
    real command.
  - Sub-300 ms perceived latency at trivial cost (≈3 tiny SQLite
    queries/second).
  - The realtime server dying degrades honestly: SSR rows + an
    offline badge; devices and the API are unaffected.
  - The feed payload mirrors dashboard-visible data only (no
    credential UIDs); LAN eavesdroppers learn nothing the login wall
    doesn't already gate.
  - No permessage-deflate (we advertise no extensions — browsers
    comply); no fragmentation reassembly (browsers don't fragment
    small client frames); 1 MiB inbound frame ceiling as an abuse
    guard.
