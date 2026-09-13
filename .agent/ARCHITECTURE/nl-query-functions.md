# ARCHITECTURE — The NL-query function surface

## Claim
The LLM never answers from its own knowledge. It selects a function,
the backend executes the REAL Eloquent query (fenced by the caller's
`StudentScope`), and the model only phrases the result. There are 22
callable functions; anything no function can answer is unanswerable by
design — the model must not guess (the present/absent polarity flip of
2026-09-13 happened exactly because "who came" had no function).

## The surface (FunctionRegistry, declarations ↔ execute 1:1)

| Family | Functions |
|---|---|
| Attendance counts | `get_attendance_count`, `get_absence_count`, `get_enrollment_count` |
| Attendance lists | `get_present_students`, `get_absent_students`, `get_late_students` (with `tapped_at`) |
| Attendance views | `get_class_status` (whole board, totals + rows), `get_attendance_by_class` (enrolled/present/absent/rate), `get_attendance_trend`, `get_repeatedly_absent_students`, `get_perfect_attendance` |
| PAE | `get_pae_count`, `get_pae_students`, `get_pae_trend` (per meal) |
| Presence now | `get_students_in_school` (today's last gate event is ENTRY), `get_student_time_in_school` (ENTRY/EXIT sessions) |
| Recycling/points | `get_recycling_totals`, `get_recycling_leaderboard` (ledger-derived ranks), `get_student_points` (balance/earned/spent) |
| Single-student | `get_student_timeline`, `find_student` (name → id bridge, max 5) |

## Scope rules
- `null` class filter = admin/school-wide; teacher = own classes;
  explicit out-of-scope class/student → `{'error': ...}`, never data.
- School-wide BY DESIGN (spec §22 public board, ADR-037 exception):
  `get_recycling_totals`, `get_recycling_leaderboard`. No scope param.
- `find_student` and trend/leaderboard take the scope filter where a
  roster is involved; the leaderboard does not.

## Prompt context (NlQueryService::systemMessage)
Every call injects `Current date and time: Y-m-d H:i (tz, weekday)`
plus the polarity pin (vino = present, faltó/no-vino = absent) and the
language-of-the-question rule. Relative dates resolve server-side;
the model never asks the user for date/time.

## Execution contract
Max 3 tool rounds; the assistant turn echoes VERBATIM (tool_call ids
intact) followed by one `role:"tool"` message per call (DeepSeek
multi-turn contract, ADR-030). Empty final text =
`nl_query.no_function_selected`, never a fake success.

## Related decisions
- ADR-037 (teacher data wall), ADR-030 (DeepSeek provider),
  ADR-046 (model lineup), ADR-051 (this surface's expansion),
  ADR-041 (safe-markdown answers).

---

## TASK-037 appendendum — the PAE parity surface (27 functions)

Removed with ENTRY/EXIT (ADR-054): `get_student_time_in_school`,
`get_students_in_school`.

Added for PAE parity (ADR-057; all resolved through the same
PaeReportService/AttendanceService methods the /admin/reports/pae pages
use, fenced by StudentScope like every other function):

- `get_missed_meals(meal, date?, class_id?)` — present + enrolled + not
  served ("quiénes no almorzaron").
- `get_missed_meal_count(meal, date?, class_id?)`.
- `get_missed_meal_trend(meal, days?, class_id?)`.
- `get_pae_enrollment(class_id?)` — breakfast/lunch/both/only-one/
  neither buckets.
- `get_student_pae_history(student_id, days?)` — per-day breakfast/
  lunch slots (served/flagged) + totals.
- `get_student_meals_on(student_id, date?)` — did-they-eat.
- `get_flagged_meal_attempts(date_from?, date_to?)` — excluded
  attempts: count + per-reason breakdown + recent rows (school-wide,
  mirroring the admin report).

The system prompt pins the missed-meal polarity and the served-only
counting rule (rejected/duplicate taps never count).
