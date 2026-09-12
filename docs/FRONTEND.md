# Frontend Design Guide & Mockup Gap Ledger (TASK-026)

> **What this is.** On 2026-09-07 the owner supplied eight HTML mockup
> pages ("kind of like this") and one rule: **keep functionality intact,
> document the parts that need functionality that doesn't exist yet.**
> Every Blade view, the layout shell and the CSS design system were
> rebuilt to match the mockups; every backend route, contract and
> behavior is unchanged (the full suite proves it). This document is the
> design reference AND the honest gap ledger: what was built, what was
> deliberately NOT built, and what each unbuilt piece would need.

Bilingual note: this file is English; `FRONTEND.es.md` is the Spanish
version. UI strings live in `lang/{en,es}/app.php`.

---

## 1. The design system — "Datum"

| Layer | Choice |
|---|---|
| Palette | Light sage-ground Material-3 tonal set, verbatim from the mockups: `surface #f6fbed`, containers `#ffffff → #dfe4d7`, `primary #0e0f0e` (near-black), **gold `tertiary-fixed #ffdf93`** (points/eco accent), `error #ba1a1a`. Full set in `public/css/tokens.css`. |
| Typography | Epilogue (display/headline) · Manrope (body) · Space Grotesk (labels/data chips) · IBM Plex Mono (mono — value-match for the mockups' JetBrains Mono). All **self-hosted** (`public/fonts/`, latin + latin-ext; zero runtime requests to Google). |
| Type scale | Mockup values, verbatim: display 56/64 · headline-lg 40/48 · headline-md 28/36 · headline-sm 22/30 · body-lg 18/28 · body-md 15/24 · body-sm 13/20 · label-lg 14/20 · label-md 12/16 · label-sm 10/14. |
| Geometry | Sharp "architectural" corners: 2px on chips/buttons, 8px on cards, pill for dots/avatars. Fixed 64px blurred topbar, 1360px shell. |
| Icons | Material Symbols Outlined, self-hosted and **subsetted + axis-instanced** (3.97 MB → 283 KB) to the 27 icons the views use; ligature-driven (`<span class="material-symbols-outlined">eco</span>`). |
| Motion | Unchanged from "Signal": `motion.js` scroll reveals gated by `.js-motion`, CSS-owned states, full `prefers-reduced-motion` respect. |

Component inventory (all in `public/css/app.css`): topbar/pill nav,
footer + ops chip, panels (`.panel`, black top-rule variant), bento
metrics (`.metric`, `.metric-hero` black+gold), KPI strip (`.stat-*`),
filter pills + search (`.filterbar`, `.pill`, `.searchbox`), ledger
tables (`.ledger-table[data-stack]` → labeled cards under 620px), event
chips (CSS-owned tone map via `[data-event-type]`), stamps
(present=gold / late=dark-brown / absent=error), points badges, reward
cards with goal meters, podium + board rows, NFC pulse art, countdown
drain bar, login auth card, demo chips, empty states.

## 2. Page map (mockup → route)

| Mockup | Route | View |
|---|---|---|
| Sign In | `/login` | `auth/login` |
| Parent View — Timeline | `/parent/students/{id}` | `parent/timeline` |
| Teacher Dashboard | `/teacher` (+`/dashboard`) | `teacher/dashboard` |
| Admin Dashboard | `/admin` | `admin/dashboard` |
| Pair Cards — Pairing Desk | `/admin/pairing` | `admin/pairing` |
| EcoStation & Recycling Hub | `/admin/ecostation` **(new)** | `admin/ecostation` |
| Rewards & Perks Store | `/student/rewards` | `student/rewards` |
| Leaderboard & Class Standings | `/student/leaderboard` **(new)** | `student/leaderboard` |
| Students — enrollment desk | `/admin/students` **(new, TASK-027)** | `admin/students` |
| Readers — management desk | `/admin/readers` **(new, TASK-027)** | `admin/readers` |
| Set a new password | `/password/change` **(new, TASK-030-A)** | `auth/password-change` |

The two TASK-026 pages and the two TASK-027 desks are views over data
that already existed plus new admin write endpoints (see §4). Nav stays
role-scoped exactly as before — students see the student hub, staff see
staff pages. TASK-028 closes a discoverability hole the owner hit
directly: the two TASK-027 desks shipped reachable ONLY by typing their
URLs — the admin top nav (desktop and the no-JS mobile menu) now links
both, pinned by a regression test.

## 3. What was deliberately added (real data, no new backend behavior)

- **Client-side filters + search** (parent timeline event pills, pairing
  roster search, teacher ledger search): pure presentation over
  server-rendered rows — the mockups' own micro-interaction scripts,
  honest by construction.
- **Student standings page**: `LeaderboardService` data (same source as
  the API and the student desk) + class standings derived in the
  controller by grouping the board's `class_name` column.
- **EcoStation hub**: `recycling_deposits` ledger (event spine joins),
  material rate table from `config/recycling.php`, recycling readers,
  all-time impact totals.
- **Goal meters on locked reward cards**: real math (balance ÷ cost).

## 3b. TASK-029 — the realtime passover + GUI completion

The owner's verdict after living in the GUI: "everything that could
change needs websockets, this needs to be realtime" — plus class
creation and the grade-°-typing chore. Every page that renders mutable
state now boots the realtime client and updates live:

| Page | Live channel(s) | What moves without a reload |
|---|---|---|
| Admin dashboard | tap + recycling | KPI strip (distinct-student Sets for attendance/PAE), recycling totals, live feed (the readers table moved to the /admin/readers desk — TASK-035) |
| Teacher dashboard | tap | attendance rows, per-class summary chips, KPI strip (all scope-fenced server-side, as before) |
| Students desk | roster (admin frames + hello replay) | created/imported students prepend idempotently; created classes join the select |
| Readers desk | roster | label/mode repaint (never clobbers the input you're typing in) |
| Pairing desk | pairing + tap | armed window, status, history, roster card cells (unchanged from TASK-020/023/027) |
| EcoStation | recycling | ledger, metrics, latest capture (TASK-027, unchanged) |
| Parent timeline | tap | the viewed student's events prepend; pills/search stay owners of visibility |
| Student dashboard | recycling | balance (fixed latent bug: the listener read `frame.payload`; the server sends `frame.update.payload`) |
| Student history | recycling | points ledger rows prepend with the true running balance; the lede balance follows |
| Student leaderboard | recycling | board + podium re-sort (points DESC, student_id ASC — the server's rule), ranks renumber, my rank/balance follow |

The roster channel (TASK-029) is a fourth WS channel: an append-only
`roster_updates` table written inside the same transaction as the change
it describes (`student_created`, `students_imported`, `class_created`,
`reader_updated`), polled by `realtime:serve`, delivered to admin
connections only (same exposure discipline as pairing frames), with the
recent snapshot riding the admin hello (idempotent replay).

GUI completion: grade is a SELECT (`1°`–`11°`, no grade 0 — no more
typing the degree sign), the seeder ships an A/B class pair per
grade (`1° A`…`11° B`), class creation lives in the students desk
(`POST /api/v1/admin/classes`, optional homeroom teacher), the grade
picker filters the class picker to that grade's A/B with the first
match auto-selected (TASK-034 — `data-grade` tags; custom names show
all), the roster search filters live as you type (debounced
fetch-swap of tbody + pagination, same URL, no new endpoint; the
GET form stays as the no-JS fallback), the CSV
import file input got the design-system surface, and the paginator now
renders in Datum (a `pagination::tailwind` vendor override — the stock
Tailwind pager markup never matched this CSS; students desk + history
were unstyled). Scattered `style="text-align:right"` inline styles
became one `.ta-right` rule.

Honest limits (documented, not pretended): the leaderboard's
class-standings panel stays a snapshot (frames carry student-level
points, not class aggregates); the timeline's empty state doesn't grow
a live table from zero (reload renders it); the student rewards
catalog is static by nature.

## 3c. TASK-030-A — student logins: the desk's account column + the rotation page

Enrollment mints the login (ADR-044), and the desk proves it: the
roster table grew a Login column rendering the provisioned email per
row, or a one-click "Create login" backfill for pre-TASK-030 rows
(`POST /api/v1/admin/students/{student}/account`, idempotent). Live
arrivals (fetch response or roster frame) paint the same cell through
one renderer, so a row created on another admin's screen still shows
its login here. The creation result box carries the display-once
credentials (`account_notice`) — the only place the temporary password
ever appears.

`/password/change` (`auth/password-change`, same auth-card grammar as
the sign-in view) is where flagged accounts land: every other page
bounces them there until they rotate to a personal password
(current-password check, minimum 8, confirmed). Logout, the locale
switcher and the form itself stay reachable; JSON callers get a
bilingual 403 (`password_change_required`) instead of a redirect.

## 3d. TASK-030-B — readers are born here now (provisioning desk)

The readers desk grew a creation panel (name + type + initial mode):
`POST /api/v1/admin/readers` mints the row with a server-generated key
and the desk shows the key EXACTLY ONCE in the result box (the
student-login display-once rule, ADR-045) while prepending the full
editable row — fetch-first, `reader_created` frame replay no-ops. Each
row also carries a Rotate key button behind the shared Datum confirm
modal (TASK-034 — `x-confirm-modal`: panel card, alert-box message,
quiet Cancel + danger Confirm, Esc/backdrop cancel, focus return; the
pairing desk's Unpair uses the same modal, and no view ships
`window.confirm` anymore — pinned by test). Rotation bricks the
fielded reader until re-flashed; the fresh key renders once in the
same result box. The roster panel takes
an even grid split (the 4-column table scrolled its action buttons
out of the narrow panel), action cells are a flex row with gap, and
the label input flexes with its column. The table always renders
(an empty-row, never no-table) so live arrivals prepend from zero, and
save/rotate clicks are delegated so live-prepended rows behave like
server-rendered ones. No key ever reaches the HTML again: the desk
greps clean by test.

Readers live on their own desk only (TASK-035 removed the dashboard's
readers table — mode changes happen at /admin/readers, whose rows
repaint AND prepend live).

## 3e. TASK-030 (Fix 3) — presence, units, and a lived-in demo

- **Project presence**: the shared shell footer links the project's
  Instagram (`@puls.e1681`, new tab + `noopener`) in both languages.
  It is the ONLY contact channel supplied — no emails, phones or
  addresses are invented anywhere (gap P5 stays open for a real
  advisor-contact surface).
- **No hardcoded units**: the EcoStation points unit renders through
  `app.points_unit` (server rows, rate card and live JS paths alike),
  and the hub's label map went fully `Js::from` (the last
  `{{ }}`-in-JS literals are gone — translators' quotes cannot break
  the script anymore). `realtime.js` English fallbacks (`just now`,
  badge states) were audited: they only render on pages WITH a live
  list/badge, and every such boot carries the full localized strings
  map — no fallback can surface under ES.
- **Pilot dataset**: `PilotSeeder` (fresh DBs only — it refuses
  non-empty ones instead of doubling) builds three classes, 24
  students with logins and cards, five readers and ten deterministic
  school days of taps (~1k events, ~70 deposits, 2 redemptions).
  `./run reset --pilot` is the one-command human demo; the small
  `DemoSeeder` fixture stays the automated-test default.

## 4. Mockup gap ledger — needs functionality that does NOT exist yet

Everything below was **omitted or replaced honestly** (no fake data, no
dead buttons — the TASK-014 honesty floor). "Needs" = the minimum
backend surface required before the mockup element can ship truthfully.

### Global / shell furniture

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| G1 | School identity ("Northfield Academy", crest images) | No school-identity config or logo assets exist | `config('presence.school_name')` + a logo asset; the shell keeps PresencePlatform branding |
| G2 | Footer "All Systems Operational" chip | No global health probe; claiming it would be theater | An aggregated health check (DB + WS + queue) — footer shows the real env chip instead |
| G3 | Telemetry strips (NODE // CTRL-NORTH-01 · LATENCY 14MS · EPOCH) | No metrics pipeline exists | A metrics/telemetry endpoint aggregated per page render |
| G4 | "Force Telemetry Poll" / "Global Thresholds" header buttons | No such endpoints | New admin endpoints (out of redesign scope by rule) |
| G5 | Campus geofence map with reader pins (LAT/LON) | Readers carry no coordinates | `readers.lat/lng` columns + a map provider (offline maps preferred — the app is LAN-first) |
| G6 | "Cryptographically sealed / hash signature / LEDGER ARCHIVE NODE" footers | No hash chain over events exists | An event-chain hash column + verification endpoint |
| G7 | Notifications bell (rewards header) | No notification system | A notifications table + WS frame type |

### Parent timeline

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| P1 | "Live Sync Anchor" with live-updating time | WS frames are staff connections only (role-resolved, fail-closed) | Per-student realtime tokens — a privacy decision, not just code; replaced with an honest "record generated" stamp |
| P2 | Student photo + floating VERIFIED badge | No photo storage for students | Photo upload + consent workflow; monogram + real student id instead |
| P3 | `PROTOCOL #PL-2025-VANCE` tag | No protocol/case model | — (real student id used) |
| P4 | "Next Milestone: 500 PTS · 70%" progress tier | No tier/milestone model | A tiers config + ledger-derived progress (good future feature: the black points card already shows the real balance) |
| P5 | Homeroom advisor contact card (email/extension/hours) + "Request Official Attendance Audit" button | No staff contact data; no audit-request workflow | Staff profile fields + an audit-requests table/endpoint |
| P6 | "Store & Milestones" filter pill | Redemptions are ledger rows, not presence events — the filter would always be empty | Merging `reward_redemptions` + milestones into the timeline feed |
| P7 | Per-row narrative ("Morning check-in on time", "2 PET containers verified") | No narrative copy per event | Fixed copy per event type is possible; free text would need a notes field |

### Rewards store

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| R1 | "Redeem Voucher" buttons, QR/barcode modal, pass codes, 14-day expiry, "Add to Wallet", voucher vault | Redemption is **deliberately desk-only** (spec §30: staff-verified, server-side balance check) — a student-side redeem button would be a product decision, not just code | Student-scoped redeem endpoint + voucher/pass code model + expiry semantics; today's card shows the real stock/affordability and the honest "Redeem at the staff desk" note |
| R2 | Category filter pills (Cafeteria / Academic / Apparel / Special) + search + sort | `rewards.type` is free text, not a taxonomy | A category enum + seeding; the real type is shown as the card's REF |
| R3 | Location coverage / barista hours / availability slots | No reward metadata fields | Reward metadata columns (location, hours) |
| R4 | Store hours footer | No config | — |

### Leaderboard

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| L1 | Season tabs, "Historic Seasons", homeroom pizza-challenge hero with milestone + prize | No season/campaign/challenge model | Seasons + campaigns tables with point windows; class standings ARE implemented (derived from today's board) |
| L2 | Grade-level tabs | The board carries `class_name` but not grade | Expose `grade` on LeaderboardService rows (students already have it) |
| L3 | Podium styling with "Your Section · Current Lead" tags | Rank competition data exists; the section-lead margin doesn't | Lead-margin is derivable; kept static-simple in v1 |

### Teacher dashboard

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| T1 | Campus environmental metrics widget | No campus sensor data | — |
| T2 | Instructor shift briefing card | No staffing/briefing model | — |
| T3 | Class cohort switcher tabs | All classes render as panels (with client search) | A single-class route + tabs; same data |

### Pairing desk

| # | Mockup element | Status |
|---|---|---|
| D1 | Per-row "reassignment / replace card" actions | **CLOSED (TASK-027)** — every paired credential in the roster is a chip with its own **Unpair** button, backed by `DELETE /api/v1/admin/cards/{card}` (semantics mirror the bulk `cards:unpair` command; confirm dialog says the tap history is deleted) |
| D2 | Status-state reference matrix | The real states already render live in the panel | — |
| D3 | Reader terminal telemetry + firmware status | No firmware reporting (B2B-Firmware has no status channel) | A device-status endpoint + WS frame |
| D4 | Crypto ledger footer | See G6 | — |

### EcoStation

| # | Mockup element | Status |
|---|---|---|
| E1 | Capture image display ("sensor audit spotlight") | **CLOSED (TASK-027)** — the REAL image streams through the admin-authed `GET /api/v1/admin/captures/{deposit}/image` (private disk stays private); a deposit without a stored image keeps the honest private-storage note |
| E2 | Terminal telemetry / firmware snippet / compliance box | No hardware reporting | See D3 |
| E3 | Interactive terminal simulation modal | A mockup demo tool, not a feature | — |
| E4 | Per-reader material "directives" | Rates are global config today (rendered truthfully) | Per-reader rate overrides (would need a schema change) |
| E5 | Live updates (no reload) | **CLOSED (TASK-027)** — the hub boots `[data-realtime]` (live badge + `realtime:recycling` CustomEvents): ledger rows prepend, impact metrics bump, the latest-capture panel refreshes |

### Login

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| S1 | "FORGOT KEY?" link | No password reset flow | A reset-token workflow |
| S2 | SSO gateway banner, TLS/FERPA/ISO compliance badges, build number | Marketing claims with no backing — omitted per the honesty floor | Real compliance artifacts |
| S3 | "Northfield Unified Portal" naming | See G1 | — |

### Student hub nav

| # | Mockup element | Why it's not there | Needs |
|---|---|---|---|
| N1 | Student-facing EcoStation link | EcoStation is admin-only (reader operations) | A scoped student variant (product decision) |
| N2 | "Attendance & Pass" student page | Attendance data is staff-scoped; students have points/history/rewards/standings | An attendance self-view (privacy decision) |

## 5. Self-hosting & asset notes

- Fonts were downloaded ONCE from Google Fonts at redesign time and
  vendored (latin + latin-ext; ES accents covered by both). No runtime
  external requests — the LAN-first rule holds.
- The Material Symbols font is subsetted AND variable-axis-instanced to
  the exact CSS instance (`FILL 0, wght 400, GRAD 0, opsz 24`):
  3.97 MB → 283 KB. **Adding a new icon**: add the ligature span in a
  view, then re-run `scripts/subset_icons.py`'s recipe (pyftsubset
  `--text` with `--layout-features=*` keeps the rlig ligatures, then
  instancer pins the axes). If an icon renders as its ligature letters,
  the font needs re-subsetting.
- The icon CSS (`fonts.css`) sets `font-display: block` so ligature
  text never flashes as raw words.

## 6. Regression contract (what the tests pin)

- `DashboardTest`: Datum tokens (palette hexes + type scale), light
  ground / gold selection / black focus floor, stamp + chip tone map on
  Datum roles, hero KPI grammar, icon font spans, data-stack responsive
  tables, motion gate, login affordances, realtime bootstrap JSON.
- `AdminPairingDeskTest`: full-bleed tone-strip grammar (re-pinned to
  Datum tokens), `data-card-cell` live updates, every script id.
- `MockupPagesTest` (new): role guards + ledger-truth content for the
  standings and EcoStation pages.
- E2E (`./run e2e`): 33 checks over HTTP including the CSRF login and
  the TASK-025 recycling story.
