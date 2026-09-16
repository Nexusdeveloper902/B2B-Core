# ADR-064 — schools as the organization spine, enforced by a global scope

## Date
2026-09-15

## Context
Pulse had no tenant concept. Every row belonged to "the school"
implicitly: `students`, `classes`, `readers`, `rewards` and `events` were
flat tables, and every admin saw all of them. The owner now wants one
install to serve a named institution (`IE Concejo de Sabaneta J.M.C.B`)
with its own visual identity, and to be able to add more institutions
later without rewriting the UI. Branding alone would have been a lie:
if a school's name is on the shell, its data had better be its own.

The existing data walls (`StudentScope`, ADR-037) scope a TEACHER inside
a school. They say nothing about schools, and they are applied by hand
in each surface that remembered to — which is exactly the pattern that
does not survive a twentieth endpoint.

## Decision
1. **`schools` is the organization spine.** One row per institution
   (`name`, `slug`, `brand_key`). `school_id` (nullable FK,
   `nullOnDelete`) lands on the ROOT owned tables only: `users`,
   `classes`, `students`, `readers`, `rewards`, `events`,
   `roster_updates`, `recycling_updates`. Children (`cards`,
   `points_ledger`, `recycling_deposits`, `reward_redemptions`,
   `pending_pairings`, `pending_captures`) are scoped THROUGH their
   parent, so ownership is stored once and cannot drift.
   `events` is the single deliberate denormalization — it is aggregated
   directly on every dashboard render — and it is stamped from its
   READER, never from a tap payload.

2. **The wall is a model-level GLOBAL scope, not a controller habit.**
   `BelongsToSchool` (own column) and `InheritsSchoolFromParent`
   (subquery through the parent) add `SchoolOwnedScope` /
   `SchoolInheritedScope`. Index, show, update, delete, search, filter,
   bulk, CSV import, route-model binding and every future query inherit
   it for free, and a foreign id answers `404` instead of leaking a row.
   Escape hatches are explicit and unreachable from a request:
   `withoutGlobalScope(...)`, `CurrentSchool::withoutScoping()`.

3. **`CurrentSchool` answers "who is this request acting for?" once.**
   In order: an explicit `actAs` override (seeders/console) → the
   authenticated user's `school_id` → the reader resolved by
   `reader.auth` (its key IS its identity, ADR-002/ADR-062) → system-wide.
   The client never supplies an organization id, so "create X in someone
   else's organization" is not an expressible request.

4. **Creation inherits (§15).** The traits' `creating` hook stamps
   `school_id` from the current context when it is blank. An explicitly
   set value is respected (seeders, system-admin tooling) — inheritance
   fills a blank, it never overrides intent.

5. **`NULL` is a real data set, and admin+NULL is the operator.** An
   ADMIN with no school is the SYSTEM ADMINISTRATOR: unrestricted across
   organizations, the one deliberate cross-organization capability,
   granted by the account's own row. Every OTHER role with a null school
   is restricted to the null-school data set — it fails closed, never
   open (the ADR-037 rule, applied to organizations).

6. **User carries no global scope.** The session guard resolves the
   current user by querying `users`; a scope that asks "who is the
   current user?" while answering that question recurses forever. The
   places that ENUMERATE accounts say it out loud instead
   (`User::inCurrentSchool()`).

7. **Client-supplied foreign keys validate through the SCOPED model.**
   Laravel's `exists:` rule queries the table and walks past the scope,
   so `class_id`, `reward_id`, `event_id` and `class_ids[]` now use
   `OwnedByCurrentSchool` and answer `422` for a foreign id instead of
   landing an unparented row.

8. **The realtime wire is walled per CONNECTION.** `realtime:serve` has
   no request identity, so the channel readers' scope is a no-op there
   by design: the socket server resolves each connection's school from
   its token's user row (system-wide only for the system administrator)
   and filters every tap/recycling/roster frame; the pairing payload is
   an aggregate, so it is BUILT inside the connection's organization
   (`CurrentSchool::actAs`).

## Alternatives Considered
- **Per-controller `where('school_id', ...)`** — rejected: isolation that
  must be remembered in twenty controllers will be forgotten in the
  twenty-first, and route-model binding would still leak.
- **`school_id` on every table** — rejected: six more columns that can
  disagree with their parent, and a backfill per table. The subquery
  costs nothing at school scale.
- **Subdomain-per-school tenancy** — rejected as over-engineering for
  today's ask (the brief says so explicitly); nothing here forecloses it.
- **A "current organization" switcher for every admin** — rejected: it
  weakens isolation for everyone to serve one account. The system
  administrator's own row grants the cross-organization view instead.

## Consequences
- Existing installs are untouched: every pre-feature row has
  `school_id IS NULL`, the demo/pilot fixtures seed no school at all, and
  their admin is a system administrator by construction.
- `./run seed-realistic` now seeds ONE school owning every row, and the
  single operator account is seeded separately and outside it
  (`SystemAdminSeeder`).
- The leaderboard's documented "school-wide" (ADR-035, spec §22) now
  means the viewer's own school — a competition inside the institution,
  never across institutions.
- New org-owned tables must opt into a trait; forgetting is visible
  (rows come back for every school) rather than silent.
