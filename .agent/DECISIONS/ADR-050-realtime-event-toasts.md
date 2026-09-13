# ADR-050 — Realtime event toasts (taps, deposits, points)

## Date
2026-09-12

## Context
The owner bench-tested the reader (RC522, PAIRING mode) and reported:
"the toasts I asked for do not appear." The productization spec (§23)
is explicit: "Student taps card → access event appears → attendance
updates → activity feed updates → **relevant toast appears**", and the
EcoStation flow ends the same way. ADR-048's clause "ambient realtime
updates NEVER toast" was a mis-reading of that requirement: it treated
taps as noise, but a tap is an EXTERNAL event — nobody on the dashboard
caused it — which is precisely what an acknowledgment layer is for.
(The anti-spam clause, §12, targets toasting your OWN harmless UI
interactions; it never exempted hardware events.)

## Decision
Realtime frames now toast, scoped and guarded:

- **`realtime:tap`** on the admin dashboard: every tap → info toast
  "«student» — «event label»" (localized via `event_type_*` keys).
- **`realtime:tap`** on the teacher dashboard: CLASS_ATTENDANCE only
  (the desk's own live rows + row flips carry the rest; every-type
  would spam a page teachers keep open all day).
- **`realtime:recycling`** on the EcoStation hub: `validated` → info
  toast "Deposit classified — «material»"; `points_awarded` → success
  toast "+N PTS — «student»".
- **`realtime:recycling`** on the student hub (own frames only):
  `points_awarded` → success toast "+N PTS — well recycled!".
- Guards on every site: `document.hidden` stays silent; a 2 s
  per-student(+type) cooldown collapses reader retry bursts; the
  toast stack cap (4) and auto-dismiss bound any burst; the live feed
  and ledger remain the durable record — toasts acknowledge, the
  surfaces document.
- WS-replayed ROSTER frames (students desk) still do NOT toast: those
  replay the admin's OWN create/import actions, which already toasted
  on their fetch path — a WS toast would double-announce.

## Alternatives Considered
- Toast inside realtime.js (one site, all pages) — rejected: the
  client is a contract-frozen shared module; WHICH events deserve a
  toast is a per-page product decision (the teacher desk immediately
  needed a narrower policy than the admin dashboard).
- Toast every recycling frame type (leaderboard_updated etc.) —
  rejected: leaderboard recalcs are derivation noise, not user-visible
  state changes.

## Consequences
- ADR-048's "ambient realtime updates NEVER toast" clause is narrowed
  to "self-caused WS replays never toast" — superseded by this ADR for
  hardware events.
- The bench flow the owner exercised now works end to end: tap a card
  on the reader → the open dashboard toasts the tap within the WS
  round-trip (~300 ms).
- Pairing toasts (armed/paired/rejected/unpaired) are unchanged.

## Status
ACTIVE (narrows ADR-048 for external events; all other ADR-048 rules
stand)
