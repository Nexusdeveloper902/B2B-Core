# ADR-035

## Date
2026-09-07

## Context
The spec §22/§28 requires a leaderboard over recycling points with the
student's own rank and consistent tie-breaking. The platform's money
rule is "balances are always SUM(delta) of the append-only ledger" — any
denormalized counter drifts.

## Decision
LeaderboardService derives the board exclusively from points_ledger
(students LEFT JOIN ledger — every student ranks; zero movements = 0
points). Ordering: points DESC, student_id ASC (deterministic). Ranking
semantics: competition ranking — equal points share the better place and
the next distinct total skips (1, 2, 2, 4). The endpoint
GET /api/v1/recycling/leaderboard serves admin/teacher/student; student
accounts additionally get 'me' (rank + points) resolved from their
account row.

## Alternatives Considered
- A cached/counter table — rejected: violates the ledger-is-truth rule.
- Dense ranking (1, 2, 2, 3) — rejected: competition ranking is the
  conventional leaderboard semantic and matches the spec's "position"
  language.
- Only ranking students with movements — rejected: a new student's 'me'
  would be null, breaking the self-service desk on day one.

## Reasoning
Same-derivation-as-balance keeps the board and every student's displayed
points in exact agreement forever; a left join guarantees rankOf always
answers; pinned by Unit + Feature tests including the tie case.

## Consequences
- The board cost is one GROUP BY over students (school-scale; fine).
- leaderboard_updated frames embed a top-3 snapshot at write time
  (ADR-032) rather than recomputing per connection.

## Status
ACTIVE
