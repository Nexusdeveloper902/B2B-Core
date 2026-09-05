# ADR-025 — Single-timezone app: America/Bogota wall clock

- **Status:** accepted (2026-09-06, TASK-015)
- **Context:** the owner directed "change the timezone of the whole
  thing to be colombia instead of whatever it is set at." The platform
  is deployed at a single Colombian school; until now
  `config('app.timezone')` was the Laravel skeleton default `UTC`, so
  every clock the owner reads (dashboard "today" boundaries, attendance
  times, pairing history, `occurred_at` in the API) ran five hours off
  the school's wall time.
- **Decision:** ONE timezone, wall-clock semantics end to end.
  `config('app.timezone')` = `env('APP_TIMEZONE', 'America/Bogota')`
  (COT, fixed UTC−5, no DST — PHP's tz database has no transitions for
  it, so naive `H:i` comparisons such as the 08:15 late cutoff are
  always school-local). `now()`, Eloquent datetime storage and every
  rendered clock are the same Bogota local time; API-facing ISO 8601
  strings carry the explicit `-05:00` offset so device parsers never
  guess. `client_timestamp` (any ISO 8601 offset) is honored as-is, as
  before.
- **Rejected — store UTC, convert at display:** a single-LAN,
  single-school deployment gains nothing from a storage/display tz
  split and pays for it with a conversion bug class (every render site
  must remember to convert; one forgotten site shows the exact
  five-hours-off symptom this task exists to kill).
- **Rejected — per-user timezone preference:** one school, one
  timezone; a preference matrix solves nothing that exists.
- **Consequences:**
  - New rows are Bogota-stamped. Rows created before this change (the
    bench demo DB) are UTC-stamped and would read 5 h off; the
    sanctioned fix is `./run reset` (re-seeds demo data on Bogota
    time). Pre-production there is no data worth migrating.
  - `APP_TIMEZONE` remains the escape hatch for any future deployment
    outside Colombia (env knob, documented in `.env.example`).
  - Test suite: existing boundary tests (07:50 present / 09:10 late vs
    the 08:15 cutoff) now mean Bogota school-local time — the honest
    reading of "school morning". Epoch-based assertions are
    offset-independent and unaffected.
  - The reader's optional `client_timestamp` contract is unchanged
    (byte-identical wire behavior; the API docs now state the server
    clock's zone explicitly).
