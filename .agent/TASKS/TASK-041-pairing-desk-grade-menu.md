# TASK-041 — Pairing Desk Grade Menu

## Objective
The pairing desk rendered ALL students on one page (fine at 5–24
fixture rows, unusable at 300 real ones). Default the roster to one
grade with a menu to switch grades — no behavior change to arming,
unpairing, realtime, or history.

## Decision notes
- **Server-side `?grade=` filtering, not client-side tabs.** Keeps
  the page light (one grade ≈ 25 rows), bookmarkable, and no-JS
  friendly; the existing client search keeps filtering the visible
  rows, and the arm/unpair/realtime script is untouched (it queries
  the rendered DOM).
- **Default = lowest grade with students; unknown grade falls back
  to it** (never a 404 or an empty desk from a hand-edited URL).
- **Numeric grade sort in PHP** — `students.grade` is free text, so
  `10°` sorts after `9°` via `(int)` cast, not lexicographically.
- **Pill grammar reuse** (parent-timeline `.pills`/`.pill`): grade +
  count links (`5° · 30`), active marked `is-active` +
  `aria-current`. One additive CSS line (`.pill` gets
  `text-decoration: none`) so links render as pills.
- One new lang key both languages (`pairing_grade_filter`); the
  armed-session panel is grade-independent by design (arming a 5°
  student while viewing 1° still counts down — the status feed owns
  that state, not the roster).

## Acceptance Criteria
- `AdminPairingDeskTest`: default shows only the lowest grade,
  `?grade=` switches, unknown grade falls back (3 new tests).
- Existing desk tests green unchanged (demo fixture is all-5°, so
  the default view still shows every fixture student).
- Full suite green.
