# ARCHITECTURE — The event-type spine

## Claim
One tap → one row in `events`. The `type` column (plus `occurred_at` + joins to
cards/students/readers) is the SINGLE source of truth from which attendance,
PAE, and recycling reporting are all derived. No application stores its own
copy of presence data. This is the architectural claim the whole "presence event
platform" pitch rests on — and it is actually true in the schema, not just
asserted in a slide.

## Schema reality

```
events
├── id
├── card_id     → cards.id → students.id (WHO)
├── reader_id   → readers.id (WHERE)
├── type        → CLASS_ATTENDANCE | PAE_BREAKFAST | PAE_LUNCH | RECYCLING_DEPOSIT | ENTRY (WHAT)
├── occurred_at (WHEN — server time or device client_timestamp)
├── metadata    (JSON: e.g. client_timestamp echo)
└── timestamps
```

`App\Enums\EventType` is the canonical type list in code; `config/presence.php`
mirrors it for documentation/external tooling. `readers.active_event_type`
decides the label a tap gets at tap time — the same physical reader can be
relabeled (classroom ↔ PAE modes) via the admin mode endpoint, which is exactly
how "one reader, many roles" works.

## Derivations (all read-only views over `events`)

| Report | Derivation |
|---|---|
| Attendance count | distinct students with `type=CLASS_ATTENDANCE` on date (optionally per class) |
| PAE counts | distinct students with `type=PAE_BREAKFAST`/`PAE_LUNCH` on date |
| Recycling totals | `recycling_deposits` joined to `events` on date range (items, points, by_material) |
| Present/late/absent | per student: first CLASS_ATTENDANCE event of today vs `ATTENDANCE_LATE_CUTOFF` |
| Late list | late students + tap times (first-tap > cutoff) |
| Class board | whole-class rows + totals for any date (generalized `classAttendanceToday`) |
| By-class breakdown | enrolled/present/absent/rate per class |
| PAE list/trend | students per meal per date; per-day counts per meal |
| In-school now | today's last gate event per student is ENTRY (first ENTRY as `entry_at`) |
| Perfect attendance | zero absences across the window (chronic-absence query as exclusion set) |
| Points statement | balance/earned/spent from the ledger (no denormalized counter) |
| Student timeline | all events for a student's cards, chronological, with deposit join |

`App\Services\AttendanceService` implements every derivation and is ALSO the
execution layer for the NL-query callable functions — so the LLM, the
dashboards, and any future export can never disagree about a number (there is
exactly one code path per metric).

## Related decisions
- ADR-002 (readers = Bearer-key identity), ADR-003/007 (classifier behind an
  interface), ADR-001 (SQLite).

## Points sub-ledger
Points are NOT stored on the student row. `points_ledger` is append-only
(delta, reason, event_id/reward_id); balance = SUM(delta). Recycling earns
(+config points per material, only after classification), redemption spends
(−reward cost, transaction+lock). The ledger is the audit story of the
earn-and-spend differentiator.

---

## TASK-037 appendendum — the meal semantics on the spine

The spine gained two columns that make the valid-vs-flagged distinction
EXPLICIT (see ADR-052):

- `events.served` (default true): only served rows contribute to PAE
  statistics. Every derived query goes through the AttendanceService /
  PaeReportService choke points, all of which filter `served = true`.
- `events.reason` (nullable): the machine-stable rejection reason for
  flagged rows.

Type set now: `CLASS_ATTENDANCE`, `PAE_BREAKFAST`, `PAE_LUNCH`,
`RECYCLING_DEPOSIT`, `PAE_ATTEMPT` (engine-written only — an
out-of-window/weekend meal tap; NOT a valid reader mode:
`EventType::validReaderModes()`). `ENTRY`/`EXIT` are GONE (ADR-054
supersedes ADR-038): no gate readers, no session derivations, and the
PAE attendance prerequisite rides `CLASS_ATTENDANCE` exclusively.

Meal rows are written by `MealServingService` (never by the generic
tap path): the meal is auto-detected from the serving windows (the
reader mode label is a legacy entry point into the engine, not the
meal authority). PAE_* rows may be served (real meals) or flagged
(attempts with a known meal); PAE_ATTEMPT rows are always flagged.
