# TASK-040 — Realistic Semester Dataset

## Objective
Give the platform a school-shaped, semester-scale dataset — ~300
students, all grades, per-meal PAE mixes, six months of trended
history across EVERY table — behind its own entry point, without
touching the setup path, the existing seeders, or any service.

## What was built
- `database/seeders/RealisticSeeder.php` (deterministic, fresh-only):
  22 classes (1°–11° A/B, one teacher each) + 2 admins + 3 kitchen
  staff; 300 students (40/36/34/32/30/28/26/24/22/18/10) with
  breakfast-only / lunch-only / both / neither enrollment; 26 readers;
  10-reward catalog (exhausted cap + inactive backpack); 117 complete
  school days (Mon–Fri, holidays + recess excluded, ending yesterday)
  of attendance / meals / duplicates / rejections / recycling +
  ledger; ~70 honest-balance redemptions; pairing + capture history;
  roster broadcasts; bounded recycling feed frames. Bilingual console
  summary (staff logins, reader keys, grade + PAE distributions).
- `scripts/seed-realistic.sh` + `./run seed-realistic` dispatcher
  entry (`--force`, `--students`, `--months`, sqlite/MariaDB guards —
  the `reset.sh` pattern).
- `ScriptSuiteTest` command map + `docs/SCRIPTS.md` / `.es.md`
  sections (the quality gate's parity rule).

## Findings (fixed during the build)
1. Purely numeric hex UIDs lose strict comparison once PHP casts
   numeric-string array keys to int — the card→id map silently
   missed rows. Matching is by student+status now (never by uid
   string).
2. `DB::table()->whereKey()` does not exist on the query builder —
   it degrades to a dynamic `where "key" = …` matching NOTHING, with
   no error (student `created_at` backdating silently skipped).
   `where('id', …)` now.
3. Full-cost bcrypt was the entire bottleneck (25 s for 44 students;
   projected ~15 min full). Minimum-cost rounds for the seeding
   process only: full 68k-tap semester in ~3 s.
4. Weekend taps and historical pairings could predate a transfer's
   join or a replacement card's issue. Both are enrollment- and
   card-aware now (verified zero violations).

## Verification (full 300×6 run, throwaway DB)
- 68247 taps (67114 served), 5549 deposits, 46337 points, 70
  redemptions; grades exactly 40…10; cap stock 0/10 sold, Mochila
  inactive/0 sold; flu weeks −4% attendance, eco week +39%
  recycling, Friday dip; zero negative balances; zero duplicate
  UIDs/emails/request_ids; every duplicate backed by a same-day
  served meal; every no_attendance lacking one.
- Determinism: two independent runs → identical counts. Fresh guard
  refuses bilingually on a dirty DB.
- `./run quality` green; full suite 547 passed / 0 failed
  (3 pre-existing env skips). Dev database restored byte-identical
  after verification.

## Deliberately unchanged
- DemoSeeder / PilotSeeder / all services and migrations: zero edits.
- Feed frames cover the last 5 school days only (bounded tail).
- No artisan CLI options (env knobs only, read by the seeder).

## Acceptance Criteria
- `./run seed-realistic --force` seeds the documented semester on a
  fresh DB and re-prints the bilingual summary.
- `./run setup` and `./run reset` behavior byte-identical to before.
- Full suite green.
