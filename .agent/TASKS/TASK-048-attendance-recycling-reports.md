# TASK-048 — attendance + recycling reporting desks, branded report PDFs, school-scoped seeds

## Ask
Give attendance and recycling the same reporting desk PAE already has
(TASK-037, ADR-056). Make every report PDF wear the generating school's
identity (ADR-065). Make the demo and pilot datasets belong to the one
supported school, so the default seeds open the branded shell.

## Delivered
- **Desks** (admin-only, same grammar as `/admin/reports/pae`):
  - **`/admin/reports/attendance`** (`AttendanceReportController`): daily
    present/late/absent with a per-class breakdown and SVG trends,
    monthly aggregates, repeat absentees, and per-student history
    (`/student/{id}`).
  - **`/admin/reports/recycling`** (`RecyclingReportController`, new
    `RecyclingReportService`): daily/monthly yields, material mix,
    points leaderboard, per-student history.
  - **Exports:** CSV and PDF for each desk and each student
    (`export/{csv|pdf}?type=daily|monthly|absentees|leaderboard`).
  - **Navigation:** both desks are linked in the admin topnav and the
    hamburger menu.
- **`AttendanceService`** gains `monthlyAttendance()` and
  `studentAttendanceHistory()`: zero-filled days, and status from the
  first tap against the late cutoff.
- **PDFs** share a new abstract `Pdf/BrandedReportPdf` shell.
  - **Branding:** brand-primary header band and table heads, the school
    crest, and the school name in the footer.
  - **Stock Pulse** renders exactly as before.
  - **Users:** `PaeReportPdf` was refactored onto it (−145 lines), and
    the new `AttendanceReportPdf` and `RecyclingReportPdf` use it.
- **Seeders:**
  - **`DemoSeeder` and `PilotSeeder`** now provision the school
    `IE Concejo de Sabaneta J.M.C.B` (slug and brand
    `ie-concejo-de-sabaneta`) and create every row while acting as it.
    This supersedes the TASK-045 note that `./run reset` seeds no
    school.
  - **`RealisticSeeder`** adds one school-scoped administrator,
    `admin.colegio@presence.test`, which opens the branded shell. The
    system operator `admin@presence.test` stays outside every school.
- **Test fixtures:** `TestCase` gains `demoSchool()`,
  `schoolStudent()`, `schoolClass()` and `schoolReader()`, because a
  bare `create()` builds a NULL-school row the organization wall hides.
  Nine existing test files switched to them, and `SchoolAssociationTest`
  and `SchoolBrandingTest` were adjusted because the demo seed now owns a
  school.
- **Strings and docs:** EN/ES `lang` keys for both desks, README (both
  languages) dashboard table, and `docs/API.md` (both languages) under
  web-only surfaces.
- **Tests:** `AreaReportsTest` (13) covers render, the Spanish
  translation, the role wall, CSV and PDF streams, and branded PDF
  headers. `RealisticSeederOrganizationTest` now pins two admins (one
  operator outside, one school admin inside).

## Status
DONE 2026-09-16 (committed in RUN-2026-09-16-core-049).
