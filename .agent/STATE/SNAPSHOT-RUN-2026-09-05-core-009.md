# STATE SNAPSHOT — after RUN-2026-09-05-core-009

## Repository state

- Branch: main at the TASK-011 merge commit (--no-ff merge of
  feature/TASK-011-dashboard-pairing-desk; see `git log` for hashes)
- Working tree: clean; no new executables (OBS-009 not triggered)
- Push: main + feature branch to origin (sandbox pushes emit no GitHub
  events per OBS-006 — CI verification stays the next dispatched run's
  job; local verification below was the merge gate)
- b2b-firmware: untouched by THIS run (a separate firmware task points
  its PAIRING.md at this page — see follow-ups)

## What the project can do now

- Everything through TASK-010 (bilingual platform, ./run suite, real
  CI, Event Ledger UI, live NL queries, card pairing endpoints).
- **Pairing is one-click now**: the admin dashboard has a **Pair
  cards** page (`/admin/pairing`) — per-student "Arm pairing" buttons
  (session-authed, NO PAT and NO curl), a live countdown, success the
  moment the reader pairs the card, and an exact recently-paired
  history. Backed by a read-only status endpoint
  (`GET /api/v1/admin/pairing/status`) and the `card_id` audit column.
- The arm-then-pair security model is unchanged: no new write path, no
  client-supplied student identity on the device plane (ADR-021).

## Merge-gate verification (this machine)

- ./run quality — PASS (Pint incl. the new files, shell syntax,
  bilingual docs parity)
- ./run test — **155 passed, 3 skipped** (141 prior + 14 new:
  PairingStatusTest 7, AdminPairingDeskTest 7), 2785 assertions
- ./run e2e — 22/22 (unchanged surface, regression-confirmed)
- DocumentationTest needles extended: API.md + API.es.md +
  postman_collection.json MUST cover the status endpoint
- 15 migrations now (2026_09_05_000002_add_card_id_to_pending_pairings)

## Bench context (owner's desk, 2026-09-05)

- Their reader key is PROVEN working (B2B-Firmware TASK-005 triage);
  their card 62041607 is fresh and ready to pair.
- New unblock path: `./run serve` → log in as admin@presence.test →
  nav "Pair cards" → arm the student → tap card at the reader →
  watch the success line land on the page.

## Next steps for whoever picks this up

1. Owner bench: drive one pairing end-to-end through the page (the
   JS countdown/polling is the part agent runs cannot verify).
2. Firmware repo: pointer task so its PAIRING.md recommends the
   dashboard over curl/PAT (cross-repo consistency).
3. Optional follow-ups (rejected/deferred, recorded in TASK-011):
   mass-pairing, reader-scoped arming, expired-row cleanup.
