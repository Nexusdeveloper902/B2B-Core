# ADR-042

## Date
2026-09-09

## Context
The owner's passover verdict: "everything that could change needs
websockets, this needs to be realtime" — after living in the GUI.
Three channels existed (tap, pairing, recycling) but every
roster-management surface was SSR-frozen: a student created by the
desk (or imported) never appeared without F5; a reader relabeled
anywhere never repainted other surfaces; the teacher class panels'
summary chips and both dashboards' KPI strips lagged behind the rows
that already went live; the parent timeline and the student
history/leaderboard/balance pages were static. The same report added
the GUI holes: no class creation, the grade field expected typed "°"
degrees, and the stock Tailwind paginator rendered unstyled.

## Decision
A FOURTH WebSocket channel — `roster` — following the recycling
channel's architecture exactly: an append-only `roster_updates` table
written INSIDE the same DB transaction as the change it describes
(`student_created`, `students_imported` — one frame per import,
`class_created`, `reader_updated` — written by BOTH reader write
endpoints), polled by `realtime:serve`, delivered to ADMIN connections
only (payloads mirror the admin REST endpoints' exposure — the pairing
frames' wire discipline), with the recent snapshot riding the admin
hello and replayed through the same idempotent handlers live frames
use. Class creation ships as `POST /api/v1/admin/classes`
(admin-only, optional teacher-role-checked homeroom assignment,
case-insensitive duplicate 422 mirroring the student rule). Grade
becomes a SELECT (0°–11°). Existing per-page handlers consume the
frames: students desk prepends rows / adds select options; readers
desk and the admin readers table repaint (never clobbering a focused
input); teacher chips + KPI and admin KPI ride the tap/recycling
frames they already receive; the timeline prepends the viewed
student's taps; history/leaderboard/balance ride recycling frames
(fixing the latent student-dashboard bug: the listener read
`frame.payload`; the server sends `frame.update.payload`).

## Alternatives Considered
- Reusing `recycling_updates` for roster rows — rejected: different
  exposure (recycling is every-role; roster frames are admin-only),
  different payload domain; mixing them would force school-wide
  delivery of admin data.
- Signature-polling the `students`/`classes`/`readers` tables (the
  pairing channel's approach) — rejected: no row-level detail without
  joins in the socket server; row-data fingerprints over three tables
  is three polls and no payload. The append-only table is one poll
  with precomputed payloads and the committed-only guarantee.
- A general-purpose "table changed" broadcast bus — rejected:
  premature; four channels with explicit contracts beat one generic
  one with implicit scope rules.

## Reasoning
The recycling channel's transactional append-only source is already
proven (TASK-025): committed-only broadcast by construction, writers
own payload context, the socket server stays read-only. Roster
changes are admin actions on admin surfaces, so admin-only delivery
keeps the exposure rule simple and consistent (same as pairing).
Idempotent update-or-prepend handlers make the hello snapshot replay
safe and make the fetch-response + WS-frame double-arrival a no-op
(the creating admin sees the row once, whichever path lands first).

## Consequences
- +1 migration, +1 model, +2 services (read/write), 4 frame types,
  one wire contract addition (`realtime:roster` CustomEvent).
- Every page rendering mutable state now boots the realtime client
  (passover table in docs/FRONTEND.md §3b).
- Honest limits documented: leaderboard class standings stay a
  snapshot (no class aggregates in frames); timeline empty state
  doesn't grow a live table from zero; rewards catalog static.
