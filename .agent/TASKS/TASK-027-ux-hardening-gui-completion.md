# TASK-027 — UX hardening, GUI completion & the teacher data wall

## Date opened
2026-09-08

## Origin
Owner's post-TASK-026 punch list (chat, 2026-09-08), merged with the
older core-functionality backlog that RUN-024 flagged. Fifteen concrete
items; every one was first VERIFIED missing (or half-built) against the
tree, then implemented, then re-verified by the full pyramid.

## Items and their resolution

| # | Owner item | Resolution |
|---|---|---|
| 1 | NL-query answers too verbose for the chat UI | System prompt now mandates concision: at most three short sentences or a compact bullet list, no preamble/filler/restating; light Markdown only (`**bold**`, `- ` bullets, `` `code` ``), headings/tables forbidden |
| 2 | NL answer renders as raw text (model returns Markdown) | `public/js/markdown.js` — escape-FIRST renderer (never trusted HTML; only `p/ul/li/strong/em/code` it creates itself; no links, no images); both dashboards render `#nl-answer` through it |
| 3 | NL queries should surface patterns the UI can't (absence totals, repeat absentees, trends) | FunctionRegistry analytical half: `get_absence_count`, `get_absent_students`, `get_late_count`, `get_attendance_trend`, `get_repeatedly_absent_students`, `get_student_time_in_school`, `find_student` (name→id resolution) — all scope-fenced, bounded args |
| 4 | Teacher-side NL query interface | `POST /api/v1/nl-query` opens to `role:admin,teacher`; the teacher dashboard gets its own NL desk (renders only when the LLM credential exists); every function execution fenced by the caller's StudentScope — the data wall is server-side, never prompt-side |
| 5 | Teachers could reach ANY student (parent timeline, redemption, school-wide feed) | `App\Services\StudentScope` — the teacher data wall (own classes; admins school-wide; student accounts self); applied at ParentViewController::timeline, RedemptionController, TeacherDashboardController SSR feed, NL functions, and the realtime WS channel (per-connection, resolved at handshake, fail closed). Leaderboard stays school-wide BY DESIGN (spec §22 public board) |
| 6 | Student login redirected to /teacher → 403 | AuthController::postLoginTarget: the stale `url.intended` survives ONLY when the freshly-authenticated role can actually open it (student↔staff walls both directions, teacher≠admin); otherwise the role's own landing page |
| 7 | Incomplete translations | 69+ new keys per language (event types, reader types, materials, card statuses, live states, both new desks, unpair family); realtime.js visible labels now localize via the server-rendered strings map; full en/es key parity pinned by tests |
| 8 | Reader management page lost in the redesign | `/admin/readers` desk + `PUT /api/v1/admin/readers/{id}` (label + active mode in ONE request); mode-only endpoint untouched (its contract stays pinned) |
| 9 | ESP32-CAM built-in LED lights unexpectedly | B2B-Firmware TASK-010: LED polarity became a config define (`PIN_STATION_LED_ACTIVE_LOW`), pin map pinned by compile-time asserts (incl. "GPIO4 is never driven"), idle patterns proven mostly-OFF, full bilingual diagnostics table (root causes: pre-fix GPIO4/RST wiring, wrong-target esp32dev image, inverted-polarity clone) |
| 10 | Echo Station latest capture image invisible | Gap E1 CLOSED: `GET /api/v1/admin/captures/{deposit}/image` (admin-only, streams from the PRIVATE disk, `Cache-Control: private`); EcoStation spotlight shows the real image, honest note when there is none |
| 11 | EcoStation page needs live updates | The hub boots `[data-realtime]`: live badge + `realtime:recycling` CustomEvents (realtime.js now forwards recycling frames); ledger rows prepend, impact metrics bump, latest-capture panel refreshes without reload |
| 12 | Card unbind in the GUI (pairing only via arm) | Gap D1 CLOSED: each roster credential is a chip with its own Unpair button → `DELETE /api/v1/admin/cards/{id}` (ADR-039 semantics); confirm dialog warns the tap history is deleted; chip repaints only from the confirmed server answer |
| 13 | Students created by hand-written SQL | `/admin/students` desk + `POST /api/v1/admin/students` (single) + `POST /api/v1/admin/students/import` (CSV roster: header-required, class-by-name, pae truthiness, per-row errors, 500-row/2MB caps). The 1:1 account is deliberately NOT auto-created (seeder pattern) |
| 14 | NL query generation worked but execution errored | Execution path hardened end-to-end: scope-fenced `execute()` returns structured error arrays (never exceptions for unknown function/args), tool results ride the documented multi-turn contract; blocked classes map to honest 503s; `find_student` resolves names the model cannot guess |
| 15 | Non-PAE students tapping a PAE reader counted as fed | TapService PAE enrollment gate: PAE-mode readers REJECT non-enrolled students with a device-facing localized 422 (`student_not_pae`), the attempt is logged (audit trail), `paeCount()` stays honest |
| 16 | Students entering school multiple times | `EventType::Departure` (EXIT) joins the spine — every entry registers (multiple rows per day are the point); `AttendanceService::studentSessions()` pairs ENTRY/EXIT on the fly → per-day sessions + time-in-school (unmatched entry = open session). No schema change |
| 17 | Security audit | OBS-015: full-surface audit (authN/authZ, API walls, DB access, student data, device comms, attack surface). Findings fixed IN this task: teacher data wall (#5), intended-URL redirect bug (#6), private-disk image door (#10), escape-first markdown (#2), CSRF on all new endpoints. Open items recorded honestly (rate limiting, plain-LAN HTTP, static device keys) |

## Verification (this run, static PHP 8.4.8 bulk build — see RUN-025)
- `./run test` → **313 passed, 1 skipped, 4,642 assertions** (was 275/1/4398 at RUN-024; +38 tests)
- `./run quality` → Pint 176 files PASS + bilingual docs checks PASS
- `./run e2e` → **33/33** (three consecutive runs; one transient 32/1 blip in a WS timing check on the first post-change run, never reproduced)
- New test surface: StudentManagementTest (11), ReaderSettingsTest (6), CardUnpairTest (6), CaptureImageTest (6), AdminDesksTest (4), pairing unpair GUI (2), teacher NL desk (1), DocumentationTest TASK-027 needles, RealtimeServerTest scope cases (in the pre-existing delta)

## Status
DONE — delivered by RUN-2026-09-08-core-025
