# TASK-028 — admin nav discoverability (the TASK-027 desks were orphaned)

## Date opened
2026-09-09

## Origin
Owner report, chat 2026-09-09: "I dont see the new GUI's". The
TASK-027 desks were verified pushed and CI-green — but the owner
could not find them in the running UI.

## Root cause (verified in the tree at a4d5503)
- `/admin/students` and `/admin/readers` exist: routes, controllers,
  views, tests, bilingual docs — all shipped in TASK-027.
- `resources/views/layouts/app.blade.php` admin nav (desktop AND the
  no-JS mobile menu) links ONLY: Dashboard, Pair cards, EcoStation.
  The two new desks were reachable solely by typing their URLs.
  The admin dashboard body has no desk quick-links either (only
  `parent.timeline` student links) — so no UI surface pointed at
  them at all. Classic "feature shipped, wiring forgotten": tests
  pinned each desk's OWN render, never the layout that reaches it.

## Resolution
- Both desks linked in the desktop admin nav and the mobile
  `mobilenav` menu, workflow-ordered: Dashboard → Students →
  Readers → Pair cards → EcoStation. Reuses existing bilingual keys
  `app.students_page` / `app.readers_page` (EN "Students"/"Readers",
  ES "Estudiantes"/"Lectores") — zero new keys, parity untouched.
- Role wall unchanged: links render inside the existing
  `isAdmin()` gate; teachers/students never see them.
- Regression test `the_layout_nav_links_both_desks_for_admins_only`
  (AdminDesksTest): pins both hrefs + labels for admins, and
  assertDontSee for teacher AND student dashboards.
- Bilingual docs: FRONTEND.md/.es.md page-map note now records the
  hole honestly (desks shipped URL-only; nav links them since
  TASK-028).

## Acceptance
- [x] Admin sees both links on every page (desktop + mobile nav)
- [x] Teacher/student views contain neither link
- [x] Full suite green: 314 passed / 1 by-design skip (new test
      included), quality PASS, e2e 33/33
