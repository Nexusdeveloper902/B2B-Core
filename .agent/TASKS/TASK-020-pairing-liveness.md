# TASK-020

Owner directive (2026-09-06): "Lets just say, idk how, i got this
Armed for Maria González — 12468 s left. Now tap a FRESH each time
i arm a pairing. also i want websockets on the pairing page so it
auto updates when a card is paired, and also for some reason when
i do a new pairing after the broken one it is fine but some other
times on the first try it seems to work properly. This seems to be
an edge case or a race condition but im not sure, please
investigate."

## Investigation (reproduced, throwaway DB, scripts outside the repo)

1. **The "12468 s left" armed window** — reproduced by planting a
   far-future `expires_at`: the desk renders whatever the row says
   (`data-seconds-left="30599"` in the repro). The code never writes
   more than the 45 s window; the row could only become far-future
   via a BACKWARD machine-clock jump after arming (NTP correction,
   VM resume, dual-boot RTC) or a browser clock ahead of the server
   on the arm response's `Date.parse` math.
2. **The intermittent race** — reproduced exactly: arm twice (a
   double-click / re-arm), tap ONE fresh card → the reader returns
   200 (the pairing SUCCEEDED) but the desk's status still reported
   `pending: Maria — 44 s left` — the superseded row was still
   active and resurfaced after the newer one was consumed. The
   desk's poll returns early while a pending exists, so the success
   line never rendered: the pairing LOOKED broken. Single-arm worked
   every time — matching the owner's "some other times on the first
   try it seems to work properly".

## Fixes (ADR-029)

- **Single-window invariant** — `PairingService::arm()` closes every
  other active window in the same transaction; `pair()` retires
  lingering pre-invariant rows. A consumed pairing can never be
  shadowed by a zombie window again.
- **Clock-jump guard** — `PendingPairing` active scope requires
  `created_at <= now()`; a row "created in the future" was written
  by a clock the machine abandoned and is stale: not pending, not
  pairable, not countable. Client-side the arm-response countdown is
  clamped to the configured window.
- **Local expiry finalize** — the desk's own ticker ends the window
  at 0 (expired line, bar hidden) instead of parking at "0 s left"
  until the next poll; a disagreeing frame simply re-arms (idempotent
  `applyStatus`).

## Feature: websockets on the pairing page (ADR-029)

- `RealtimePairing` service: md5 **signature** over the mutable
  `pending_pairings` columns + the shared **payload**
  (`PairingService::statusPayload()` — the ONE truth, also used by
  `PairingStatusController` now).
- `realtime:serve` polls the signature on the same 300 ms beat;
  arm / consume / reject changes broadcast `{"type":"pairing", …}`
  to every connected **admin** (role resolved once per connection,
  fail closed — pairing frames carry card UIDs, tap frames never do;
  teachers keep the tap channel only). The hello frame carries the
  pairing snapshot for admins.
- `realtime.js` generalized: any `[data-realtime]` element boots the
  connection; feed rendering gates on `#live-list`; new
  `realtime:pairing` CustomEvent. One client, two pages.
- The pairing desk page: same badge grammar as the dashboards, boot
  node with the SSR-minted token (AdminPairingController mints it),
  and the desk script applies frames through the same
  `applyStatus()` its poll uses. The poll stays as the honest
  fallback (WS down → desk still current on its own cadence).

## Acceptance

- [x] Reproduction script proved both bugs pre-fix and both dead
      post-fix (zombie: `pending: null` + success surfaced;
      clock-jump row: `pending: null`)
- [x] Existing suite green: one test STRENGTHENED deliberately
      (`the_most_recent_armed_pairing_wins` asserts the invariant);
      zero other existing assertions changed
- [x] +8 new tests: supersede, clock-jump (status + pair refusal),
      signature stability/arm/consume+reject, payload truth, wire
      broadcast (arm + consume frames over real sockets), teacher
      privacy (silence + tap channel intact), desk wiring, e2e
      double-arm leg → 231 total
- [x] realtime-feed.md contract updated (frames, payload shape,
      admin-only delivery, testing map)
- [x] `./run quality` PASS (shellcheck enforced) · `./run e2e` 24/24
- [x] Live-browser proof: desk updates < 1 s after the reader pairs
      a card (no reload, no poll wait); armed-in-another-tab
      appears live; countdown drains and finalizes locally; WS
      killed → badge offline + desk still current via poll; zero
      console errors
