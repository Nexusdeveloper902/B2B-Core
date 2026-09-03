# ADR-013: Design tokens — value-matched from the marketplace ("The Event Ledger")

## Date
2026-09-03

## Context
TASK-005 (UI passover) requires the core platform to be visually
consistent with the marketplace storefront. The marketplace design
system ("The Event Ledger", marketplace ADR-002) is defined in
`public/css/app.css` + self-hosted fonts of the B2B-Marketplace repo.
Consistency is maintained **by value-matching** (same colors/fonts/
patterns across two independent codebases), NOT by a shared package —
a shared design-token package is explicitly out of scope for this task.

Reference extracted read-only from B2B-Marketplace @ commit ecde2d5.

## Decision

Adopt the marketplace's design tokens verbatim in the core platform,
encoded as CSS custom properties in `public/css/tokens.css` (the single
source of truth for this app), consumed by `public/css/app.css`.

### Extracted palette (literal values)
| Token | Value | Marketplace role |
|---|---|---|
| `--paper` | `#F3F4F0` | page ground (cool porcelain, green-grey cast) |
| `--surface` | `#FFFFFF` | content surfaces (tables, panels, fields) |
| `--ink` | `#101D18` | deep green-black text; emphasis borders |
| `--pine` | `#0A5C38` | primary brand green: CTAs, links, rules |
| `--pine-dark` | `#07422A` | CTA hover |
| `--go` | `#1D9E5F` | signal green — sparing: live dots, success |
| `--steel` | `#53615A` | secondary text |
| `--line` | `#D7DCD5` | hairline rules/borders |
| `--wash` | `#E9EEE9` | tinted alternating ground |
| `--alert` | `#B3261E` | form errors only |
| (literal in CSS) | `#37453F` | body copy on paper |
| (literal in CSS) | `#CBD5CC` | hairlines on wash |
| (literal in CSS) | `#EEF1EC` | ledger table row rules |
| (literal in CSS) | `#97A29A` | input borders |
| (literal in CSS) | `#DCEDE2` | "row written" flash |
| (on ink) | `#E8F0EA` / `#AEBAB2` / `#98A8A0` / `#8FA096` / `#97A69D` / `#263330` | ink-band text/legal/borders |

### Extracted typography (self-hosted woff2, no CDN)
- Display: **Space Grotesk** 500/600/700 — h1 `clamp(2.15rem, 4.6vw, 3.35rem)` 700 ls `-0.024em`; h2 `clamp(1.45rem, 2.6vw, 1.7rem)` 600; h3 `1.12rem` 600; headings lh 1.12, ls `-0.012em`
- Body: **IBM Plex Sans** 400/400i/500/600 — `16px/1.62`
- Data: **IBM Plex Mono** 400/500 — ONLY for literal event data (timestamps, IDs, event types, reader IDs, points): table headers `11.5px/1 500 ls .05em steel`; table cells `13px/1`
- Font files: copied 1:1 from marketplace `public/fonts/` (same woff2 files, `@font-face` via `public/css/fonts.css`)

### Extracted spacing / radius / component grammar
- Radius: **2px** on buttons/inputs; everything else square. NO rounded-card kit, NO box-shadows, NO gradients.
- Shell: `max-width 1120px`, `padding-inline clamp(20px, 5vw, 40px)`
- Topbar: paper ground, `1px solid var(--line)` bottom border, min-height 76px (64px ≤940px); wordmark = 11px pine square "tap" mark (inset ring) + `Presence` `Platform` (em = steel 500) in Space Grotesk 700 19px
- Buttons: padding `14px 24px` (topbar `10px 16px`), border `1.5px`, radius 2px, font `600 15px/1`; primary = pine/white (hover pine-dark); quiet = transparent + ink border (hover fills ink/paper)
- Nav links: `500 15px`, hover/active = pine underline 2px, offset 8px
- Langswitch: `600 13px`, `/` separator, active = underline `--go`
- Panels: surface + `1px line` border, emphasis variant `border-top: 2px solid var(--ink)`; mono label 12px pine ls `.06em`
- Ledger tables: mono cells, `#EEF1EC` row rules, last/emphasized values pine 500
- Forms: label `600 14px`; input `15px`, border `1px #97A29A`, radius 2px, padding `11px 14px`; focus = pine border + `box-shadow 0 0 0 1px pine`; invalid = alert; hint steel 13px; error alert `13.5px 500`
- Status marks: 7px pine squares (list bullets), 9px `--go` live dots
- Footer: ink ground, paper wordmark, legal 13px, `#263330` rule
- Focus floor: `:focus-visible` outline `2px pine` offset 2px (footer `--go`); skip-link
- Responsive: 940px / 620px breakpoints; nav collapses to `<details>` (no JS)
- Motion: `prefers-reduced-motion` respected

## Alternatives Considered
- Keep the TASK-002 blue/rounded plain-CSS look — rejected: generic CRUD look is the problem being fixed.
- Introduce Tailwind/Vite build to match a hypothetical reference stack — rejected: marketplace itself ships plain CSS + self-hosted fonts (no build), and the constraint forbids new pipelines.
- Shared design-token package across repos — out of scope by task definition.

## Reasoning
The marketplace ADR-002's own constraint list applies forward: no
cream+terracotta, no dark+neon, no rounded-card kit, no eyebrow labels,
no middot metadata rows, no arrow CTAs, no scattered scroll animations.
The ledger grammar (hairlines, tabular mono rows, pine accents) is
derived from the product's data shape — the core platform renders
exactly that data (attendance taps, recycling deposits, points), so the
grammar maps 1:1 onto dashboards: tables become ledger rows, stats
become ruled spec blocks, forms become contact-style panels.

## Consequences
- A future agent MUST NOT revert to rounded-card kits, shadows, system-font
  stacks, or a different palette while either repo still uses this system.
- Whoever changes the palette/type on one side MUST update the other
  (value-matching, no mechanical sync).
- Fonts are duplicated per repo (~280KB woff2) — accepted cost of no
  shared package.
- `resources/css/app.css` (dead Tailwind scaffold from Laravel install,
  never built — no build step exists) remains untouched dead code; its
  removal is recorded as follow-up work, not done in this task
  (minimize unrelated changes).

## Status
ACTIVE

## Supersedes
None (TASK-002 established "plain CSS, no build step"; this ADR keeps
that approach and pins the token values on top of it).
