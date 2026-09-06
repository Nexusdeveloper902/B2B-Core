# STATE SNAPSHOT — after RUN-2026-09-06-core-018

## Repository state

- Branch: main at the TASK-020 merge commit (feature/
  TASK-020-pairing-liveness merged --no-ff; see `git log` for the
  hash)
- Working tree: clean
- Test count: 232 passed / 3 skipped (was 223/3) — +9 pairing
  channel / invariant pins, 1 existing test strengthened
- B2B-Marketplace: clone retained read-only (styling reference)
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-017)

- **The pairing desk is a realtime page** (TASK-020, ADR-029): the
  ADR-026 WebSocket feed gained a second channel. `pending_pairings`
  row changes (arm / consume / reject) are detected via an md5
  signature (`RealtimePairing`) on the same ~300 ms poll beat and
  broadcast as `{"type":"pairing", …}` frames carrying EXACTLY
  `PairingService::statusPayload()` — the same array
  `GET /api/v1/admin/pairing/status` serves (one truth, two
  transports). The hello frame carries the pairing snapshot for
  admins. Time-only transitions (countdown, expiry) are NOT
  broadcasts — the desk's client clock owns them, and its poll
  remains the honest fallback.
- **Admin-only delivery**: pairing frames carry card UIDs (the
  admin-only REST payload), so they go to admin connections only
  (role resolved once per connection from the verified token's user
  id, fail closed). Teacher connections keep the tap channel and
  never see pairing data. Tap frames still carry no UIDs.
- **One realtime.js, two pages**: any `[data-realtime]` element
  boots the connection (dashboards' #live-list or the desk's hidden
  #pairing-realtime); feed rendering gates on #live-list; pairing
  frames dispatch `realtime:pairing` CustomEvents; the desk applies
  them through the same `applyStatus()` its poll uses. The desk page
  renders the same live/connecting/offline badge grammar and mints
  the same SSR token (AdminPairingController).
- **Single-window invariant**: `arm()` closes every other active
  window in the same transaction; `pair()` retires lingering
  pre-invariant rows. The zombie window race (double-arm → success
  shadowed by a stale window → "pairing looked broken") is
  structurally dead.
- **Clock-jump guard**: a pending row whose `created_at` is in the
  future (written by a clock the machine has since abandoned — NTP
  correction, VM resume, dual-boot RTC) is stale: not pending, not
  pairable, not countable. The arm-response countdown is clamped to
  the configured window client-side; the desk ticker finalizes
  expiry locally at 0 (no more lying at "0 s left").
- realtime-feed.md contract updated (frames, payload shape,
  admin-only delivery, testing map); README/README.es desk rows
  updated.

## Confirmed facts (cumulative, still current)

- All RUN-017 facts hold: Signal design system 1:1, honest offline
  degrade strings EN/ES, auto-reconnect, motion gate, mobile cards,
  value-match tokens
- All RUN-016 facts hold: realtime feed contract (tap frames,
  token, poll), LAN stateful serve
- All RUN-014/015 facts hold: pairing rejection stamps + desk
  honesty, Colombia timezone semantics
- Device contracts UNTOUCHED: POST /api/v1/events/tap,
  POST /api/v1/admin/cards/pair (409/422 semantics + messages
  identical — firmware needs zero changes; its e2e is unaffected)
- Dev DB reseed still rotates reader keys + card UIDs (not run this
  task; pending_pairings verified empty post-proof)
