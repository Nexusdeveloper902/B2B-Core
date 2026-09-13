# STATE SNAPSHOT — RUN-2026-09-13-core-041

## Overall Status
Reader deletion DONE (committed + pushed, this snapshot with it).
NL-query 12→22 surface (CHAT entry in PROJECT.md, ADR-051) rides
the same commit. Nothing uncommitted.

## Completed
- DELETE /api/v1/admin/readers/{reader}: child-first cascade
  (events + deposits + pending captures), pairing history unlinked,
  ledger balances preserved (nullOnDelete), images off disk,
  reader_deleted roster frame, dead key 401s.
- Readers desk ⋯ popover overflow (Rotate/Delete), Datum confirm,
  live row removal on roster frames, empty-state restore.
- Bilingual API docs + postman 04d + DocumentationTest needles for
  the new endpoint (house no-stale-docs contract).

## In Progress
- Nothing.

## Blocked
- Remote CI verdict on the pushed commit (observe post-push).

## Known Problems
- None new.

## Important Current Facts
- Native `popover` for the overflow menu: top-layer, no clipping,
  no anchor-positioning dependency (fixed + getBoundingClientRect).
- `points_ledger.event_id` nullOnDelete is what makes deletion
  balance-safe — no ledger logic runs in the destroy path.
