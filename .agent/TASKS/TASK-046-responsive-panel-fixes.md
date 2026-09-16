# TASK-046 — responsive panel fixes

## Ask
Owner: "there are parts in the interface that, depending on the panel
you are in, are not being fully responsive — fix those."

No panel named, so the panels were MEASURED rather than guessed at.

## Method
All 17 panels (every role: admin desks, teacher, kitchen, parent,
student, login) were server-rendered to standalone HTML, loaded in a
real browser inside a fixed-width iframe (Chrome's headless window has a
minimum width, so `--window-size` cannot produce a true 320px layout
viewport), and probed for:

- horizontal PAGE scroll (`documentElement.scrollWidth > clientWidth`),
- any element escaping the viewport box,
- any element whose content overflows it,

with legitimate `overflow-x: auto` strips and `overflow: hidden` clips
excluded (the `.metric-hero` decorative blob is a deliberate, clipped
bleed and was the first false positive to filter out).

Swept 15 widths, 320 → 1600px. Findings fixed, then re-swept to green.

## Findings and fixes

**Root cause of every phone-width failure: `min-width: auto`.** Flex and
grid items are never narrower than their content by default, so nested
`overflow-x: auto` scrollers could not shrink and the PAGE scrolled
sideways instead of the strip. Fixed once for every layout container.

| Panel | Failure | Fix |
|---|---|---|
| `/admin/pairing` | page scroll 516>375 up to 480px | shrinkable items + the two `.filterbar` bugs below |
| `/parent/students/{id}` | page scroll at 320px | same |
| `/admin/reports/pae` | page scroll ≤375px, `.report-cols` overflow at 414px | shrinkable grid items |
| `/admin/reports/pae/student/{id}` | page scroll at 621–699px | `.ledger-wrap` bleed scoped to `.panel` |
| every admin desk | topbar tools (logout, EN/ES) pushed off-screen 941–1400px | `.topnav` becomes the shrinking, scrolling element |

Two bugs were width-independent:

- `.filterbar` turned into a column but kept `flex-wrap: wrap` — a
  multi-COLUMN flex container, whose line takes the cross size of the
  widest item. The pill row therefore sized the page.
- `.filterbar .searchbox` kept `flex: 1 1 320px` in that column, and
  `flex-basis` sizes the MAIN axis: the pairing desk had a **320px-tall**
  search field with a canyon of white space above it.

And two layout facts were simply missing:

- `.stat-row` (both PAE desks) had **no CSS rule at all** — its KPI tiles
  stacked full-width at every viewport, desktop included. Now an
  `auto-fit` grid.
- Below 620px every `.btn` goes full-width; right for a form action,
  wrong for the PAE export toolbar (five short links = most of a phone
  screen). `.report-exports .btn` and `.btn-small` keep their width.

TASK-045 follow-up: the "branded for <school>" chip now hides below
1400px (was 1180px) so it can never be the reason the topbar overflows.

## Gates
- 15 widths × 17 panels re-measured: **clean everywhere**.
- `./run ci`: 3/3 green — 638 tests (635 pass, 3 by-design skips),
  e2e 44/44, quality green.
- New pins: `tests/Feature/Web/ResponsiveLayoutTest.php` (7).
- Docs: `docs/FRONTEND.md`/`.es.md` §1b.

## Deliberately NOT done
- Breakpoints unchanged (1160 / 940 / 620) — the failures were
  structural, not breakpoint placement.
- The `x-stat` prop/slot bug on both PAE desks (values never render) and
  the missing `app.parent_view` translation key were FOUND but not
  fixed: content defects, not responsiveness, and out of this ask.
