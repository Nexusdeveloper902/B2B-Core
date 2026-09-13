# ADR-048 — One global toast system for action acknowledgment

## Date
2026-09-12

## Context
The productization pass makes user-action feedback a hard requirement:
"toasts everywhere appropriate", one systematic style, real actions
acknowledged — while explicitly forbidding notification spam and
toast-as-page-error-replacement. Audit findings: the app had NO toast
layer (page `.notice` and inline `.nl-answer` boxes only), and every
desk's fetch `.catch` path was SILENT — a network failure reset the
button spinner and told the user nothing.

## Decision
`public/js/toast.js` exposes `window.PulseToast` (success/error/
warning/info + show) — dependency-free IIFE matching the realtime.js
conventions. CSS lives in one `.toast-*` block in app.css. Rules:

- Bottom-right stack (mobile ≤640px: full-width bottom strip), max 4,
  newest last, oldest dismissed first.
- Durations: success/info 4 s, warning 6.5 s, error 8 s; hover pauses;
  explicit dismiss button; `aria-live` polite (assertive for
  error/warning); prefers-reduced-motion removes the enter/exit
  animation entirely.
- Division of labor: the toast ACKNOWLEDGES, the inline `.nl-answer`
  box DOCUMENTS. Detail-heavy payloads (CSV row errors, display-once
  API keys, pairing status lines) stay in the boxes. Ambient realtime
  arrivals never toast.
- Wiring: students desk (create/class/import/account + ALL catches),
  readers desk (create/save/rotate + catches), pairing desk (armed/
  paired/rejected/unpaired + catches; rejection toasts deduplicated by
  `lastRejectionUid` so idempotent poll/WS replays stay silent), admin
  dashboard (NL failure + redemption), teacher desk (NL network
  failure). Student live-balance stays silent (ambient polish).
- Bilingual copy: `toast_*` lang keys (EN/ES parity test applies);
  views pass strings via `Js::from` (TASK-014 rule). The dismiss
  label rides `window.PulseToastLabels` from the layout.

## Alternatives Considered
- Toast on realtime tap/pairing frames — rejected: spam; the live feed
  IS the feedback surface.
- Toast as the carrier for import row errors — rejected: multi-line
  detail belongs in a persistent, copyable inline box.
- A Composer/npm toast library — rejected: the app is no-build by
  architecture; 120 lines of vanilla JS replace a dependency.
- Toasting successful NL answers — rejected: the answer box is the
  product; a toast would duplicate it.

## Reasoning
Perceived responsiveness requires that (a) successes are acknowledged
without hunting the page for a changed table, and (b) failures —
especially network failures, which previously vanished — are loud. One
system keeps this consistent and accessible.

## Consequences
- `PulseIdentityTest` pins toast.js on every shell page, the CSS
  contract, and PulseToast presence in all five desk scripts.
- Dismissal auto-durations mean a missed toast is gone by design —
  durable state lives in the inline boxes/tables, never only in a
  toast.
- Server-side API messages inside inline boxes render EN on desk
  fetches (no Accept-Language header) while toast copy follows the
  session locale — a pre-existing gap now visible side-by-side,
  recorded as follow-up work.

## Status
ACTIVE
