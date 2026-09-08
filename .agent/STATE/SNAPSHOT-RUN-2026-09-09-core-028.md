# SNAPSHOT-RUN-2026-09-09-core-028

- **HEAD**: main at the TASK-029 commits (roster channel + class
  creation + realtime passover + UI consistency) + this run's records
  — push/CI verdicts in RUN-2026-09-09-core-028.
- **What changed**: ADR-042 roster channel (append-only
  `roster_updates`, admin-only frames, transactional writes from all
  four admin write surfaces); POST /api/v1/admin/classes + desk form;
  grade SELECT 0°–11°; every mutable page boots realtime (see
  docs/FRONTEND.md §3b coverage table); student-dashboard balance
  frame-shape bug fixed; design-system paginator (vendor override);
  file-row + ta-right + filterbar CSS; 11 new bilingual keys.
- **Suite**: 330 passed / 1 by-design skip (test COUNTS are the
  stable metric) · quality PASS · e2e 33/33 · real-socket roster
  broadcast proof (admin-only, hello snapshot).
- **Sibling repos**: B2B-Firmware main b88f77e, ESP32-CAM-CV 5c62dc1
  — untouched this run (no CI repos; nothing in TASK-029 touches
  them).
- **Secrets hygiene**: PAT only via per-command credential helper
  (store-file outside the repos); gitleaks green on every prior run.
- **Durable notes**: (1) a page's "live" story has THREE arrival
  paths (SSR, fetch response, WS frame) — handlers must be
  idempotent update-or-prepend, and the hello snapshot replay is the
  same code path; (2) distinct-count KPIs need per-student seen sets
  client-side, never raw ++; (3) `pagination::tailwind` resolves
  through resources/views/vendor/pagination/ (loadViewsFrom's
  prepend) — the override works without touching config.
- **Remote CI**: run 34291829693 on 3de84fa SUCCESS (13/13 jobs;
  live-LLM smoke skipped by design) — observed, loop closed.
