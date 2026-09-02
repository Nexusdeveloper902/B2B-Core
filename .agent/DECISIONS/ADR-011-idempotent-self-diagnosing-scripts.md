# ADR-011

## Date
2026-09-03

## Context
The project's stateless protocol (master execution protocol §1) rejects
assumed state: agents must verify preconditions instead of trusting prior
runs. The owner asked for the same principle ("the same stateless prompt
architecture") applied to how the app is operated. The TASK-002 manual flow
violated it constantly: `php artisan serve` failing at request time because
`.env`/`APP_KEY`/sqlite file were missing; partial bootstraps leaving the repo
in ambiguous states; errors surfacing as Laravel stack traces instead of
actionable next steps.

## Decision
Every script in the suite is **idempotent, self-diagnosing, and fail-loud**:

1. **Idempotent**: safe to re-run any time. `setup` re-runs are no-ops for
   completed steps; `.env` is never overwritten (only created when absent);
   `APP_KEY` is generated only when empty; migrations/seeder are no-ops when
   current (DemoSeeder is firstOrCreate-idempotent by design).
2. **Verify-then-act**: every command checks its own preconditions and
   resolves its own interpreter (ADR-010 resolution chain) before doing
   work — no script assumes a previous script ran.
3. **Fail loud, suggest the fix**: errors are bilingual (EN/ES), exit codes
   are non-zero, and each failure prints the command that fixes it
   (e.g. "run `./run setup` first", "run `./run doctor` for details",
   Arch php.ini `sed` remediation).
4. **Throwaway-safe for testing**: destructive scripts (`reset`, e2e) either
   operate on throwaway databases (e2e uses `database/e2e.sqlite`, never the
   dev DB) or require an explicit `--force`/confirmation (`reset`).
5. **Bilingual output convention**: user-facing lines print
   `English / Español` on one line (matching `scripts/e2e.sh` and the
   seeder's existing convention).

## Alternatives Considered
- Verbose per-step scripts that just wrap the raw commands — rejected:
  wrapper scripts that assume a perfect environment recreate the exact
  problem the owner reported.
- An internal state file marking "setup done" — rejected: state files go
  stale after partial failures and violate the stateless premise; the
  filesystem itself (vendor/, .env, APP_KEY, migrations table) is the only
  source of truth.

## Reasoning
The filesystem is the state; every script derives what it needs from it.
This makes each script a true stateless agent over the repo: fresh clone +
one command (`./run setup`) is always sufficient, and any half-broken
environment is repairable by re-running the same command.

## Consequences
- Scripts must keep preconditions cheap (file existence greps, `php -m`
  checks) so re-running is fast.
- CI dogfoods `./run setup --ci` (quiet, no-DB variant) on every push, so
  idempotency is continuously machine-verified on clean checkouts.
- Script failure messages are part of the UX contract and must remain
  bilingual + actionable (checked by ScriptSuiteTest's doc-parity test at
  the structural level).

## Status
ACTIVE

## Supersedes
none
