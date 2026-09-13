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
