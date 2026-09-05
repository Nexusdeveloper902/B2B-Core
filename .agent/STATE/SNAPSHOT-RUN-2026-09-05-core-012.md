# STATE SNAPSHOT — after RUN-2026-09-05-core-012

## Repository state

- Branch: main at the TASK-014 merge commit (feature/
  TASK-014-pairing-desk-honesty merged --no-ff; see `git log` for the
  hash) — records docs commit on top
- Working tree: clean
- Test count: 174 passed / 3 skipped (was 168/3) — +6 (3 Api
  PairingStatusTest, 3 Web AdminPairingDeskTest)
- B2B-Firmware: untouched this run (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-011)

- **The pairing desk no longer breaks after the first pairing** (the
  owner's bench bug, reproduced live): all JSON literals in the desk
  script render through unescaped echo — before TASK-014, the first
  completed pairing made Blade emit `&quot;…&quot;` in `lastSeenUid`,
  a fatal JS SyntaxError that killed buttons + polling on EVERY reload
  (F5 couldn't help; history non-empty was permanent). Regression-
  pinned by a test that renders the desk WITH a completed pairing.
- **Rejected taps are visible at the desk**: a 422 already_paired
  answer stamps the armed window (`pending_pairings.last_rejected_uid/
  _reason/_at`, migration 000003, stamped inside the pair transaction);
  the status feed reports `pending.last_rejection`; the desk renders
  the bilingual note with the remediation ("tap a DIFFERENT card, or
  run ./run unpair"). The window stays armed — a fresh card can still
  complete it. 409 no-session taps stamp nothing.
- **The desk state machine is honest**: success shows once and stays;
  "window expired" only for a window that truly expired; polling is
  2 s while armed / 15 s quiet idle watch (cross-tab aware); no more
  eternal expired-flashing loop overwriting the success line.

## Confirmed facts (cumulative, still current)

- ADR-020 invariant 2 unchanged: a paired credential is burned — the
  fix makes that outcome VISIBLE, not mutable (./run unpair is the
  sanctioned bench reset, TASK-013/ADR-023)
- The pair endpoint's device-side contract is byte-identical (200/409/
  422 + messages); the stamp rides the existing transaction
- Pairing desk arming (TASK-011), LAN phones (TASK-012), unpair script
  (TASK-013) — unchanged and still current
- Blade environment lesson: escaped `{{ }}` echo is FORBIDDEN for JS
  literals in scripts; literal brace pairs in comments are parsed by
  Blade too — reword comments instead

## Bench expectations after the owner pulls

- `git pull` + `php artisan migrate` (the 3 new columns) + restart
  `./run serve` → the desk works for student #2 after pairing student
  #1: arm → tap a DIFFERENT card (or `./run unpair` first to reuse the
  same card) → rejections explain themselves on screen.

## Open items

- Deferred: GET /api/v1/reader/me boot-time key check; firmware
  PAIRING.md pointer to the desk rejection note (optional cross-repo
  follow-up, NOT started)
- Sandbox tooling note (not a product defect): ./run serve under the
  wrapper PHP mishandles web routes; the browser bench used a manual
  `php -S` boot — see RUN-012 environment notes
