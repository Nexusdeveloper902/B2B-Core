# ADR-066 — `feedback` realtime channel for taps that write no row

## Date
2026-09-16

## Context
The Android speaker bridge (TASK-047, B2B-App ADR-003) beeps once per
realtime `tap` frame. But `tap` frames come from `events` rows, and three
kinds of answered tap write **no** row:

- a same-day repeat classroom tap (TASK-043, first tap counts): the
  device answers `200 duplicate:true`;
- an unknown card (404);
- an inactive card (404).

On the bench, the reader printed `[OK] event logged` three times and the
phone beeped only once. Writing duplicate rows into `events` would
break TASK-043 and inflate every derived view.

## Decision
1. **A new append-only table, `tap_feedback`** (`cue` =
   accepted|rejected, `reason` = duplicate|not_found|inactive,
   `event_id` = the original row for duplicates, plus `school_id` and
   `reader_id`). It follows the same pattern as `roster_updates` and
   `recycling_updates`. `TapService` writes it only on the three
   no-row paths, so each tap yields exactly one cue: either a `tap`
   frame or a `feedback` frame, never both.
2. **`realtime:serve` broadcasts it.** It polls the table after its
   head and pushes
   `{"type":"feedback","feedback":{id,cue,reason,event_id,school_id}}`.
   - **Who receives it:** admin and kitchen connections, within their
     organization.
   - **No hello replay:** cues are only meaningful live.
3. **The write is best-effort.** A failed insert is logged and
   swallowed; it can never fail the device's tap, because the device's
   answer is the product. MariaDB and SQLite keep the transaction
   usable after a failed statement.
4. **Rows derived from `served` also carry the cue.** Each
   `RealtimeFeed` row carries `feedback` (accepted|rejected, derived
   from `served`), so the phone never re-implements the meal-engine
   truth.

## Alternatives rejected
- **Write a row per duplicate:** breaks first-tap-counts and every
  report derived from `events`.
- **Broadcast from the tap endpoint straight into the socket server:**
  already rejected by ADR-026, because it couples the device write path
  to a UI concern and loses cross-process writers.
- **Cache-backed queue:** racy read-modify-write, and a second storage
  path for one feature.

## Consequences
- Dashboards ignore the new frame type (`realtime.js` dispatches only
  known types). Teacher and student connections never receive it.
- **Deploy order:** the migration must run before the new `TapService`
  serves traffic. `artisan serve` loads PHP per request, and one
  duplicate tap returned 500 on the bench in that window, which is why
  the write became best-effort.
- `tap_feedback` is never pruned, same as the other realtime logs. It
  gains one small row per repeat or unknown tap.

## Status
ACTIVE
