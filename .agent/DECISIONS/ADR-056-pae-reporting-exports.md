# ADR-056 — PAE reporting + PDF/CSV exports (dompdf)

## Date
2026-09-14

## Context
PAE had only dashboard counters. The spec requires general reports
(daily/monthly), per-student reports, missed meals (current + trends),
excluded attempts — with useful graphics, plus PDF and CSV downloads.
The repo had zero PDF capability and a no-build-step, no-chart-library
doctrine for the web UI.

## Decision
1. **One data layer**: `PaeReportService` (daily/monthly meals,
   mealsTrend, missedMeals + trend, flaggedAttempts with reason
   breakdown, enrollmentSummary, studentHistory, studentMealsToday).
   The report pages AND the NL function registry call the same methods,
   so an LLM answer can never disagree with a report page.
   Missed meal = present (CLASS_ATTENDANCE) + enrolled + not served,
   computed only on school days (Mon–Fri with attendance recorded).
2. **Web pages** (admin-only): `/admin/reports/pae` (SVG trend charts —
   hand-rolled, no chart library, per the house doctrine — enrollment
   summary, missed list + trend, flagged reasons, per-student entry
   point) and `/admin/reports/pae/student/{id}` (totals + a
   participation strip: one column per day, green served / amber
   flagged).
3. **PDF via dompdf 3** (the only new composer dependency; pure PHP,
   no binaries — works with the hermetic toolchain): `PaeReportPdf`
   renders inline-styled HTML (dompdf's safe subset: tables, borders,
   flat fills) — branded header (Pulse ink/gold/sage), summary tiles,
   horizontal CSS bar "charts" (robust where absolute positioning is
   fragile), zebra tables. dompdf's `stream()` echoes directly and
   bypasses Laravel's response pipeline — the service returns
   `response($dompdf->output())` with honest Content-Type headers.
4. **CSV**: streamed `fputcsv` rows (header row, UTF-8) for
   daily/monthly/missed/flagged + per-student.
5. Locale: every PDF string resolves through the session locale (EN/ES
   parity with the web reports).

## Alternatives Considered
- A JS chart library + print-to-PDF: violates the no-build doctrine and
  print output is unreliable.
- barryvdh/laravel-dompdf wrapper: framework-version coupling risk on
  Laravel 13; the direct dependency is 3 lines of integration.

## Consequences
- `composer.json` gains dompdf/dompdf (+4 transitive deps, all pure
  PHP); CI runs it on setup-php and the hermetic static PHP alike.
- Export routes are session-authed web routes (admin role wall), not
  API routes — downloads stream directly.

## Status
ACTIVE
