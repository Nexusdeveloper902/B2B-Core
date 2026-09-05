# TASK-015-colombia-timezone

Owner directive (2026-09-06): "change the timezone of the whole thing to
be colombia instead of whatever it is set at." The platform is deployed
at a Colombian school and every clock the owner looks at (dashboard
stats, attendance tables, pairing history, the API's `occurred_at`) ran
on UTC.

## Decision (ADR-025)

Single-timezone app: `config('app.timezone')` is `America/Bogota`
(COT, fixed UTC−5, no DST) by default, env-overridable via
`APP_TIMEZONE`. Wall-clock semantics everywhere — `now()`, Eloquent
datetime storage, and display are all the same Bogota local time, so no
per-render conversion layer exists. Storing UTC and converting at
display was rejected: a single-LAN, single-school deployment gains
nothing from a storage/display tz split and pays for it with a
conversion bug class.

## Deliverables

1. `config/app.php` — `'timezone' => env('APP_TIMEZONE', 'America/Bogota')`.
2. `.env.example` — documented `APP_TIMEZONE=America/Bogota` knob.
3. `tests/Unit/TimezoneTest.php` — pins the default, the fixed −05:00
   offset, Eloquent round-trip in Bogota wall time, and the ISO string
   the API emits (explicit `-05:00` offset, honest for device parsers).
4. `docs/API.md` + `docs/API.es.md` — "Times are Colombia local"
   convention note; `DocumentationTest` needle `America/Bogota` on both
   languages.
5. `.agent` records: ADR-025, this task file, RUN + ledger, STATE
   snapshot, PROJECT.md facts.

## Acceptance

- [ ] Full suite green with the tz switch (existing boundary tests —
      07:50 present / 09:10 late vs 08:15 cutoff — now mean Bogota
      school-local time, which is the honest reading)
- [ ] `./run quality` PASS · `./run e2e` 22/22
- [ ] Bench note recorded: dev DB rows created before this change are
      UTC-stamped; after pulling, `./run reset` re-seeds demo data on
      Bogota time (pre-change rows would read 5 h off otherwise)

## Out of scope (deliberately)

- Per-user timezone selection (one school, one timezone).
- Migrating pre-existing row timestamps (bench DB is demo data; the
  sanctioned reset is `./run reset`).
- Firmware changes (the device sends optional client timestamps with
  explicit offsets; the API already accepts any ISO 8601 input).
