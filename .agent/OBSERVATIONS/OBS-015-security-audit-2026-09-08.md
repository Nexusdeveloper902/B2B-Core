# OBS-015 — security audit of the whole surface (TASK-027 item 17)

## Date
2026-09-08

## Observation
Owner-requested full security audit: authentication, authorization,
API endpoints, database access, student data, device↔server
communication, overall attack surface. This observation is the audit
record; findings that needed code were fixed IN TASK-027 (each pinned
by tests), and what cannot be fixed in code is recorded honestly as
residual risk.

## Method
Full-tree review of every route (web + api), every middleware
(EnsureRole, ResolveReaderToken, SetApiLocale/SetWebLocale, Sanctum
statefulApi), the realtime WS server, the JS surfaces (realtime.js,
markdown.js, both dashboards, three desks), models/fillables,
requests/validation, storage disks, the seeder, and the device
contract in B2B-Firmware. Findings cross-checked by executing the
role walls as guest/teacher/student/admin in the test suite.

## Findings — FIXED in TASK-027 (each with a pinning test)

1. **Teacher over-reach (HIGH)**: `/parent/students/{id}`,
   `POST /students/{id}/redeem`, the school-wide live feed and the SSR
   feed were reachable by teachers for ANY student by URL param. Fixed
   by the StudentScope data wall (ADR-037): 403 at the wall; the WS
   channel resolves the scope per connection at handshake, fail
   closed, and filters live frames AND the hello snapshot.
2. **Intended-URL login redirect bug (MEDIUM)**: a guest hitting
   /teacher stored `url.intended`; a student logging in next was
   replayed into /teacher → 403 (and symmetrically for staff into
   /student). Not an open redirect (Laravel's intended only replays
   internal paths), but a role-wall bypass-by-confusion. Fixed in
   AuthController::postLoginTarget: the intended URL survives only
   when the freshly-authenticated role can open it.
3. **Capture-image privacy (HIGH)**: images lived on the private disk
   (good) with NO way to view them (gap E1). The temptation — copying
   to the public disk — was avoided: one admin-authed streaming route
   (ADR-040), `Cache-Control: private`, role wall before any byte.
4. **Model-output XSS surface (HIGH by definition)**: the new Markdown
   rendering of NL answers uses an escape-FIRST renderer (ADR-041) —
   only elements it creates itself can ever appear; no links, no
   images, no raw HTML. Verified: no other innerHTML path for nl-answer.
5. **CSRF on the new write surface**: all new endpoints
   (readers/students/import/cards-delete/captures-image-GET) ride the
   Sanctum stateful middleware with X-CSRF-TOKEN from the meta tag;
   the desks' fetch helpers send it on every verb.
6. **PAE integrity (MEDIUM, data-integrity class)**: a non-PAE student
   tapping a PAE reader used to be COUNTED as fed. Now a 422 with a
   device-facing localized message + a logged audit trail (feeding
   program integrity — spec's honest-numbers rule).
7. **Realtime channel (HIGH)**: previously admin-vs-everyone binary
   with school-wide history for any authenticated connection. Now
   per-connection role+scope, resolved once at handshake, fail closed
   on any DB doubt; hello snapshot scoped identically.

## Findings — sound by construction (verified, no change needed)

- **Device auth**: Bearer static keys per reader; the key IS the
  reader identity (client-supplied reader ids never trusted — the
  ResolveReaderToken middleware owns resolution).
- **Mass assignment**: every new write goes through a FormRequest
  (StudentStoreRequest, ReaderSettingsRequest) with explicit rules;
  models use $fillable allow-lists.
- **SQL injection**: Eloquent/Query Builder throughout; the only
  `DB::raw` uses are constant expressions (COALESCE/CAST in the
  leaderboard) with no user input.
- **File upload**: CSV import validates `mimes:csv,txt` + 2 MB +
  500-row cap; rows are parsed defensively (trim, type-safe casts,
  per-row errors). Capture images are consumed by the classifier
  pipeline, not served back verbatim anywhere public.
- **Secrets**: DEEPSEEK_API_KEY and reader keys live in .env (git-
  ignored; gitleaks-scanned in CI); PAT for pushes is per-command
  env var only (never in the tree).
- **Session/auth**: Laravel defaults (encrypted cookies, same-site).

## Findings — residual risk, accepted or deferred (honest ledger)

1. **No rate limiting anywhere — including login**: neither the login
   route nor any API endpoint has a throttle middleware (LoginRequest
   carries none; routes carry none). A LAN host could brute-force
   passwords or hammer tap/classify/NL endpoints. Cheap to add
   (throttle middleware) but needs a limit policy decision (devices
   legitimately tap in bursts; a wrong limit breaks the bench) —
   flagged as the top residual item.
2. **Plain HTTP on the LAN**: the server and devices speak HTTP; the
   reader API key travels in a cleartext header. LAN-trust model
   (documented) — TLS via reverse proxy is deployment work, not code.
3. **Static device keys, no rotation flow**: reader keys rotate only
   by SQL/CLI today. Acceptable at this scale; rotation command +
   key versioning would be a task of its own.
4. **No audit log for admin reads** (timeline views, capture image
   streams): writes and device events are logged; human READS of
   student data are not. Noted for a future compliance pass.
5. **NL answers remain model output**: the system prompt forbids
   fabrication, functions return real numbers, but a jailbroken model
   could still phrase misleadingly. The desk labels answers as
   AI-generated; scope errors are explicit. Residual risk accepted.

## Impact
The audit's actionable items are all shipped and pinned (TASK-027);
the residual ledger above is the honest boundary of what code can fix
today. Future tasks should start from the residual list.

## Related Task
TASK-027-ux-hardening-gui-completion (item 17)

## Status
CONFIRMED (audit executed and re-verified this run)
