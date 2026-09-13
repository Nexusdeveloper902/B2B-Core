# ADR-053 — kitchen role and the /kitchen glanceable desk

## Date
2026-09-14

## Context
Meal-service staff need the serving verdict the instant a card taps —
but the admin dashboard is a dense operations console (KPI strip, live
feed, NL box, redemption desk) that requires scanning. The PAE Full
Program spec asks for a dedicated kitchen workflow: normal login,
restricted to one page, realtime, glanceable, no photos (the platform
has none), bilingual.

## Decision
1. **`UserRole::Kitchen`** ('kitchen'): authenticates through the
   normal login flow; login/landing/intended-URL redirects land on
   `/kitchen`; every other desk's `role:` wall 403s kitchen users
   (teachers/students cannot open `/kitchen`; admins MAY, for
   verification).
2. **`/kitchen`** (ADR layout): the primary surface is a huge state
   panel — waiting (sage) / accepted (green, check icon, student name,
   class, time) / rejected (red, x icon, the localized reason) — driven
   by the same realtime.js client the dashboards use (hidden boot node;
   this page owns its PAE-only recent list; idempotent
   update-or-prepend handlers for the three arrival paths).
   Pre-painted server-side from the newest PAE row so a reload never
   loses the last verdict.
3. **Realtime scope**: kitchen connections see the full tap channel
   (name-level data, same exposure as tap frames) but stay non-admin:
   pairing frames (card UIDs) and roster frames never cross a kitchen
   wire. `/realtime/token` mints kitchen tokens; the WS server resolves
   the role per connection, fail closed.
4. **Feed semantics on the wire**: RealtimeFeed rows carry `served` +
   `reason` — the kitchen page colors its state from them; the admin
   dashboard's live KPI bumping skips `served === false` frames.

## Alternatives Considered
- Polling the REST feed: sub-second feel requires ~1 s polls from every
  kitchen screen; the WS channel already exists and the peak load
  (<50 taps/min) is nothing for the hand-rolled server.
- Restricting kitchen scope to PAE_* rows only in clientSeesRow: less
  data, but attendance taps are harmless name-level rows and the
  filter would need a per-frame type check for little privacy gain.

## Consequences
- A fourth user-visible role: login page demo chips, nav grammar and
  redirect rules extended (the TASK-027 intended-URL rule generalizes:
  kitchen only ever lands on /kitchen).
- The kitchen list/state is bench-verifiable in the browser (proved:
  tap → green/red within one WS poll, zero console errors).

## Status
ACTIVE
