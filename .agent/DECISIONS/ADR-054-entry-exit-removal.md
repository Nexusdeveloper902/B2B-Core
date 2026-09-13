# ADR-054 — ENTRY/EXIT removed from the platform

## Date
2026-09-14

## Context
TASK-027 (ADR-038) added an entry/exit gate feature: `ENTRY`/`EXIT`
event types, an `entry` reader type, and `studentSessions()` time-in-
school derivations. The PAE Full Program spec explicitly removes it:
ENTRY/EXIT is not part of the target PAE workflow, and the meal
attendance prerequisite now rides `CLASS_ATTENDANCE` exclusively.

## Decision
Remove the feature COMPLETELY rather than leave dead paths:
- `EventType::Entry`/`Departure` cases deleted; `PAE_ATTEMPT` joined
  the enum (engine-written only — `validReaderModes()` excludes it from
  assignable reader modes, which the three reader requests now use).
- `ReaderType::Entry` deleted (no `entry` readers can be provisioned;
  existing rows would fail casts — fresh reseed per the spec).
- `AttendanceService::studentSessions()` + `studentsInSchool()`
  deleted (with their NL functions `get_student_time_in_school` +
  `get_students_in_school`), the orphaned parent-timeline lang keys
  removed, the PilotSeeder gate reader + gate taps removed.
- Config lists (`event_types`, `reader_types`), the docs and the
  Postman examples updated; the removal is DOCUMENTED in API.md/ES
  (supersession note) rather than silently vanished.

## Alternatives Considered
- Keep the event types but stop deriving sessions: every consumer
  (enum validation, mode endpoint, lang labels, CSS chips, seeders,
  tests) would still carry the dead weight; the spec says remove.
- Feature-flag the removal: the platform is pre-production; flags
  would add a configuration surface for a feature nobody uses.

## Consequences
- NL surface: 2 functions removed (the ADR-051 22-function surface
  becomes 27 with the TASK-037 PAE parity additions); DocumentationTest
  needles moved with the docs.
- The parent timeline's "attendance" filter category no longer matches
  ENTRY rows (they cannot exist).
- Supersedes ADR-038 (which remains in the append-only record as
  history).

## Status
ACTIVE (supersedes ADR-038)
