# TASK-029 — the realtime passover, class creation & GUI completion

## Date opened
2026-09-09

## Origin
Owner's passover report (chat, 2026-09-09), after living in the GUI:
1. no option to create a class (the students desk's class select was
   read-only — the roster workflow's first step needed hand-written
   SQL);
2. the grade field expected TYPED text including the "°" degree sign;
3. adding a student did not update the roster (no websockets);
4. the teacher view's class panel needed websockets;
5. "do a passover, everything that could change needs websockets,
   this needs to be realtime";
6. "a crap ton of places where the ui is inconsistent or not styled".

## Resolution

| # | Owner item | Resolution |
|---|---|---|
| 1 | Class creation | `POST /api/v1/admin/classes` (admin-only; name unique case-insensitive → bilingual 422; OPTIONAL teacher assignment, `role:teacher`-checked) + a create-class form in the students desk (name + optional teacher select). ADR-042 |
| 2 | Typed grade + "°" | Grade is a SELECT with preset options 0°–11° (matches the seeder's format); CSV import keeps accepting free text |
| 3 | Student create not live | ADR-042 — the `roster` WS channel: append-only `roster_updates` rows written in the same transaction as the change; the students desk prepends rows idempotently (fetch response OR WS frame, whichever lands first; hello snapshot replays) |
| 4 | Teacher class panel | The tap frames teachers already receive (class-scoped) now also move the per-class summary chips and the KPI strip (row → chips → totals, first-tap-wins at every level, matching the server's semantics) |
| 5 | Realtime passover | Every page with mutable state boots the realtime client (coverage table in docs/FRONTEND.md §3b): admin KPI (distinct-student Sets — the stats are distinct counts), admin readers table, readers desk, students desk, parent timeline (viewed student's taps prepend; pills/search stay owners of visibility), student history (ledger rows + running balance), student leaderboard (board + podium re-sort with the server's deterministic rule, ranks renumber), student balance (latent `frame.update.payload` shape bug FIXED — the old listener read `frame.payload` and never fired) |
| 6 | UI consistency | Design-system paginator (vendor `pagination::tailwind` override — the stock Tailwind markup never matched app.css; students desk + history were unstyled); file input styled (`.file-row`); scattered inline `text-align:right` became one `.ta-right` rule; teacher filterbar inline flex became CSS |

## Acceptance
- [x] Class create API + GUI + duplicate/validation/role tests
      (ClassManagementTest ×5)
- [x] Roster frames ride every write transaction (student create,
      import, both reader endpoints) — pinned by DB assertions
- [x] WS server broadcasts roster frames to admins only; hello
      carries the snapshot (RealtimeServerTest, real sockets)
- [x] View-layer passover pinned (RealtimePassoverTest ×9, incl. the
      pagination + frame-shape regressions)
- [x] Full suite: 330 passed / 1 by-design skip, quality PASS, e2e
      33/33
