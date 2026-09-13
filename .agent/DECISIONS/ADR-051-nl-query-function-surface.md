# ADR-051 — NL-query function surface 12 → 22 (present/absent polarity fix)

## Date
2026-09-13

## Context
Two owner reports against the NL assistant in one session:

1. "¿Quién ha venido hoy?" → the model asked the USER for the date
   ("Could you tell me the date (YYYY-MM-DD)?"). The system prompt
   carried no date context, so "hoy" was unresolvable.
2. The same question then returned wrong-polarity data (absentees
   framed as present). Root cause, not a prompt flake: the registry
   had `get_absent_students` (a LIST) but only `get_attendance_count`
   (a NUMBER) on the present side. "Who came" had NO answerable
   function — the model reached for the absent list (or the count)
   and phrased it backwards. No prompt wording can fix a missing
   capability; the model must guess when no function fits.

The follow-up decision: expand the surface to the full question set a
school actually asks (late lists, PAE lists, class boards, in-school
right now, leaderboard, points, perfect attendance) instead of
patching one hole at a time.

## Decision
- System prompt carries full date/time context on EVERY call:
  `Current date and time: Y-m-d H:i (timezone, weekday)` from `now()`
  (America/Bogota) — plus "never ask the user for the date or time"
  and an explicit present/absent polarity pin
  ("quién vino/ha venido" = PRESENT, "quién faltó/no vino" = ABSENT).
- 10 new functions (22 total), each 1:1 with a real Eloquent-backed
  method — the LLM still only selects/phrases, never computes:
  `get_late_students`, `get_class_status`, `get_attendance_by_class`,
  `get_enrollment_count`, `get_pae_students`, `get_pae_trend`,
  `get_students_in_school`, `get_recycling_leaderboard`,
  `get_student_points`, `get_perfect_attendance`.
- `get_recycling_leaderboard` is school-wide with NO class scope —
  the spec §22 public-competition-board exception (same as
  `get_recycling_totals`), consistent with ADR-037.
- `perfectAttendance` reuses the chronic-absence query as its
  exclusion set (`repeatedlyAbsentStudents(days, 1)`) instead of a
  second school-day computation; the extracted `schoolDaysInWindow`
  helper is behavior-identical in the original caller.
- Every teacher-reachable function takes the caller's StudentScope
  (ADR-037 convention); out-of-scope answers with an explicit error,
  never data, never silence.

## Alternatives Considered
- Prompt-only polarity fix ("don't confuse present/absent") —
  rejected: without a present-list function the model still has
  nothing correct to call; wording cannot conjure data.
- Scoped leaderboard (`class_id?`) — rejected: contradicts the spec
  §22 public board and the ADR-037 exception the codebase already
  documents; a class-filtered board is a different feature.
- `get_pae_trend` returning both meals per day — rejected: two
  response shapes for one function; required `meal` keeps one shape
  mirroring `get_attendance_trend`.
- Raw per-reader/device stats, reward-catalog lookup — rejected: ops
  browsing and dashboard content, not school questions.

## Consequences
- `FunctionRegistryTest::declares_exactly_the_fixed_function_set`
  must be edited to add a function — intentional friction: the
  surface changes loudly, never silently.
- New derivations live in `AttendanceService` (single code path per
  metric — dashboards and NL cannot disagree); new rows added to the
  event-type-spine derivations table.
- docs/API.md + docs/API.es.md function lists updated (bilingual
  invariant); new names pinned as DocumentationTest needles.
- `classAttendanceToday()` now delegates to
  `attendanceRowsForClass(class, date)` — dashboard row shape
  unchanged (Blade `tappedAt` contract).

## Status
ACTIVE
