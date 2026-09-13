# TASK-036 — Pulse productization follow-ups

## Date opened
2026-09-12 (RUN-2026-09-12-core-036)

## Origin
Remainder of the owner's productization pass directive: items that are
real but need either owner input or dedicated runs. Each listed with
the spec section it comes from and the surface it touches.

## Items (none blocking; not ordered)
- **Accept-Language on desk fetches** (spec §33-adjacent, i18n):
  students/readers/pairing/dashboard `postJson`/`getJson` helpers
  should send `Accept-Language` from the session locale so server-side
  API messages match the UI language (today inline boxes render EN
  under ES while toasts localize). Small: one header per helper + test.
- **Rate limiting** (spec §32, OBS-015 top residual): login throttle +
  API throttle middleware; start from the OBS-015 residual ledger in
  the TASK-027 run record.
- **Command palette** (spec §28): global quick-jump (students/classes/
  readers/EcoStation/settings). The no-build stack makes a small
  vanilla implementation viable (~150 lines); do NOT bundle a library.
- **Onboarding flow** (spec §25): setup-progress surface for fresh
  installs (org config → classes → students → readers → cards).
  Needs an honest data-source per step (what exists vs not); the
  honesty floor forbids fake progress.
- **Audit-log UI** (spec §27): roster channel already records
  student_created/imported/class_created/reader_updated; a read-only
  admin surface over roster_updates (+ pairing history) may suffice
  before any new write-path auditing.
- **`@presence.test` domain decision** (owner): rename to `pulse.test`
  is a 35+ file cross-cutting change (seeders, tests, e2e, docs);
  deliberate deferral, needs owner call.
- **Student self-redeem (R1) / trend dashboards / CV training plan /
  NFC cloning posture** — carried from TASK-030-G unchanged.

## Acceptance
Each item: focused tests → full suite → quality → e2e, one item at a
time, per the house protocol.
