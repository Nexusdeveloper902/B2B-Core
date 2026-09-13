# ADR-052 — per-meal PAE enrollment and the meal-serving engine

## Date
2026-09-14

## Context
The PAE (school feeding) program was a tap-to-record meal counter: one
`students.pae_enrolled` flag, a reader manually relabeled into
`PAE_BREAKFAST`/`PAE_LUNCH` mode, and a TASK-027 gate that only rejected
non-enrolled students. The PAE Full Program spec (TASK-037) requires a
real operational program: independent breakfast/lunch enrollment, meals
served only inside admin-configurable windows on school days, a prior
same-day attendance prerequisite, duplicate protection, and auditable
excluded attempts that never inflate meal statistics.

## Decision
1. **Per-meal enrollment**: `students.pae_breakfast_enrolled` +
   `students.pae_lunch_enrolled` replace the single flag (a superset
   copy keeps existing enrollees fed; fresh reseeds equally valid per
   the spec). Admin UI = two checkboxes; CSV import understands
   `pae_breakfast`/`pae_lunch` (aliases `breakfast`/`lunch`) and the
   legacy single column (enrolls both).
2. **The serving engine** (`App\Services\MealServingService`): every
   meal-reader tap (reader `type: pae`, or any reader relabeled into a
   PAE mode) is evaluated BEFORE any row is written:
   (1) Mon–Fri school-local (America/Bogota), (2) exactly ONE serving
   window active — auto-detected from the clock, the reader mode label
   is never the meal authority, (3) enrollment for the SPECIFIC meal,
   (4) strictly earlier same-day `CLASS_ATTENDANCE`, (5) one meal per
   student per day. All pass → served meal row; any failure → a flagged
   row. Evaluation + insert share one transaction with a card row lock
   (concurrent same-student taps serialize; the loser is the honest
   duplicate).
3. **Served vs flagged on the event spine**: `events.served`
   (default true) + `events.reason` (machine-stable:
   weekend/out_of_window/window_overlap/no_student/not_enrolled/
   no_attendance/duplicate). Rejected attempts with an active meal keep
   the attempted meal's type; taps with NO active window are
   `PAE_ATTEMPT` (engine-written only — never a valid reader mode).
   Flagged rows stay visible in feeds/timelines/reports (dashed
   styling) but every PAE derivation filters `served = true` at the
   shared helper choke points, so dashboards/reports/NL can never count
   an excluded attempt.
4. **Overlapping windows**: settings validation rejects them at save
   time; the engine's safety net flags any residual overlap LOUDLY
   (`window_overlap`) — never silently assigns an ambiguous meal.
5. **Device contract**: accepted → 200 with `meal` + localized message;
   rejected → 422 with `reason`, the attempted `meal` (when a window was
   active), the flagged row's `event_id`, and a meal-specific,
   Accept-Language-localized message.

## Alternatives Considered
- Flagged attempts in a SEPARATE table: structurally immune to
  accidental counting, but invisible to the realtime feed (which polls
  events), the parent timeline and the live dashboards without a merge
  layer; the served/reason columns + choke-point filters deliver the
  same guarantee while keeping one event stream.
- Window-selection priority (breakfast wins) for overlaps: silently
  assigns an ambiguous meal — exactly what the spec forbids.
- Keeping the reader mode as the meal authority: contradicts the
  auto-detection requirement; the mode stays as the legacy entry point
  into the engine only.

## Consequences
- ENTRY/EXIT removal (ADR-054) unblocks the attendance prerequisite's
  single-source semantics; the sessions feature has no consumer left.
- The device response grew (meal/message/reason/event_id) — additive,
  firmware-compatible (old fields unchanged).
- Counts and dashboards needed the served-filter audit (done across
  AttendanceService + PaeReportService + the dashboard KPI bumping).
- Migration copies then drops the old flag: existing dev databases
  upgrade in place.

## Status
ACTIVE
