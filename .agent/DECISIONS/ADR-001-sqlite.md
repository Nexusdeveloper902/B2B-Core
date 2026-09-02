# ADR-001

## Date
2026-09-02

## Context
The core platform needs a database for demo-scale deployment (a handful of
students/cards/readers, low event volume) while keeping a credible growth path.
The project's sibling app (marketplace) already chose SQLite.

## Decision
Use SQLite as the database (`database/database.sqlite`) via
`DB_CONNECTION=sqlite`. All data access goes through Eloquent.

## Alternatives Considered
- MySQL/Postgres from day one — rejected: extra infra for zero demo value, and
  the task explicitly forbids introducing them in this phase.
- Flat-file/JSON storage — rejected: no relational integrity for the
  event/card/reader graph.

## Reasoning
Demo scale does not justify a server; Eloquent isolates the app from the storage
engine; migrating later is a config change (`DB_*`), not a rewrite.

## Consequences
- Zero-infra local runs (school laptop demo).
- Concurrent writer contention is acceptable at demo volume.
- Testing uses `:memory:` SQLite for speed (phpunit.xml).

## Status
ACTIVE

## Supersedes
none
