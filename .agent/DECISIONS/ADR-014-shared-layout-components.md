# ADR-014: Shared Blade layout + component structure for the UI passover

## Date
2026-09-03

## Context
TASK-005 required every page to live on one shared layout consuming the
ADR-013 design tokens, with reusable partials instead of per-page ad hoc
markup (the TASK-002 views each carried their own table/button soup).

## Decision

- **`resources/views/layouts/app.blade.php`** is the single app shell:
  skip link → topbar (wordmark + tap mark, role-aware `topnav` with
  `is-active` underlines, user chip + logout, EN/ES `langswitch`) →
  `main#main` page frame (`.shell`, flash notices) → ink footer
  (wordmark, note, legal rule with env).
- **Anonymous Blade components** in `resources/views/components/`:
  - `panel` (mono uppercase label + optional ink `rule` emphasis border)
  - `stat` (ledger stat block: mono label + display value)
  - `stamp` (bordered status badge: present/late/absent)
  - `empty` (dashed empty-state box, `role=status`)
  - `field` (form field: label + control slot)
- **CSS layering**: `public/css/tokens.css` (tokens) + `app.css`
  (components) + `fonts.css` (self-hosted @font-face) — no build step,
  matching ADR-008-era plain-CSS approach.
- **JS wiring is load-bearing**: the admin dashboard script rewrites
  `className` (`nl-answer`, `answer-ok/error`, `hidden`) and queries
  fixed ids (nl-query-form, redeem-form, mode-form). Views keep those
  names verbatim; new visual classes are additive only.
- Dead Laravel scaffold `welcome.blade.php` deleted (route `/` always
  redirected; the file was unreachable).

## Alternatives Considered
- Component slot forms with named error slots — rejected: more surface
  than needed for 5 components.
- Tailwind/Vite pipeline — rejected (constraint; see ADR-013).
- Livewire components — rejected: adds a runtime dependency for what is
  a static restyle; fetch-based JS is preserved as-is.

## Reasoning
Anonymous components keep the ledger grammar repeatable without a
build step or runtime framework; the layout mirrors the marketplace's
topbar/footer conventions by structure (wordmark → nav → tools) so
cross-app navigation feels continuous while ids/classes stay
core-platform-specific.

## Consequences
- New pages MUST extend `layouts.app` and use the components — no new
  ad hoc page shells.
- Any future change to the admin dashboard's inline JS must keep the
  `nl-answer`/`answer-*`/`hidden` contract or update both the script
  and `app.css` together.
- `resources/css/app.css` (dead Tailwind scaffold, never built) is
  still present and unused; removal recorded as follow-up work.

## Status
ACTIVE

## Supersedes
None (TASK-002 had no styling ADR; its no-build-CSS approach is kept).
