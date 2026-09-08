# SNAPSHOT-RUN-2026-09-09-core-027

- **HEAD**: main at the TASK-028 fix (nav wiring for the two TASK-027
  desks) + this run's records — push/CI verdicts in
  RUN-2026-09-09-core-027.
- **What changed**: `layouts/app.blade.php` admin nav (desktop +
  no-JS mobile menu) now links `/admin/students` and `/admin/readers`
  (workflow order: Dashboard → Students → Readers → Pair cards →
  EcoStation), inside the existing `isAdmin()` gate; regression test
  in AdminDesksTest pins hrefs for admins AND absence for
  teacher/student; FRONTEND.md/.es.md record the discoverability hole
  honestly.
- **Why**: owner hit it directly — "I dont see the new GUI's". The
  desks were complete and CI-green but reachable only by URL; no UI
  surface linked them. Durable lesson: a shipped page needs its
  NAVIGATION pinned by a test, not just its own render.
- **Suite**: 314 passed / 1 by-design skip (test COUNTS are the
  stable metric — assertion totals are environment-sensitive) ·
  quality PASS · e2e 33/33 · no new translation keys (en/es parity
  untouched).
- **Sibling repos**: B2B-Firmware main b88f77e (no-CI repo; TASK-010
  stands), ESP32-CAM-CV main 5c62dc1 (reference repo, no CI) —
  untouched this run.
- **Secrets hygiene**: PAT only via per-command credential helper
  (store-file outside the repos); gitleaks green on prior pushed
  runs; nothing persisted this run.
- **Remote CI**: run 34288218249 on e6869ad SUCCESS (13/13 jobs;
  live-LLM smoke skipped by design) — observed, loop closed.
