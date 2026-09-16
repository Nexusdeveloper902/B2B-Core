# ADR-065 — school branding as a derived token layer

## Date
2026-09-15

## Context
`IE Concejo de Sabaneta J.M.C.B` should see Pulse in its own identity
(`#80193c` + its official crest) without becoming a different
application, and more schools must be addable later. The dashboards
already have one palette source of truth (`public/css/tokens.css`,
ADR-013/ADR-036/ADR-047) whose `--primary` family is referenced ~100
times across `app.css`. The obvious failure modes were: a second
stylesheet per school, per-component `if school === '...'`, a flash of
Pulse colors before the correct ones, and a brand color bleeding over
semantic status colors.

## Decision
1. **One chain, resolved once**: `account → school → branding profile`.
   `schools.brand_key` NAMES a profile in `config/branding.php`;
   `BrandResolver` returns a `Brand` value object; `AppServiceProvider`
   shares it with every view as `$brand`. No controller, service or
   template ever compares a school name to a string.

2. **Branding is CONFIGURATION, not per-row data.** Colors, logos and
   icon sets live in `config/branding.php`, versioned with the assets
   they reference. The `schools` row only names its profile, so an
   operator cannot drift a school into an unreadable contrast pair.

3. **A six-token brand layer, and the palette is DERIVED from it.**
   `tokens.css` opens with `--brand-primary`, `--brand-primary-hover`,
   `--brand-primary-contrast`, `--brand-primary-muted`,
   `--brand-primary-muted-dim`, `--brand-primary-on-muted` (+
   `--brand-mark-height`); `--primary`, `--on-primary`,
   `--primary-container`, `--primary-fixed`, `--primary-fixed-dim`,
   `--on-primary-fixed`, `--surface-tint`, `--tertiary` and
   `--on-tertiary` are `var()`s over them. The defaults ARE the Pulse
   values, so `app.css` needed no rewrite and the unbranded shell is
   unchanged. A profile may move ONLY `--brand-*` tokens (pinned by a
   test).

4. **Brand color is the ACTION color, not a global replacement.**
   Surfaces, type, spacing, the gold points/eco accent
   (`--tertiary-fixed`) and the semantic error family stay shared, so
   status can never collapse into decoration (the ADR-047 rule).
   The dividing line: `--tertiary-fixed` is the DATA accent (hero
   numerals, meter fills, present stamps, success notices, toasts);
   `--on-primary` is UI CHROME on the action color (button labels,
   active nav, chips, pills, tabs, avatars, pagination). 14 chrome
   occurrences moved from the gold token to `--on-primary`; for Pulse
   both resolve to `#F5CB5C`, so nothing moved visually.

5. **No flash, no layout shift.** The profile's tokens are inlined in
   `<head>` AFTER the stylesheets (`<style id="brand-theme">`), so the
   correct palette exists at first paint; default Pulse emits nothing.
   Every mark carries explicit `width`/`height`, so the crest reserves
   its box before loading.

6. **Guests get a device memory, accounts always win.** The login screen
   has no account to resolve from, so it wears the brand this device
   last signed in with (encrypted `pulse_brand` cookie, written on
   login). An authenticated account overrides it unconditionally, and
   signing in with an unbranded account CLEARS it — that is what stops
   branding from surviving into another account's session. The resolver
   memo is keyed by identity, not merely "once per object", for the same
   reason.

7. **Pulse is the floor, always.** No school, no `brand_key`, an unknown
   `brand_key` (a future school whose profile has not shipped) and a
   broken config block all resolve to stock Pulse rather than failing.

8. **The crest is the institution's own artwork**, processed, never
   redrawn: `B2B-Logo-Suite/School_Logo.jpeg` with the flat white
   background flood-filled to transparency from the EDGES only (the book
   pages inside the crest are white too), trimmed, and padded to a square
   canvas so it cannot render stretched.

## Alternatives Considered
- **A stylesheet per school** — rejected: doubles the design system and
  guarantees drift the moment `app.css` changes.
- **Storing hex values on the `schools` row** — rejected: the ability to
  save an unreadable palette is not a feature, and it separates values
  from the assets they ship with.
- **Overriding `--primary` directly, without a brand layer** — rejected:
  it loses the distinction between the action color and the gold data
  accent, and `--on-primary`/`--primary-fixed` would still need
  per-school reasoning.
- **A `data-brand` attribute + per-brand CSS blocks shipped to everyone**
  — rejected: every browser would download every school's palette.

## Consequences
- Adding a school = one `schools` row + one config block + a logo folder.
  No UI, route, controller or stylesheet change.
- `docs/BRAND.md`/`.es.md` §3b is the contract; contrast ratios for the
  shipped profile are asserted, not asserted-by-eye.
- A brand can never repaint a status color, which is the property that
  makes "add the next school" a safe operation.
