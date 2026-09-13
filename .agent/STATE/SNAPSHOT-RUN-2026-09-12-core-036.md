# STATE SNAPSHOT — RUN-2026-09-12-core-036

## Overall Status
Productization pass DELIVERED (uncommitted tree). The product is
**Pulse** across Core/Marketplace/Firmware user-visible surfaces, with
the real brand suite in place, the brand-anchored token palette, a
global toast system, and branded 419/429 error pages. All local gates
green. No commits (owner review pending; no PAT this run).

## Completed
- Pulse identity: name, real logo assets, favicon/PWA/OG set,
  manifest, titles — Core + Marketplace + Firmware.
- Palette: tokens.css anchored to #CFDBD5/#E8EDDF/#F5CB5C/#242423/
  #333533 (ADR-047); gold-on-ink action grammar.
- Toast system (ADR-048) wired into every desk incl. silent catches.
- 419/429 branded error pages; Postman/scripts/docs/readmes rebranded
  (EN+ES).
- e2e hermeticity: classifier driver pinned to stub in scripts/e2e.sh.
- Docs: BRAND.md + BRAND.es.md, FRONTEND.md/.es.md §3f.

## In Progress
- Nothing mid-flight.

## Blocked
- Remote CI observation: needs owner PAT (push + watch run).

## Known Problems
- Local .env still has RECYCLING_CLASSIFIER_DRIVER=deepseek with no
  key → live dashboards' classify flow 503s until the owner supplies
  DEEPSEEK_API_KEY or flips the driver back (e2e unaffected now).
- Desk fetches omit Accept-Language → API messages in inline boxes
  render EN under ES (pre-existing; TASK-036).

## Important Current Facts
- Suite: 453 passed / 3 skipped · quality PASS · e2e 33/33 ·
  Marketplace 20/20.
- Working tree: RUN-036 changes + the (verified) teacher class-search
  diff from the previous session — both uncommitted.
- `public/brand/*` + `public/manifest.webmanifest` exist in BOTH
  Laravel repos; `favicon.svg` no longer exists in either.
- Tests pin: Pulse titles, mark-96 in shell, wordmark-tap ABSENT,
  brand hexes, toast.js + desk PulseToast calls, 419/429 views,
  `# Pulse` README H1 (EN), Marketplace pages contain "Pulse".
