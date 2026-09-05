# TASK-012-stateful-lan-access

Owner's bench report (2026-09-05, same session as the firmware pairing
401s): "when i run the website in localhost and i enter in my phone (via
network access) i log in, click arm pairing and it says unauntenthicaded."

The web login from the phone works (web routes + host-only session
cookie), but the pairing desk's API fetches are `auth:sanctum` under
`statefulApi()`: Sanctum only session-authenticates a request whose
Referer/Origin host is in `sanctum.stateful`, and that list was
localhost-only — so the phone's `http://192.168.1.6:8000` origin was
never stateful and the fetch answered 401 "Unauthenticated" right after
a successful login.

## Deliverables

1. `config/sanctum.php` — `Sanctum::currentRequestHost()` joins the
   DEFAULT stateful list: the host actually serving each request is
   first-party for its own same-origin dashboard fetches (works for any
   LAN IP, survives DHCP changes; bilingual comment records why).
   `SANCTUM_STATEFUL_DOMAINS` remains the full-replacement override.
2. `.env.example` — TASK-012 guidance block: leave unset for the
   default (phones work out of the box); set only to pin an explicit
   host list; warns that an EMPTY uncommented value disables ALL
   stateful hosts.
3. `tests/Unit/StatefulDomainMatchingTest.php` — 6 tests pinning
   `EnsureFrontendRequestsAreStateful::fromFrontend()`: LAN request host
   stateful; localhost still stateful; unrelated-host referer NOT
   stateful; no-referer (device) requests stay stateless; explicit
   config list respected; placeholder present in the default config.
4. Docs: docs/API.md + API.es.md "Using the dashboard from another
   device on your LAN (TASK-012)" — behavior, `--host=0.0.0.0` serving
   note, override lever, device-endpoint non-impact.
   DocumentationTest needles extended (TASK-012,
   SANCTUM_STATEFUL_DOMAINS, --host=0.0.0.0 in EN+ES).
5. `.agent` records: ADR-022, this task file, RUN-2026-09-05-core-010 +
   ledger, STATE snapshot, PROJECT.md facts.

## Acceptance

- [x] config/sanctum.php default includes the request-host placeholder
- [x] 6 new tests; suite = 161 passed / 3 skipped (was 155)
- [x] ./run quality PASS (one Pint FQCN fix during the run)
- [x] ./run e2e 22/22
- [x] API.md + API.es.md bilingual note; DocumentationTest needles green
- [x] .env.example override guidance with the empty-value trap
- [x] No new routes, no write paths, no device-endpoint changes

## Out of scope (deliberately)

- No change to the pairing desk JS (it already sends X-CSRF-TOKEN +
  same-origin credentials; once stateful, the phone flow is identical
  to the desktop one that the owner's bench log shows working).
- No CSRF/session config changes (the stateful pipeline already
  enforces them on desktop; the phone now goes through the same path).
- The device-side 401 of the same bench session is B2B-Firmware
  TASK-007 (Basic vs Bearer Authorization scheme) — fixed there.
