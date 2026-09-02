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
