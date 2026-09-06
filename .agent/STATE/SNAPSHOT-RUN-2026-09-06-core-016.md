# STATE SNAPSHOT — after RUN-2026-09-06-core-016

## Repository state

- Branch: main at the TASK-018 hotfix merge commit (feature/
  TASK-018-ci-shellcheck-red merged --no-ff; see `git log` for
  the hash) — records docs commit on top
- Working tree: clean
- Test count: 219 passed / 3 skipped (unchanged from core-015)
- GitHub Actions: GREEN again on main (was red since TASK-016 —
  two merges shipped with a latent shellcheck warning)
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-015)

- **One fix only**: scripts/serve.sh's realtime startup probe loop
  uses its counter honestly — the failure warning reports the probe
  count ("Realtime server did not come up after N probe(s) / El
  servidor en vivo no arrancó tras N intento(s)"). 10 = loop
  exhausted; <10 = the artisan process died early. Loop behavior
  (10 probes × 0.3 s, early-break on dead process) unchanged.
- Everything from core-015 stands: Calm Ledger UI (ADR-027),
  realtime feed (ADR-026), Colombia time (ADR-025), pairing desk
  honesty (ADR-024), device + realtime protocols byte-identical.

## Confirmed facts (cumulative, still current)

- **CI's quality/shellcheck bar now matches the dev sandbox**:
  shellcheck 0.10.0 lives at /home/z/my-project/tools/shellcheck
  (sandbox artifact); with tools on PATH, ./run quality enforces
  shellcheck locally exactly like the Actions scripts-lint job.
- Realtime WebSocket feed, Colombia school time, pairing desk
  honesty, unpair, LAN stateful, ADR-020 invariants: all unchanged
- Dev DB: standard demo state (reseed rotates reader keys + card
  UIDs; the bench must re-read them)

## Bench expectations after the owner pulls

- `git pull` + `./run serve` — no functional change; only the
  worst-case realtime startup warning is more precise.
- GitHub Actions badge back to green on this merge and after.

## Open items

- Optional follow-ups (NOT started): dark mode (rejected for now,
  ADR-027); per-class "jump to class" nav when many classes exist;
  marketplace token catch-up (ADR-027 supersession note).
- Deferred (unchanged): GET /api/v1/reader/me; firmware PAIRING.md
  pointers.
