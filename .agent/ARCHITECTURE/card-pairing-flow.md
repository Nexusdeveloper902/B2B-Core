# Architecture: the card-pairing flow (TASK-010, desk UI in TASK-011)

## Status

Architectural fact added by TASK-010 (ADR-020); the dashboard arming
surface was added by TASK-011 (ADR-021) as the deferred follow-up the
ADR had recorded. Pairing is a real, tested capability — previously the
seeder fabricated cards and the API docs recorded pairing as a gap.

## The flow

```text
DASHBOARD SIDE (admin)                 DEVICE SIDE (any reader)
──────────────────────────            ──────────────────────────
POST /admin/students/{id}/arm         POST /admin/cards/pair
  (TASK-011: the "Pair cards"               │  Bearer <reader.api_key>
   page at /admin/pairing is the            ▼
   one-click UI for this POST;         most recent unconsumed,
   it polls GET /admin/pairing/        unexpired pending row
   status for the live countdown)           │  (lockForUpdate)
       │                                    ▼
       ▼                              cards row created
 pending_pairings row ──45 s window──▶ (credential_uid → student)
 (student_id, expires_at)                   │
                                            ▼
                                   pending row: consumed_at,
                                   reader_id + card_id stamped
```

## pending_pairings and the existing schema

| Table | Relationship | Notes |
|---|---|---|
| `pending_pairings` | `student_id` → `students` (cascade delete) | Transient: rows expire (45 s) or are consumed within seconds in normal use; they are never updated after consumption. |
| `pending_pairings` | `reader_id` → `readers` (nullable, null on delete) | Stamped on consumption — which reader completed the pairing; null while armed. |
| `pending_pairings` | `card_id` → `cards` (nullable, null on delete) | TASK-011 audit column: stamped at consumption — the exact cards row this pairing created. Powers the pairing desk's history; null on rows consumed before TASK-011 and while armed. |
| `cards` | created by the pair step | `credential_uid` (unique) → `student_id`, status `active`. From then on the card is an ordinary tap identity. |
| `events` | downstream | A paired card immediately produces ordinary tap events — pairing does NOT create an event. |
| `points_ledger` | untouched | Pairing awards no points (the recycling earn loop is unchanged). |

## Invariants

1. **A pending pairing is one-shot** — `consumed_at` is stamped exactly
   once; the row-lock in `PairingService::pair()` makes double-consumption
   impossible even under concurrent scans.
2. **Existing card rows are immutable assignments** — a credential that
   already has a row (any status) is rejected (422), never reassigned.
3. **The reader key is the only trusted device identity** — the pair call
   never accepts a student id or reader id from the client.
4. **The window is configuration** (`PAIRING_WINDOW_SECONDS`, default 45)
   — changing it is a .env change, not code (ADR-020).
5. **The arming UI writes through the same endpoint** (ADR-021): the
   pairing desk page's buttons POST to the TASK-010 arm endpoint with
   the admin's session — no new write path, no client-supplied
   student identity on the device plane. Its status feed
   (GET /admin/pairing/status) is strictly read-only.
