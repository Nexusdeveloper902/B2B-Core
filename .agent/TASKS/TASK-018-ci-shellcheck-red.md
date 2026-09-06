# TASK-018-ci-shellcheck-red

Owner directive (2026-09-06, after the TASK-017 merge was pushed):
"Please. the CI failed"

## Diagnosis

GitHub Actions was red on BOTH TASK-016 and TASK-017 pushes (4 jobs
each: Lint, Scripts lint, Windows smoke, Arch smoke). One single
shellcheck warning caused all of it:

    scripts/serve.sh:77  SC2034 (warning): i appears unused.

The probe-counter loop from TASK-016 (`for i in 1 2 3 4 5 6 7 8 9
10`) never reads `i` — the loop is attempt-bounded only, so
shellcheck flags the variable as dead.

Why local gates stayed green: `scripts/quality.sh` runs shellcheck
only when installed ("optional locally, required in CI") — and the
dev sandbox had no shellcheck. CI installs and enforces it. The
local-vs-CI gap hid the warning for two merges.

## Decision

- Fix the CODE, not the linter: the counter is now honestly used —
  the realtime startup failure warning reports the probe count
  ("did not come up after N probe(s)", EN+ES). 10 = loop exhausted;
  <10 = the artisan process died early. Diagnostic value improves,
  behavior of the loop is unchanged.
- Close the local-vs-CI gap in the dev sandbox: shellcheck 0.10.0
  installed at `/home/z/my-project/tools/shellcheck` (sandbox
  artifact, not a repo file) so `./run quality` now enforces
  shellcheck locally exactly like CI.
- No `shellcheck disable` pragma: the variable is genuinely used
  now; a suppression would have hidden the intent.

## Acceptance

- [x] Exact CI command reproduced red locally before the fix,
      green after: `shellcheck --severity=warning -x run
      scripts/_lib/common.sh scripts/*.sh`
- [x] `./run quality` PASS with shellcheck now enforced locally
- [x] `./run test` 219 passed / 3 skipped (unchanged)
- [x] `./run e2e` 24/24 (boots serve — realtime startup loop
      exercised; hello + 401 realtime checks pass)
- [x] Failure-path proof: loop-exhaustion → "after 10 probe(s)";
      early process death → "after 1 probe(s)" (simulated)
- [x] CI green on GitHub after push (all 13 jobs, incl. Windows +
      Arch smokes) — verified via the Actions API

## Out of scope (deliberately)

- Changing `quality.sh`'s "shellcheck optional locally" contract
  (documented in docs/SCRIPTS.md; CI remains the enforcer — the
  sandbox now matches it in practice).
- Any rewording beyond the one warn; no protocol, route, view,
  CSS, or test change.
