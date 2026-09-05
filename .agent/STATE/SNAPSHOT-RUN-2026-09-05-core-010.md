# STATE SNAPSHOT — after RUN-2026-09-05-core-010

## Repository state

- Branch: main at the TASK-012 merge commit (feature/
  TASK-012-stateful-lan-access merged --no-ff; see `git log` for the
  hash)
- Working tree: clean
- Test count: 161 passed / 3 skipped (was 155/3) — +6
  StatefulDomainMatchingTest
- B2B-Firmware: updated by ITS task this same day — TASK-007 Bearer
  auth header fix (main @ f325b2e, verified there)

## What the backend does now (delta vs RUN-009)

- The default Sanctum stateful-domain list includes the host actually
  serving each request (Sanctum::currentRequestHost() placeholder):
  dashboard fetches from phones on the LAN (e.g.
  http://192.168.1.6:8000) are session-authenticated once logged in —
  no .env change needed, DHCP-proof. SANCTUM_STATEFUL_DOMAINS remains
  the full-replacement override (.env.example documents it + the
  empty-value trap).
- No new routes, no write paths, no device-endpoint changes.

## Confirmed facts (cumulative, still current)

- Reader endpoints: stateless Bearer api_key (ADR-002); readers send no
  Referer/Origin, so stateful matching never applies to them
- Admin/teacher API endpoints: auth:sanctum + role; session works from
  localhost AND any host serving the request (TASK-012/ADR-022); PAT
  auth unchanged
- Pairing desk: arm-then-pair via dashboard (ADR-020/021); the desk has
  NO manual pair form — the only pair writer is the device-side
  POST /api/v1/admin/cards/pair (reader Bearer key)
- The device-side 401s in the owner's bench log were the firmware's
  Basic/Bearer scheme bug (B2B-Firmware TASK-007) — the backend pair
  contract was verified correct and is untouched here

## Bench expectations after the owner pulls + restarts serve

- Phone: open http://<lan-ip>:8000 (serve --host=0.0.0.0), log in,
  Arm pairing → works; countdown + success line behave like desktop.
- Device: with B2B-Firmware TASK-007 flashed, tap within the window →
  pairing completes on serial AND the desk page.

## Open items

- Real-phone verification is the owner's bench item (honesty boundary
  in RUN-010)
- Deferred again: GET /api/v1/reader/me boot-time key check
