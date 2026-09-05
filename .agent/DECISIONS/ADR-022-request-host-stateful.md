# ADR-022: The host serving a request is a stateful domain (LAN phone access)

## Status
Accepted (2026-09-05, TASK-012)

## Context
Owner's bench report (2026-09-05, pairing desk live): "when i run the
website in localhost and i enter in my phone (via network access) i log
in, click arm pairing and it says unauntenthicaded."

The dashboard pages are plain web routes (session cookie, host-only for
whatever host served them — login from a phone at
`http://192.168.1.6:8000` works). But the desk's JS fetches
(`/api/v1/admin/students/{id}/arm-pairing`, `/api/v1/admin/pairing/status`)
are guarded by `auth:sanctum` under `$middleware->statefulApi()`. Sanctum
authenticates such requests via the session ONLY when
`EnsureFrontendRequestsAreStateful::fromFrontend()` matches the request's
Referer/Origin host against `sanctum.stateful` — and that list defaulted
to `localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1` + APP_URL.
A phone's referer `http://192.168.1.6:8000/...` matches nothing → the
request is treated as stateless → no session → 401 "Unauthenticated",
seconds after a successful login.

The same bench session's device-side 401 (pairing taps) was a separate
root cause fixed in B2B-Firmware TASK-007; this ADR covers only the
session-side one.

## Decision
1. **Add `Sanctum::currentRequestHost()` to the default stateful list**
   (config/sanctum.php). The placeholder is swapped at request time for
   the host actually serving the request (`$request->getHttpHost()`,
   host:port), so ANY host the app is reachable at — 127.0.0.1,
   localhost:8000, 192.168.1.6:8000, a future production domain — is
   first-party for its own same-origin dashboard fetches. A DHCP address
   change needs no .env edit.
2. **`SANCTUM_STATEFUL_DOMAINS` stays the explicit override** and
   replaces the default entirely (documented in .env.example + API.md
   EN/ES, including the trap that an EMPTY uncommented value disables
   all stateful hosts).
3. **Pinned by tests** (`tests/Unit/StatefulDomainMatchingTest.php`,
   6 tests): the phone request is stateful; localhost stays stateful; a
   referer from an unrelated host is NOT stateful (forging a referer
   must not unlock session auth — only the request's own host counts);
   no-referer requests stay stateless (device endpoints unchanged); an
   explicit config list is respected; the placeholder survives in the
   default config.

## Rationale
- Stateful requests still run the full Sanctum pipeline
  (EncryptCookies, StartSession, ValidateCsrfToken, AuthenticateSession)
  — an attacker forging `Referer: http://<api-host>/` from elsewhere
  gains nothing: cross-site POSTs do not carry the SameSite=lax session
  cookie, and CSRF is enforced on the stateful path.
- The matching rule is pinned at the `fromFrontend()` level because a
  full-stack session test cannot honestly produce the pre/post-fix
  differential: within one test, the auth guard and session store are
  shared across requests, keeping the user authenticated regardless of
  this middleware (recorded as a testing pitfall in RUN core-010).
- Alternative rejected — telling every bench owner to hand-maintain
  `SANCTUM_STATEFUL_DOMAINS=192.168.x.y:8000`: fragile (DHCP), and the
  failure mode is exactly the confusing "logged in but Unauthenticated"
  this task exists to remove.

## Consequences
- Phones (or any second device) on the LAN can drive the pairing desk
  end-to-end after `git pull` + server restart; no .env change needed.
- The doc/API.md note tells owners to serve on all interfaces
  (`./run serve --host=0.0.0.0`) so phones can reach the host at all.
- Device endpoints are untouched: readers send no Referer/Origin, so
  `fromFrontend()` is false and their Bearer-key flow stays stateless.
- If a deployment ever wants to narrow this, SANCTUM_STATEFUL_DOMAINS
  is the documented lever (with the empty-value trap called out).
