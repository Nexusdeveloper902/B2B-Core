# ADR-043 — Full-stack audit hardening pass (2026-09-09)

## Context

A cross-repo audit (Core, Marketplace, Firmware) found concrete defects that
warrant code changes now, ahead of any new feature work. None of them change
product behavior for a legitimate user; each closes a gap a hostile input,
an orphaned account, a network failure, or a heavier deployment could hit.

## Decisions

1. **Rate limiting on the two expensive doors.** `POST /login` is throttled
   6/min per IP (brute-force guard); `POST /api/v1/nl-query` is throttled
   20/min per USER (each forwarded question is a paid DeepSeek call; a
   per-user key lets a staff room share NAT without starving each other).
   Limiters live in `AppServiceProvider` under the `login` / `nl-query`
   names.

2. **StudentScope fails CLOSED for orphaned student accounts.**
   `users.student_id` is nullable by design (`nullOnDelete`). The old
   construction handed a student account with no linked row the ADMIN scope
   shape (`null classIds + null studentId`) — school-wide data over the
   realtime tap channel. Now `forUser()` returns an empty allowlist, and
   `RealtimeServeCommand::resolveScope()` mirrors it. A student dashboard
   request from such an account answers a clean 404 instead of a fatal.

3. **Inline-script JSON literals use `Js::from()`** (hex-tags
   `< > ' & "`) in the pairing/students desks, replacing bare
   `json_encode` — the value-match for a card UID is operator-influenced
   data crossing into a `<script>` context.

4. **The realtime socket server caps the pre-handshake buffer** (32 KB,
   drop beyond). Post-handshake frames were already capped by
   `WsFrame::MAX_INBOUND`; a client dribbling handshake bytes forever was
   the one unbounded memory surface. The dead `isAdmin()` helper (superseded
   by `resolveScope()`) is removed.

5. **SQLite ships with `busy_timeout=5000` + WAL journal mode.** The
   realtime poller reads every ~300 ms while HTTP writes award points; the
   previous defaults could 500 a write on a poller lock collision.

6. **`cards:unpair` documentation matches its real semantics:** recycling
   deposits DO cascade away with the events (unique cascade FK) and their
   stored images are now removed with them; point balances survive. The
   command counts and prints deposits + images. A test pins the contract.

7. **Redemption idempotency closes its race:** the `request_id` replay
   lookup is scoped to the calling student (no cross-student balance leak),
   and the request_id UNIQUE index violation from two concurrent submits
   converts to a replay (or a `duplicate` rejection for a foreign key)
   instead of a raw 500.

8. **The NL tool loop honors its documented bound** (max 3 tool rounds; the
   old `<=` executed a 4th round whose results were discarded), and an
   unknown `RECYCLING_CLASSIFIER_DRIVER` surfaces as the standard 503
   classifier-unavailable response instead of a 500.

9. **Frontend defect round:** student history running balance seeds the
   accumulator with the carried-in balance (page-local sums were wrong
   after row 25); `tr[hidden]` wins over the mobile stacked-table CSS
   (teacher search / parent filters were dead ≤620 px); the `panel rule`
   prop actually renders `.panel-rule`; NL query boxes close their
   aria-live region on network failure; parent timeline counter interpolates
   from a template attribute and a filter-empty note exists; live JS rows
   carry `data-label` for the mobile stacked cards; hardcoded `PTS` units
   move to `app.points_unit`; login errors get `aria-describedby`; the
   leaderboard's `is-first` hero follows the live re-sort; reader-mode
   feedback announces in its own live region.

## Consequences

- No route, contract, or visual identity changes. All 329 Core tests pass;
  Pint clean. `README.md`/`README.es.md` and `docs/SCRIPTS*` updated to
  describe the system as it is (Datum, real e2e count, `unpair` in the
  command table).
