# Pulse Brand & Product Identity

> **Read this in:** [Español](BRAND.es.md)
>
> Established by the productization pass (2026-09-12). The product is
> **Pulse** — everywhere a person, a device, or a search engine can see
> a name. This document is the identity contract: what the brand assets
> are, where they live, and the rules for using them.

## 1. Name

The product name is **Pulse** (EN and ES alike — the name is not
translated). The domain terms are NOT brand: a *presence event*, the
`presence_events` table, `config/presence.php` and the firmware's
internal `Presence` namespace describe the event model and stay as-is.

- Browser titles: `<page> — Pulse` (the shell's `<title>` template).
- `config/app.php` default: `Pulse` (Core) / `Pulse Marketplace`
  (storefront); `.env.example` matches.
- Demo accounts remain `@presence.test` — an email domain (seed-data
  infrastructure), deliberately not renamed this pass.

## 2. Logo assets (`public/brand/`)

All assets derive from the **real brand suite** (`B2B-Logo-Suite/` in
the workspace — the P mark with the gold orbit and the tagline
"Tecnología para problemas reales"). No generic or invented mark is
used anywhere.

| Asset | Source | Use |
|---|---|---|
| `mark.png` | Mark.png (cream knocked out to transparent) | master transparent mark, ink + gold |
| `mark-96.png` | ↳ thumbnail | topbar / footer / login band (`width="42" height="30"`) |
| `mark-cream-96.png` | ↳ alpha remap to cream | the mark on dark surfaces (storefront footer) |
| `favicon-16/32/48.png`, `favicon.ico` | mark on `#E8EDDF` tile | browser tabs |
| `apple-touch-icon.png` (180) | mark on `#E8EDDF` tile | iOS home screen |
| `icon-192/512.png`, `icon-maskable-*.png` | mark on `#E8EDDF` tile (maskable: content in the 80 % safe zone) | PWA manifest |
| `lockup-cream-480.png` | Horizontal.png (tight crop) | contexts that need the full lockup |
| `og-image.png` (1200×630) | lockup on brand cream | social previews (`og:image`) |

Rules:

- There is intentionally **no SVG favicon**: the raster mark is the
  authentic asset; a hand-traced vector would risk distorting it.
- The wordmark in the shell is the typed name in the display font next
  to the real mark — the same lockup grammar the suite's Horizontal
  asset uses. Never render the old placeholder tile (`wordmark-tap`)
  again; it is gone and a test pins its absence.

## 3. Palette → semantic tokens

The five established Pulse values anchor `public/css/tokens.css`
(ADR-047). The token names are the contract; the values are the brand:

| Brand value | Role |
|---|---|
| `#E8EDDF` cream | `--background` / `--surface` — the ground |
| `#CFDBD5` sage | `--surface-variant`, strongest container step, hairline family |
| `#242423` ink | `--primary` (action color), `--text`, `--on-gold` |
| `#333533` graphite | `--primary-container`, hover step (`--surface-tint`) |
| `#F5CB5C` gold | `--tertiary-fixed` — points/eco accent, **and** `--on-primary`: the mark's gold-on-ink chip language is the button/active-nav grammar |

Error red (`#ba1a1a` family) stays independent of the brand set so
status never collapses into decoration. Contrast floor: gold on ink
≈ 9.7:1, ink on cream ≈ 12.6:1 — every pinned pair passes WCAG AA.

## 3b. School branding (the brand layer)

> TASK-045 · ADR-064 (organizations) · ADR-065 (branding layer)

Pulse can render as itself, or as **Pulse branded for one
institution** — same layout, same components, same spacing, same
behavior. It is a *layer over* the design system above, never a second
design system.

**The chain:** `authenticated account → school → branding profile`.

| Piece | Where |
|---|---|
| The organization | `schools` row (`name`, `slug`, `brand_key`) |
| The account's membership | `users.school_id` |
| The branding values | `config/branding.php`, keyed by `brand_key` |
| The resolved profile | `App\Support\Branding\Brand`, resolved by `BrandResolver` |
| What views consume | `$brand` (shared with every view by `AppServiceProvider`) |

No controller, service or Blade template ever compares a school name to
a string. Views ask `$brand->logoSrc()`, `$brand->name()`,
`$brand->isDefault()`, so adding a school never touches markup.

**Fallbacks are the floor, not an error path.** An account with no
school, a school with no `brand_key`, a `brand_key` whose profile has
not shipped yet, and a guest with no device memory all resolve to stock
Pulse.

### The six brand-layer tokens

`public/css/tokens.css` opens with a brand layer; the entire
primary/action family is *derived* from it:

```css
--brand-primary            /* the action color            → --primary, --tertiary */
--brand-primary-hover      /* hover/pressed step          → --primary-container, --surface-tint */
--brand-primary-contrast   /* the label ON the action color → --on-primary */
--brand-primary-muted      /* light brand tint            → --primary-fixed */
--brand-primary-muted-dim  /* dimmer tint                 → --primary-fixed-dim */
--brand-primary-on-muted   /* text on the light tint      → --on-primary-fixed */
```

A profile may move **only** these (plus `--brand-mark-height`); a test
pins that. Surfaces, type, spacing, the gold points/eco accent
(`--tertiary-fixed`) and the semantic error family stay shared, so a
brand can change the action color without being able to break contrast,
hierarchy, or the meaning of a status color.

The rule that decides which gold stayed gold: **`--tertiary-fixed` is
the data/points/eco accent (hero numerals, meter fills, present stamps,
success notices, toasts); `--on-primary` is UI chrome sitting on the
action color (button labels, active nav, chips, pills, tabs, avatars,
pagination).** For stock Pulse both resolve to `#F5CB5C`, so the
unbranded shell renders byte-identically to what shipped before.

### No flash, no layout shift

- The profile's tokens are inlined in `<head>` **after** the
  stylesheets (`<style id="brand-theme">`), so the correct palette is
  present in the first paint. Stock Pulse emits nothing at all.
- Every mark carries explicit `width`/`height`, so swapping the Pulse
  lockup for a school crest reserves the same box before the image
  loads.
- The login screen has no account to resolve from, so it wears the
  brand this device last signed in with (an encrypted `pulse_brand`
  cookie, written on login). An **authenticated** account always wins
  over that cookie, and signing in with an unbranded account clears it.

### Shipped profile: IE Concejo de Sabaneta J.M.C.B

| | |
|---|---|
| `brand_key` / slug | `ie-concejo-de-sabaneta` |
| Brand color | `#80193c` |
| Ramp (derived from the one hue) | hover `#9a1e48` · contrast `#ffffff` · muted `#f5e5eb` · muted-dim `#e8c9d4` · on-muted `#4d0f24` |
| Assets | `public/brand/schools/ie-concejo-de-sabaneta/` |

The crest is the institution's **own official artwork**
(`B2B-Logo-Suite/School_Logo.jpeg`): the flat white background was
flood-filled to transparency from the edges only (the book pages inside
the crest are white too), trimmed, and padded to a square canvas so it
can never render stretched. Nothing was redrawn or regenerated.

Contrast (verified in `tests/Feature/Web/SchoolBrandingTest.php`):
white on `#80193c` ≈ 9.9:1, white on the hover step ≈ 7.9:1, `#80193c`
on cream ≈ 8.3:1, the gold points accent on `#80193c` ≈ 6.4:1 — every
rendered pair passes WCAG AA.

### Adding the next school

1. `schools` row — `School::provision('<name>', '<slug>', '<brand_key>')`.
2. A block in `config/branding.php` under that `brand_key`.
3. Logo files under `public/brand/schools/<brand_key>/`.

No UI, route, controller or stylesheet changes.

## 4. Toast feedback system (the one acknowledgment layer)

`public/js/toast.js` + the `.toast-*` block in `app.css`. One system,
four tones:

```js
PulseToast.success('Student created.');
PulseToast.error('Unable to save.', 'The server rejected the row.');
PulseToast.warning('Some rows could not be imported.');
PulseToast.info('Pairing armed — tap a fresh card.');
```

- Stacked bottom-right (mobile: full-width bottom strip), max 4 — the
  oldest leaves first.
- Auto-dismiss: success/info 4 s, warning 6.5 s, error 8 s; hover
  pauses; manual dismiss button; `aria-live` polite (assertive for
  error/warning); reduced-motion skips the slide/fade entirely.
- **Division of labor:** the toast is the acknowledgment, the inline
  result box (`.nl-answer`) is the documentation. Detail-heavy output
  (CSV row errors, display-once API keys) stays in the box; the toast
  carries the short confirmation. Ambient realtime updates NEVER toast.
- Wired today: students desk (create / class / import / account
  provisioning + every network catch), readers desk (create / save /
  key rotation + catches), pairing desk (armed / paired / rejected /
  unpaired + catches — rejection toasts are deduplicated by UID),
  admin dashboard (NL failure, redemption), teacher desk (NL network
  failure).
- Bilingual copy lives in `lang/*/app.php` under the `toast_*` keys;
  views pass translated strings via `Js::from` (the TASK-014 rule).

## 5. Error pages

403/404/419/429/500/503 all render inside the app shell
(`resources/views/errors/*`), localized, each with a way out. 419 and
429 were added by this pass — they previously fell through to Laravel's
bare framework page.

## 6. What was deliberately NOT renamed

- `PresenceEvent`, `presence_events`, `config/presence.php`, the
  firmware `Presence` namespace — internal identifiers naming the event
  model (renaming = functional churn, zero user-visible gain).
- `@presence.test` demo emails — seed-data infrastructure.
- Historical `.agent/` records and dated audit docs — append-only
  history is never rewritten.
