# ADR-037

## Date
2026-09-08

## Context
Teachers could open ANY student's timeline (`/parent/students/{id}`),
redeem for any student, read the school-wide live feed, and (with the
new NL surface) would ask questions about the whole school. The owner's
rule: teachers only reach students enrolled in the classes THEY teach.
With per-endpoint bespoke scoping the wall would drift — five surfaces
today, more later.

## Decision
One explicit scope object: `App\Services\StudentScope` —
`forUser(User)` resolves admin = unrestricted, student-account = exactly
their own row, teacher = own classes (`user->classes()->pluck('id')`).
Every teacher/student-reachable surface that resolves a student applies
the scope INSTEAD of trusting the URL or the route-model binding:
parent timeline (403 on `allowsStudent`), redemption desk, the SSR live
feed (row filter), every NL-query function execution (explicit
`Class [X] is outside the caller's scope` errors, never silent data),
and the realtime WS channel (per-connection scope resolved ONCE at
handshake, fail closed on any doubt). Deliberate exception: the
recycling leaderboard stays school-wide (spec §22 — a public
competition board by design).

## Alternatives Considered
- Prompt-side promise ("tell the model the teacher only sees their
  classes") — rejected: a prompt is not a security boundary; the model
  could still request and receive school-wide rows.
- Global query scope on the Student model — rejected: it would also
  fence admin contexts and migrations/commands that legitimately need
  school-wide reach; scope must be explicit per caller.
- Per-endpoint hand-rolled checks — rejected: five bespoke walls drift
  apart; one object with one semantic is testable.

## Reasoning
The wall is server-side data filtering at the point of query
construction — a teacher's request for an out-of-scope class/student
answers with an explicit scope error, so there is no silent data leak
AND no silent empty answer (the model is told why). Scope resolution at
handshake (not per frame) keeps the WS hot path allocation-free while
still fencing both the live frames and the hello snapshot.

## Consequences
- `StudentScope::forUser` is the ONLY entry point; direct
  `user->classes()` checks in request paths are a smell (the feed's
  row filter and the WS resolver both derive from the same object).
- Recycling totals and the leaderboard remain school-wide by spec —
  documented in the scope's docblock so the exception is deliberate.
- New teacher-reachable surfaces MUST take a StudentScope parameter
  (convention pinned by the NL FunctionRegistry signature).

## Status
ACTIVE
