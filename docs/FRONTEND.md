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

The two TASK-026 pages and the two TASK-027 desks are views over data
that already existed plus new admin write endpoints (see §4). Nav stays
role-scoped exactly as before — students see the student hub, staff see
staff pages.

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
