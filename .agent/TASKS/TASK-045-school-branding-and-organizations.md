# TASK-045 — school branding + organization scoping

## Ask
Owner brief, two halves that only work together:

1. **Branding.** Add a school-name field to the account relationship.
   When an account belongs to `IE Concejo de Sabaneta J.M.C.B`, Pulse
   renders in that school's identity — brand color `#80193c`, the real
   crest from the adjacent logo suite — across buttons, nav, cards,
   badges, focus/selected states, charts, login, footer, favicon.
   Everyone else keeps stock Pulse, untouched. One centralized decision,
   no scattered conditionals, trivially extensible to more schools, not
   a white-label SaaS.
2. **Organizations.** Anything created for an organization must STAY in
   it: creation inheritance server-side, no client-supplied org ids, and
   no cross-organization read/write/delete by changing an id — audited
   across index/show/create/update/delete/search/bulk/CSV/API/realtime/
   NL/PAE/devices. Plus: the realistic seeder becomes one coherent
   school, with exactly one system admin created separately, outside it.

## Starting point
No tenant concept existed at all — `students`/`classes`/`readers`/
`rewards`/`events` were flat and every admin saw everything. So the
branding ask could not be honored honestly without building the
organization first (ADR-064), then hanging the identity off it
(ADR-065).

## Done
**Schema** (2 migrations, additive + reversible)
- `schools` (`name`, `slug`, `brand_key`).
- `school_id` on the 8 ROOT owned tables; 6 child tables scope through
  their parent; `events` denormalized (stamped from its READER) with
  `(school_id, type, occurred_at)` + `(school_id, class_id)` indexes.

**Isolation** (ADR-064)
- `CurrentSchool` (user → device → system-wide), `SchoolOwnedScope`,
  `SchoolInheritedScope`, `BelongsToSchool`, `InheritsSchoolFromParent`.
- `OwnedByCurrentSchool` validation rule replaces 5 raw `exists:` rules
  that walked past the wall.
- `User::inCurrentSchool()` for the surfaces that enumerate accounts
  (User itself carries no global scope — guard recursion).
- Raw-builder walls: `LeaderboardService`, `RealtimeFeed`,
  `RealtimeRoster`, `RealtimeRecycling`.
- `realtime:serve`: per-connection school resolved at handshake; every
  tap/recycling/roster frame filtered; pairing payload built inside the
  connection's organization.
- System administrator = admin with `school_id IS NULL`; the ONLY
  cross-organization capability, and the only one that may pass
  `school_id` to `POST /api/v1/admin/staff`.

**Branding** (ADR-065)
- `config/branding.php`, `Brand`, `BrandResolver`, `$brand` shared with
  every view; brand layer in `tokens.css` with the primary family
  derived from it; 14 chrome-on-primary colors moved to `--on-primary`.
- Inline `<style id="brand-theme">` in `<head>` (no flash), explicit
  mark dimensions (no shift), `/manifest.webmanifest` rendered per
  brand, guest login screen wears the device's remembered brand.
- School assets processed from the institution's own
  `B2B-Logo-Suite/School_Logo.jpeg` (edge flood-fill to transparency,
  trim, square canvas) → crest/favicons/apple-touch/PWA/OG.

**Seeders**
- `RealisticSeeder` acts as the school for its whole run and creates NO
  admin; raw bulk inserts stamped explicitly.
- `SystemAdminSeeder` — exactly one operator, outside every
  organization, idempotent, refuses to create a second.
- `scripts/seed-realistic.sh` runs it separately after the dataset.

**Tests** (+53)
- `SchoolBrandingTest` (18), `OrganizationScopingTest` (19),
  `SchoolAssociationTest` (7), `RealisticSeederOrganizationTest` (9);
  `DashboardTest` token pins updated to assert the derivation.

**Docs**: `docs/BRAND.md`/`.es.md` §3b, `docs/DATABASE.md`/`.es.md` §8,
`docs/API.md`/`.es.md` (organization scoping + staff `school_id`),
`docs/SCRIPTS.md`/`.es.md` (seed-realistic), both READMEs.

## Deliberately NOT done
- No subdomain/tenant routing, no per-school settings, no org switcher
  UI, no white-label admin — the brief explicitly asks for the small
  clean abstraction, not the SaaS.
- Demo/pilot fixtures keep seeding NO school: they are the stock-Pulse
  regression baseline, and they prove the "accounts without a school
  keep working" promise on every CI run.
- `settings` stays global (system-level runtime config), not per-school.
