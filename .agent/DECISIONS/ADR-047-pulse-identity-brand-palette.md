# ADR-047 — Pulse identity: name, real brand assets, and brand-anchored palette

## Date
2026-09-12

## Context
The owner's productization pass (chat, 2026-09-12) names the product
**Pulse** and requires: the real Pulse logo everywhere (no generic or
AI-invented mark — the assets live in the workspace's
`B2B-Logo-Suite/`), correct favicon/app icons (the repo's
`favicon.ico` was 0 bytes and `favicon.svg` a placeholder tap mark), a
PWA manifest, and the established Pulse palette
(`#CFDBD5 #E8EDDF #F5CB5C #242423 #333533`) converted into meaningful
semantic tokens — explicitly without blindly repainting every color.
Until now the app was branded "Presence Platform" with the Datum
mockup M3 palette as source of truth (ADR-036).

## Decision
1. The product name is **Pulse** (EN and ES). Browser titles,
   `config/app.php` defaults (`Pulse` / `Pulse Marketplace`),
   `.env.example`, lang `app_name`, READMEs/API docs/Postman titles,
   script banners, and the firmware's user-visible station page all
   carry it.
2. `public/brand/` ships assets derived ONLY from the real suite:
   transparent `mark.png` (cream knocked out), `mark-96.png` for the
   shell, `mark-cream-96.png` for dark surfaces, a favicon/PWA tile set
   on a `#E8EDDF` ground, `lockup-cream-480.png`, and a 1200×630
   `og-image.png`. No SVG favicon is fabricated. The `wordmark-tap`
   placeholder tile is deleted (its absence is test-pinned).
3. tokens.css anchors the five brand values: cream = ground, sage =
   strongest container step/hairlines, ink `#242423` = action color and
   text, graphite `#333533` = container/hover step, gold `#F5CB5C` =
   the points/eco accent AND `--on-primary` — the mark's gold-on-ink
   chip language becomes the button/active-nav grammar. Error red
   stays independent. Type scale, spacing, radii, and motion are
   untouched (ADR-036 remains the source for those).
4. Deliberately NOT renamed: `PresenceEvent`/`presence_events`/
   `config/presence.php`/the firmware `Presence` namespace (internal
   event-model identifiers), `@presence.test` demo emails (seed-data
   infrastructure), historical `.agent/` records and dated audit docs
   (append-only history).

## Alternatives Considered
- Keep "Presence Platform" as the brand and treat Pulse as a codename —
  rejected: the owner's directive is explicit and consistent.
- Hand-trace an SVG favicon from the PNG — rejected: distortion risk
  on the real mark for no functional gain (ICO+PNG covers every
  browser).
- Repaint every color literal to the five brand hexes — rejected by
  the task text itself: semantic states must stay distinguishable;
  tokens carry the brand, components keep their grammar.
- Rename the `Presence` code identifiers — rejected: user-invisible,
  high-churn, violates minimal-change.

## Reasoning
Brand identity is a surface contract; internal naming is not. Anchoring
the token LAYER (not every component rule) lets the whole UI shift to
the brand values with one file change, exactly the architecture's
design.

## Consequences
- `PulseIdentityTest` pins: Pulse titles, real-mark presence, absence
  of the placeholder tile, favicon/manifest/OG links, asset suite on
  disk (non-zero), manifest name.
- DashboardTest now pins the brand hexes (updated from the mockup
  hexes); the "mockup hexes verbatim" reading of ADR-036 is superseded
  for the palette only.
- Marketplace rebrands in step (its storefront ADR-017 there); the
  station firmware page and its READMEs follow this ADR's rules.
- A future owner-side rename of `@presence.test` is a cross-cutting
  task (35+ files) — recorded, not attempted.

## Status
ACTIVE (supersedes ADR-036's palette-source clause only)
