# UI Passover — Controls & Spacing (2026-09-12)

> **What this is.** An owner-requested polish pass over the whole app
> UI: inconsistent spacing in many places, and text boxes + dropdown
> selectors not styled like the alerts and pop-ups. The alerts keep
> their style — the controls were brought to them. Refinement only:
> the Datum design system (TASK-026/ADR-036), every layout, every
> backend contract and every JS hook are unchanged.

Bilingual note: this file is English; `UI-PASSOVER-2026-09-12.es.md`
is the Spanish version.

---

## 1. Controls now share the alert surface (alerts untouched)

Every text box, dropdown, search box and file input renders the
`.nl-answer` / `.notice` surface language — lowest-surface fill, 1px
`--border`, 2px radius, body type — via one shared selector in
`public/css/app.css`:

- `.field` inputs (login, password rotation), dense `.bare-*` desk
  controls, `.searchbox` inputs (previously tinted fill + transparent
  border) and the CSV file input (previously its own copy of the
  surface, with a duplicated `max-width`) all ride the same
  background / border / radius / hover / focus rules.
- Sizes stay role-based on purpose: 44px `.field` controls vs 38px
  dense desk controls (same idea as `.btn` vs `.btn-small`). Only
  the surface was unified, not the size scale.
- Dropdowns get a CSS chevron (`appearance: none` + inline-SVG
  background, `padding-right: var(--sp-xl)`): native select arrows
  differ per browser and were the visible "unstyled" half of the
  complaint. Mixed input/select rows keep aligned gutters.
- Shared details the controls never had: `::placeholder` in
  `--text-meta`, `:disabled` dimming, `[aria-invalid]` error border
  on desk inputs too, and `textarea` joins the box rule (the old
  rule styled `textarea` focus but never its box).

`.nl-answer` / `.notice` / `.field-error` tones, padding and borders
are byte-identical to before — the reference style did not move.

## 2. Spacing inconsistencies fixed

| # | Inconsistency | Fix |
|---|---|---|
| S1 | 13 layout `style="…"` attributes scattered over 7 views (filterbar overrides, icon alignment, `min-width: 0`, uppercase, table-note insets, capture meta) | One rule each in `app.css` (`.filterbar--flush`, `.auth-band-icon`, `.min-w-0`, `.t-uppercase`, `.table-note--inset`, `.capture-meta`, `.metric-value--inline`); views reference classes only. The goal-meter `width: …%` stays inline — that width IS the data. |
| S2 | Students desk `<hr class="rule">` and the PAE `.check-line` label had NO styling (browser-default inset rule, unstyled checkbox) | New `.rule` (1px `--border`, `sp-md` rhythm) and `.check-line` (inline-flex, 18px `accent-color: primary` box) rules. |
| S3 | Pairing desk idle note (`<p class="live-empty">`) matched no selector — only `.live-row.live-empty` (the feed row) existed, so the note fell back to default `<p>` margins | Standalone `.live-empty` rule in the feed's text grammar (mono, meta, centered, `sp-md` padding). |
| S4 | Result boxes under ledger tables (readers desk `#reader-result`, admin mode result) touched the table — form-led answers get `sp-sm` from `.tool-form`'s own bottom margin, table-led ones got nothing | `.ledger-wrap + .nl-answer { margin-top: var(--sp-sm) }` — same gap both ways. The pinned `.live-panel .nl-answer` full-bleed rule still wins inside live panels (later in the file, unchanged). |
| S5 | `.reward-grid` used `sp-lg` gaps while every other section grid (`.bento`, `.grid-2`, `.stack`) uses `sp-md` | One-token change to `sp-md`. |
| S6 | `.notices` referenced undefined `var(--sm)` (fell back silently) | Now `var(--sp-sm)` directly. |

## 2b. Follow-up fix — the pass was invisible behind browser cache

Owner report after the first delivery: "still looks the same".
Verified live (computed styles in a real browser): the new CSS was
correct on disk — the browser was serving the STALE file. Root
cause: the layout served `css/app.css` with no version string, so
normal refreshes never revalidate (heuristic caching without
revalidation on link navigation).

Fix (one file, `layouts/app.blade.php`): every head asset URL now
carries `?v=<filemtime>` (`fonts.css`, `tokens.css`, `app.css`,
`motion.js`) — each file busts only when IT changes, so this and
every future stylesheet pass show up on first load. Pinned in the
same DashboardTest (`css/app.css?v=\d+` et al. on rendered HTML).

Rendered proof (local serve + browser, admin session): versioned
URLs served (`app.css?v=1789176949`); select computes to
`appearance: none` + SVG chevron, white fill, 1px `#c6c7c1`, 2px
radius; input / answer box / search compute to the identical
surface; students-desk screenshot shows chevron selects, bordered
search, styled checkbox and `hr` separators.

Honest note: the sign-in page itself changes the least — its
`.field` controls already matched the alert surface, so only
placeholder/disabled/invalid details moved there. The visible
change lives on the desks (search tint→white+border, select
chevrons, checkbox, separators, table→answer gap).

## 3. Deliberately NOT changed

- **Native `confirm()` dialogs** (card unpair, reader-key rotation):
  they are browser chrome and cannot wear Datum styling; replacing
  them with a custom modal is a product/a11y decision (focus trap,
  keyboard handling), not a polish fix. Recorded as a follow-up.
- **Alert/pop-up visuals**: frozen by request — controls moved to
  them, never the reverse.
- **Heights**: 44px vs 38px control sizes are a size scale, not a
  mismatch (documented in §1).

## 4. Verification

| Gate | Result |
|---|---|
| `./run test` (unit + feature) | **443 passed**, 3 pre-existing skips, 0 failed (8 249 assertions) |
| `./run quality` (Pint + shell + docs parity) | **passed** |
| New pin | `DashboardTest::text_boxes_and_dropdowns_share_the_alert_surface_language` (shared-surface regex, chevron, check-line/rule/live-empty/table-gap rules, untouched answer tones, desk HTML class checks) |
| Pre-existing pins | `.live-panel .nl-answer` full-bleed grammar still asserted and passing (AdminPairingDeskTest) |

## 5. Files touched

- `public/css/app.css` — unified control surface, select chevron,
  placeholder/disabled/invalid, `.check-line`, `.rule`,
  `.live-empty`, view-extracted utilities, table→answer gap,
  reward-grid gap, notices fallback fix
- `resources/views/admin/{pairing,ecostation}.blade.php`,
  `teacher/dashboard.blade.php`, `auth/{login,password-change}.blade.php`,
  `parent/timeline.blade.php`, `student/rewards.blade.php` —
  inline styles replaced by the new classes (13 attributes removed;
  only the data-driven meter width keeps `style=`)
- `tests/Feature/Web/DashboardTest.php` — one regression pin
- `.agent/TASKS/TASK-032-ui-controls-and-spacing-passover.md`,
  `.agent/RUNS/RUN-2026-09-12-core-032.md` — agent records
