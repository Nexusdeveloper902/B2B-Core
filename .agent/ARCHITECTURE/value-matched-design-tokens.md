# ARCHITECTURE — Cross-app visual consistency: value-matched design tokens

## Claim
Visual consistency between the marketplace storefront (B2B-Marketplace)
and the core platform (B2B-Core) is maintained **by value-matching**:
both codebases carry the same literal design tokens (palette hex values,
font files, spacing/radius conventions, component grammar), encoded
independently in each repo's plain CSS. There is NO shared design-token
package, no npm dependency, no build pipeline, and no mechanical sync.

## Where the values live

- Marketplace: `public/css/app.css` `:root` block (direction "The Event
  Ledger", marketplace ADR-002), fonts in `public/fonts/`.
- Core platform: `public/css/tokens.css` (single source of truth for
  this app, recorded in ADR-013), consumed by `public/css/app.css`;
  font files copied 1:1 from the marketplace.

## Implications (future agents MUST know)

1. **Whoever changes one side's palette/type/spacing MUST remember to
   update the other repo manually.** There is nothing that will warn
   you — divergence is silent.
2. The constraint list travels with the tokens: no cream+terracotta,
   no near-black+neon, no rounded-card kits, no box-shadows, no eyebrow
   labels, no middot metadata rows, no arrow-suffixed CTAs, no
   scattered scroll animations (marketplace ADR-002 consequences).
3. A genuinely shared design-token package consumed by both repos was
   explicitly evaluated and deferred during TASK-005 (task's
   out-of-scope list): it is the natural next step if a third property
   joins the family, but it adds setup surface neither repo needs yet.
4. Data set in IBM Plex Mono is a *functional* statement (the product's
   artifact IS a timestamped record), not decoration — keep mono for
   literal event data (timestamps, IDs, event types, points), body sans
   everywhere else.
