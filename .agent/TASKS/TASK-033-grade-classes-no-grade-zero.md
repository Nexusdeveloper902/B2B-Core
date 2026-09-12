# TASK-033 — A/B class per grade; grade 0 removed

## Date opened
2026-09-12

## Origin
Owner (chat, 2026-09-12): "yk the class stuff, rn there is only 5B
and it doesnt change, please add an a and b variant for each grade
and grade 0 should not exist."

## Work
- [x] `DemoSeeder` ships one A/B class pair per grade, 1–11
  (`1° A`…`11° B`, 22 classes). 5° B stays the demo home: Elena
  Ramírez's homeroom + the four seeded students (grade `5°`).
  `firstOrCreate` keeps reruns idempotent; no unique constraint on
  `classes.name` exists (API-level duplicate 422 unchanged).
- [x] Grade SELECT on the students desk: `range(0, 11)` →
  `range(1, 11)`. Server side never constrained grades (free-text
  `grade`, CSV passthrough) — no backend change needed.
- [x] Tests: the seeder change broke 9 tests that assumed a
  single-class world (`SchoolClass::first()` landed on 5° B; `6° A`
  / `8° A` were "fresh" names). Fixed by naming the home class
  explicitly (`where('name', '5° B')`, + `homeClass()` helper in
  AttendanceServiceTest) and moving fresh-name fixtures to the C
  variant (`6° C`, `8° C`; `7° C` already was). Grade pin updated:
  `1°` present, `0°` asserted absent, `5° A` / `11° B` seed
  coverage in the class dropdown.
- [x] Docs: FRONTEND.md + .es.md grade-range line updated.
- [x] Full pyramid + quality green.

## Explicitly out of scope
- Redistributing the four demo students across grades (tests pin
  the 5° B household: Absent 4, duplicate-name, disambiguation).
- PilotSeeder (opt-in big roster, already has 5° A + 5° B, no 0°
  anywhere) — untouched.
- Grade validation rules (still free text by design).

## Status
DONE — delivered 2026-09-12 (uncommitted tree; commit per owner call)
