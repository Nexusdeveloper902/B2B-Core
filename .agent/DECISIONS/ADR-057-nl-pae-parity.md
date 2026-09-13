# ADR-057 — NL-query parity with the completed PAE model

## Date
2026-09-14

## Context
The natural-language interface (ADR-051's 22-function surface) answered
attendance/recycling questions well but only had the three original PAE
functions (count/list/trend over the old single-flag model). The spec
demands parity with the completed PAE system — using the same business
rules and data definitions, never conflicting logic.

## Decision
1. **Same services, no parallel logic**: the registry's new arms call
   `PaeReportService`/`AttendanceService` directly — the exact methods
   behind /admin/reports/pae, with the same served-truth filters and
   the StudentScope fences as every other function.
2. **Additions** (7): `get_missed_meals`, `get_missed_meal_count`,
   `get_missed_meal_trend` (present + enrolled + not served),
   `get_pae_enrollment` (breakfast/lunch/both/only-one/neither),
   `get_student_pae_history` (per-day slots + totals),
   `get_student_meals_on` ("did Maria have lunch today"),
   `get_flagged_meal_attempts` (count + per-reason breakdown + recent
   rows, school-wide like the admin report).
3. **Removals** (2): `get_student_time_in_school` +
   `get_students_in_school` left with ENTRY/EXIT (ADR-054). The surface
   is 27 functions.
4. **Prompt parity**: the system prompt now describes the per-meal PAE
   model; the date/time preamble pins the missed-meal polarity
   ("no almorzó" = get_missed_meals) and the served-only counting rule
   (rejected/duplicate taps never count), mirroring the dashboard JS
   guards.

## Alternatives Considered
- LLM-computed aggregates from raw rows: never (fabrication risk — the
  registry exists precisely so the model only selects/phrases).
- A separate PAE-focused registry: two surfaces to keep in sync; one
  registry with the same service arms is the parity guarantee.

## Consequences
- FunctionRegistryTest pins the new 27-name surface; the doc needles
  (API.md EN/ES) pin the names so documentation drift fails the build.
- find_student now carries per-meal enrollment flags for better
  phrasing of enrollment questions.

## Status
ACTIVE
