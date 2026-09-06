# ADR-029 — Pairing channel on the realtime feed + single-window pairing invariant

- **Status:** accepted (2026-09-06, TASK-020)
- **Context:** the owner reported two pairing-desk ills from the
  bench: (1) an armed window that read "Armed for Maria González —
  12468 s left" (the window is 45 s), and (2) an intermittent race —
  "when i do a new pairing after the broken one it is fine but some
  other times on the first try it seems to work properly" — and asked
  for websockets on the pairing page so it auto-updates when a card
  is paired.
- **Investigation (reproduced on a throwaway DB):**
  - The **12468 s** countdown is a *stale far-future row*, not a code
    path writing a huge window: `arm()` only ever writes
    `now() + 45 s`. A row whose `expires_at` is hours in the future
    can only exist when the machine's clock moved BACKWARD after the
    row was written (NTP correction, VM resume, dual-boot RTC) — or
    when the browser clock runs ahead of the server on the arm
    response's `Date.parse` math. Either way the desk faithfully
    rendered a lie the row told.
  - The **race** is the *zombie window*: `arm()` never superseded a
    prior active window, so a double-arm (impatient double-click, a
    re-arm from another tab) left two live rows; `pair()` consumed
    the newest, and the older unconsumed row then resurfaced as
    "the" active window — the desk kept counting down and NEVER
    showed the pairing that had just succeeded. Single arm = works
    (exactly the reported "some other times on the first try it
    seems to work properly").
- **Decision 1 — single-window invariant:** `PairingService::arm()`
  atomically closes every other still-active window (same
  transaction as the insert), and `pair()` retires any lingering
  pre-invariant rows it consumes alongside. "Newest active" and
  "the window" are now the same row, always. No schema change; the
  superseded row stays in the table as an honest expired record.
- **Decision 2 — clock-jump guard:** `PendingPairing::scopeActive()`
  and `isActive()` additionally require `created_at <= now()`.
  `created_at` is stamped by the same `now()` that computed
  `expires_at`, so a legitimate row always satisfies it (second-floor
  precision keeps it `<=`); a row that fails it was written by a
  clock the machine no longer has and is stale by definition — it
  cannot arm the desk, count down, or consume a card. Client-side,
  the arm-response countdown is clamped to the configured window, so
  a skewed browser clock cannot paint an absurd number either.
- **Decision 3 — the pairing channel (the websockets ask):** the
  ADR-026 feed gains a second channel with the same rules:
  `pending_pairings` row changes (arm / consume / reject) are
  detected by a cheap md5 **signature** over the mutable columns
  (`RealtimePairing`) and broadcast as `{"type":"pairing", ...}`
  frames carrying EXACTLY `PairingService::statusPayload()` — the
  same array `GET /api/v1/admin/pairing/status` serves. One truth,
  two transports: the desk's poll path and its WebSocket path can
  never disagree. Time-only transitions (countdown draining, a
  window expiring) are deliberately NOT broadcasts — the desk's own
  client clock owns the passing seconds (it now finalizes expiry
  locally at 0 instead of lying at "0 s left"), and the poll remains
  the honest fallback whenever the socket is down.
- **Privacy floor:** pairing frames carry card UIDs (they mirror an
  admin-only REST endpoint), while tap frames deliberately never do.
  So the frame is delivered to **admin connections only** — the
  role is resolved once per connection from the verified token's
  user id, failing closed. Teacher dashboards keep the tap channel
  and never see pairing data, exactly as before.
- **Rejected — a second WebSocket server/port for pairing:** one
  feed, two channels; the token, reconnect, badge-honesty and
  degrade machinery are shared, and `./run serve` keeps starting a
  single realtime process.
- **Rejected — broadcasting on a timer (snapshot every poll):** row
  changes are the events; time is not. Signature polling keeps the
  wire quiet during a countdown and the payload always
  state-relevant.
- **Rejected — pushing frames from the arm/pair endpoints (event
  listeners):** same coupling argument as ADR-026 — the device write
  path stays decoupled; any process sharing the database is a
  broadcaster (tests included).
- **Consequences:**
  - The pairing desk is a realtime page: same `realtime.js` client
    (boot node `[data-realtime]`, feed rendering gated on
    `#live-list`, `realtime:pairing` CustomEvent), same badge
    grammar, same SSR-first render. Zero new CSS (the Signal
    components already exist).
  - One test strengthened (`the_most_recent_armed_pairing_wins` now
    asserts the invariant), everything else additive: supersede,
    clock-jump, signature/payload, wire broadcast, teacher privacy,
    desk wiring, e2e double-arm leg.
  - The zombie and the absurd countdown are structurally dead; the
    desk's success line can no longer be shadowed.
