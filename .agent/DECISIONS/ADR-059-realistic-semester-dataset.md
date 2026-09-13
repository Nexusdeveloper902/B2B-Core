# ADR-059 — realistic semester dataset (third seeder path)

## Date
2026-09-13

## Context
DemoSeeder is the small stable test fixture and PilotSeeder the 10-day
human demo (its volumes are pinned exactly by PilotSeederTest). Neither
answers the analytics question: what does a REAL school look like in
this schema — 300 students across all grades, per-meal PAE enrollment
mixes, six months of school-shaped history, card churn, transfers,
reward economics with exhausted/inactive states, and operational
history (pairings, captures, broadcast rows)? The desks and reports
were built against fixtures of 5–24 students; a semester-scale,
trend-shaped dataset is the only honest way to exercise them.

## Decision
1. **A third seeder, `RealisticSeeder`, deliberately NOT on the setup
   path.** `./run setup` / `./run reset` keep seeding DemoSeeder
   (instant, test-pinned). The heavyweight dataset is opt-in only:
   `php artisan migrate:fresh --seeder=RealisticSeeder` or the new
   `./run seed-realistic` command (`scripts/seed-realistic.sh` —
   guarded, `--force`, `--students`/`--months`, sqlite+MariaDB like
   `reset.sh`). Default scale: ~300 students × ~6 months (117 school
   days → ~68k taps, ~5.5k deposits, 70 redemptions).
2. **Deterministic semester** (`mt_srand`, fixed seed): the same knobs
   always produce the same story (verified: two independent full runs
   → identical 68247/5549/70). Tunables via `REALISTIC_STUDENTS` /
   `REALISTIC_MONTHS`, grade weights scaled proportionally.
3. **School-shaped trends, not uniform noise**: lower-grades-heavy
   enrollment (40…10 across 1°–11°), per-meal PAE mix with a
   vulnerability gradient (both 139 / breakfast-only 50 / lunch-only
   54 / neither 57), Monday/Friday dips, a May flu wave, 2026
   weekday holidays + a mid-year recess week, exam-week recycling
   slumps, an August eco-campaign spike, recycling adoption ramping
   10%→26%, 8 mid-year transfers, 3 leavers, ~5% replaced cards
   (lost/revoked with taps split across the switch date), and the
   full flagged-attempt taxonomy (duplicate / not_enrolled /
   no_attendance / out_of_window / weekend — every flagged row
   cross-checked against its prerequisite).
4. **Bulk inserts + minimum-cost bcrypt.** Eloquent-per-row would take
   ~15 min; chunked `DB::table()->insert` plus
   `config(['hashing.bcrypt.rounds' => 4])` seeds the full semester in
   ~3 s (25 s → 0.8 s on the small probe — bcrypt was the entire
   bottleneck). Hashes stay verifiable (`Hash::check` — rounds travel
   inside the hash). DEV fixtures only; the production auth path is
   untouched.
5. **Fresh-only guard** (the PilotSeeder rule): refuses on a non-empty
   database instead of doubling append-only history.
6. **Fixture honesty carried over**: synthetic deposits carry
   `image_path = null` (like PilotSeeder); the capture placeholder
   path is documented as synthetic; student logins go through the
   REAL `StudentAccountService` allocator with
   `must_change_password = true` (the real-enrollment posture,
   ADR-044) while staff stay one-tap demo logins; timings resolve
   through the effective settings (meal windows + late cutoff), so an
   overridden timetable still yields coherent taps.

## Alternatives Considered
- Extending PilotSeeder to 300×6mo: rejected — its 3-class/10-day
  scope and exact volumes are test-pinned; a different beast deserves
  a different file, and the small fixture must stay the
  automated-test default.
- Generating through the HTTP API (tap-by-tap): rejected — orders of
  magnitude slower, nondeterministic under concurrency, and it would
  exercise rate limits instead of the schema.
- Faker-uniform random rows: rejected — uniform noise has no school
  shape (no Monday effect, no adoption ramp, no prerequisite
  consistency) and would teach the reports nothing.

## Reasoning
Smallest change that buys a semester: one seeder, one script, one
dispatcher entry (+ the mechanical ScriptSuiteTest map row and the
bilingual SCRIPTS sections the quality gate demands). Zero changes to
existing seeders, schema, or services — the dataset only WRITES rows
the current rules already understand.

## Consequences
- `./run seed-realistic` is the analytics playground; DemoSeeder
  stays the test/CI default and PilotSeeder the 10-day human demo.
- Residuals (honest, out of scope): no artisan options passthrough
  (env only); feed frames cover only the last 5 school days (bounded,
  not whole-semester); weekend taps are sparse by design.

## Status
ACTIVE
