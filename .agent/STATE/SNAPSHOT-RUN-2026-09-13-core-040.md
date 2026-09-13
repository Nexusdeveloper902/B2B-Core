# STATE SNAPSHOT — RUN-2026-09-13-core-040

## Overall Status
Marketplace panel/overflow/translation fixes + Core search hardening
DONE (uncommitted, both repos). All gates green.

## Completed
- Marketplace: hero tap-visual centered with real insets (reader 80px
  from panel edge), slide distance measured from live layout gap,
  landing demo strings translated (EN/ES), branded localized 404.
- Core: students desk live search degrades to client-side filtering on
  fetch failure (pager hidden during fallback, restored on success);
  toast region aria-label localized.

## In Progress
- Nothing.

## Blocked
- Push + remote CI pending owner PAT (RUN-036→040 uncommitted).

## Known Problems
- None new.

## Important Current Facts
- Gates: Core 453/3 + quality PASS · Marketplace 20/20.
- The marketplace locale route is `/lang/{locale}`.
- Marketplace now has `resources/views/errors/404.blade.php` (branded,
  uses `common.not_found_*` keys).
- `ledgerLoop.distance()` reads offsetLeft-based gap — layout-proof.

## Commit-and-push close-out (2026-09-13, owner-authorized)

- All three repos committed and pushed: Core 3c037ce→b8997b2→10c6a4c,
  Marketplace 8c41a78→2260dd4, Firmware 40a4fc8 (main).
- Remote CI verdict (observation loop closed): Core run 34769078472 on
  10c6a4c = SUCCESS — 13/13 jobs green + the by-design live-LLM skip,
  INCLUDING the new "Full suite on MariaDB" job on its first flight.
- Push fallout fixed en route (see RUN-2026-09-12-core-039 addendum):
  proc_open→Symfony Process harness modernization, the .env.example
  fused-line CI breakage (root cause of the first red push, pinned by
  a wellformedness test), and Marketplace's content_audit.py SSRF
  guard.
- Windows note: SIGTERM undefined on Windows PHP — use Process::stop(0).
- Marketplace/Firmware have no CI by design; their local gates stand.
