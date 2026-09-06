# STATE SNAPSHOT — after RUN-2026-09-06-core-019

## Repository state

- Branch: main at the TASK-021 merge commit (feature/
  TASK-021-pairing-status-grammar merged --no-ff; see `git log`
  for the hash)
- Working tree: clean
- Test count: 233 passed / 3 skipped (was 232/3) — +1 pin for the
  live-panel status-strip grammar
- B2B-Marketplace: clone retained read-only (styling reference)
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What changed (delta vs RUN-018)

- **The pairing status card is now a Signal tone strip** (TASK-021):
  inside the desk's full-bleed `.live-panel`, the armed/expired/
  success message renders as an edge-to-edge tinted strip with 18px
  gutters and a single 1px rule line (the `.live-hint` /
  marketplace `.tap-visual` pattern); the draining window bar
  meters the panel's full width beneath the strip (margin 0,
  radius 0, `is-low` scarlet unchanged); the idle note uses the
  `.live-empty` row grammar. ONE gutter system per panel instead
  of three.
- Presentation only: zero behavior, zero JS-contract, zero string
  changes. The dashboards' inset answer boxes (padded panels) keep
  the `.form-alert` card grammar — the fix is scoped to
  `.live-panel` descendants.
- Dev sandbox environment fact (recorded for future runs): the
  raw `tools/php84` binary lacks ext-iconv — every server/test
  launch MUST go through the `/home/z/my-project/tools/php`
  wrapper (PHPRC + LD_LIBRARY_PATH), or every route fatals with
  a masked "Class config does not exist" cascade.

## Open / next

- Open (not started): per-class jump nav
- Deferred: GET /reader/me, firmware PAIRING.md pointers
