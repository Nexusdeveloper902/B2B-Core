# Architecture: the card-pairing flow (TASK-010)

## Status
Architectural fact added by TASK-010 (ADR-020). Pairing is now a real,
tested capability — previously the seeder fabricated cards and the API
docs recorded pairing as a gap.

## The flow

```text
DASHBOARD SIDE (admin)                DEVICE SIDE (any reader)
──────────────────────────            ──────────────────────────
POST /admin/students/{id}/arm         POST /admin/cards/pair
       │                                     │  Bearer <reader.api_key>
       ▼                                     ▼
 pending_pairings row  ──45 s window──▶ most recent unconsumed,
 (student_id, expires_at)               unexpired pending row
                                         │  (lockForUpdate)
                                         ▼
                                  cards row created
                                  (credential_uid → student)
                                         │
                                         ▼
                                  pending row: consumed_at,
                                  reader_id stamped
```

## pending_pairings and the existing schema

| Table | Relationship | Notes |
|---|---|---|
| `pending_pairings` | `student_id` → `students` (cascade delete) | Transient: rows expire (45 s) or are consumed within seconds in normal use; they are never updated after consumption. |
| `pending_pairings` | `reader_id` → `readers` (nullable, null on delete) | Stamped on consumption — which reader completed the pairing; null while armed. |
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
