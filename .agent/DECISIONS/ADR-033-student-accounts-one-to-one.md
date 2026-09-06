# ADR-033

## Date
2026-09-07

## Context
The spec §11/§12/§30 requires student self-service accounts (login,
own points/history/rewards) while the platform's identity spine is the
students table (cards reference it; attendance, PAE and recycling all
join it). A second student identity inside a users table would fork the
spine.

## Decision
Add UserRole::Student and a unique nullable users.student_id FK: an
account LAYER, not an identity. Login reuses the session flow verbatim;
students land on /student (own points, history, rewards). Every query in
the student desk resolves the student from the authenticated account —
a URL parameter can never select another student's data. Staff routes
remain role-gated (students get 403 on /dashboard and /admin).

## Alternatives Considered
- Email/password columns on students — rejected: mixes auth state into
  the domain table; password resets would touch domain rows.
- A separate students_auth table — rejected: same fork risk with an
  extra join.

## Reasoning
users already owns authentication (Laravel's Authenticatable, session
guard, Sanctum tokens); the students table already owns identity. The
1:1 FK composes them without duplicating either. Demo accounts are
seeded and printed like staff logins.

## Consequences
- Deleting a student nulls the account's link (nullOnDelete) rather than
  cascading into users.
- Realtime token issuance extends to students (frames carry no card UIDs
  — the pairing channel stays admin-only).
- The student leaderboard 'me' field resolves from the account, never
  from query input.

## Status
ACTIVE
