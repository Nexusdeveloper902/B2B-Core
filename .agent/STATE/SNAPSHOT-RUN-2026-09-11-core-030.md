# STATE SNAPSHOT — RUN-2026-09-11-core-030

## Overall Status
Branch `feature/TASK-030-pulse-productization-pass` is 4 commits ahead
of main (main untouched): TASK-030 A/B/Fix-3/C delivered, pyramid
green after every item (423 passed / 3 skipped · quality PASS ·
e2e 33/33). NOT pushed — push + CI observation is the next action.

## Completed
- A. Student auto-provisioning + forced rotation (ADR-044) + race/
  truncation/bulk/PAT-wall hardening + 37 tests.
- B. Reader creation + key-once lifecycle + rotation (ADR-045) + desk
  + 13 tests.
- Fix 3. PilotSeeder (993/69/2) + `./run reset --pilot` + Instagram
  footer + PTS/unit i18n + 13 tests.
- C. 28 oblivion pins (analytical NL, PAE gate, sessions, redirects).
- 3 adversarial subagent audits, all findings closed.

## In Progress
- Nothing — branch work complete, awaiting push/CI observation.

## Blocked
- Live NL round-trip still needs DEEPSEEK_API_KEY (owner action,
  unchanged since TASK-022).

## Known Problems
- None open on the branch. Conscious residuals in the RUN record
  (plaintext reader keys, documented initial password, pilot-today
  times, item-G follow-ups).

## Important Current Facts
- DemoSeeder = test fixture (counts pinned by ~15 assertions — do not
  enrich it; PilotSeeder is the demo dataset).
- `points_unit` lang key pre-existed — do not re-add.
- Windows `\\wsl.localhost` git-status dirt is a view artifact; work
  from `wsl -d archlinux`.
- Suite: 423/3 · e2e 33/33 · quality PASS (commit ea6c9ad).
