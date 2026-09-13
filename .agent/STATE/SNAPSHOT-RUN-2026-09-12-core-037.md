# STATE SNAPSHOT — RUN-2026-09-12-core-037

## Overall Status
Spacing/cramming pass DONE (uncommitted). All local gates green.

## Completed
- Six spacing defects fixed (headline line-height, ES topbar wraps,
  tool-form gaps, readers action cells, reward-note air, orphaned
  REWARDS link → x-stat footer slot).
- Browser-verified desktop + mobile; suite 453/3-skip; Pint clean.

## In Progress
- Nothing.

## Blocked
- Remote CI observation still pending owner PAT (RUN-036 + 037 +
  teacher diff all uncommitted).

## Known Problems
- Unchanged from RUN-036 (local .env deepseek classifier without key;
  desk fetch Accept-Language gap → TASK-036).

## Important Current Facts
- `x-stat` component now accepts an optional `footer` slot (styled
  `.stat-footer`; gold on the ink hero). Student hub REWARDS link
  lives there.
- Base headings (h1–h4) carry line-height 1.2 — do not remove; the
  lede kicker grammar depends on it.
- `.row-actions` is a single-column, full-width, nowrap button stack
  (readers desk only).
