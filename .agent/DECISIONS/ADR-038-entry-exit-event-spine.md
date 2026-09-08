# ADR-038

## Date
2026-09-08

## Context
The spec needs school-gate flows: students enter and leave multiple
times a day; the platform must register EVERY entry and derive
time-in-school. The obvious schema change (a `sessions` table, or
entry/exit columns on events) would ripple through every derived view
built on the single `events.type` spine (attendance, PAE, recycling).

## Decision
Add `EventType::Departure = 'EXIT'` to the SAME spine. A gate reader in
`ENTRY` mode logs `ENTRY` rows (one per tap — multiple entries per day
are the point); switched to `EXIT` it logs `EXIT`. Derivation is
on-the-fly: `AttendanceService::studentSessions(student, days)` pairs
each day's EXIT with the most recent open ENTRY (an unmatched ENTRY
stays an open session; an EXIT with no open entry is kept as a
standalone row). Time-in-school = sum of closed sessions (+ the open
one). No migration, no new table, no writer change beyond the enum.

## Alternatives Considered
- A dedicated `sessions` table with entry_id/exit_id links — rejected:
  a second source of truth beside the immutable event log; pairing
  state would need repair logic on out-of-order taps.
- Entry/exit columns on the events row — rejected: breaks the
  "one tap = one typed row" spine every report already derives from.
- Ignore multiple entries (first-of-day only, like class attendance) —
  rejected: that IS the current bug (owner item 16).

## Reasoning
The platform's core invariant is "one tap becomes one events row whose
type is the reader's mode at tap time" — attendance, PAE and recycling
all derive from that spine. ENTRY already existed as a reader mode;
giving it a closing twin keeps the invariant and makes gate readers
configurable purely by mode switch (the existing admin mode surface),
no firmware change.

## Consequences
- `get_student_time_in_school` NL function exposes the derivation.
- Attendance semantics are untouched: first-of-day CLASS_ATTENDANCE
  tap remains the attendance record (ENTRY taps never count as
  attendance).
- Out-of-order or unpaired taps degrade to honest open/standalone
  sessions — never to silent drops.

## Status
ACTIVE
