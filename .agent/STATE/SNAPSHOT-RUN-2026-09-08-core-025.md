# SNAPSHOT-RUN-2026-09-08-core-025

- **HEAD**: main @ <this run's TASK-027 merge> (pushed; see RUN-2026-09-08-core-025
  for the CI run verdict). Working tree clean after commit.
- **Suite**: 313 tests / 1 by-design skip / 4,642 assertions · e2e 33/33
  (×3 consecutive) · quality PASS (Pint 176 files + docs).
- **TASK-027 delivered**: NL analytical half (7 new scope-fenced
  functions), teacher NL desk, StudentScope data wall (ADR-037) on
  timeline/redemption/feed/NL/realtime, login intended-URL fix,
  +184 lang lines, /admin/students desk (single + CSV import),
  /admin/readers desk (rename + mode in one PUT), per-card Unpair GUI
  (ADR-039), capture-image authorized door (ADR-040, E1 closed),
  EcoStation realtime hub (E5 closed), PAE enrollment gate (422 +
  audit log), ENTRY/EXIT sessions (ADR-038), escape-first markdown
  (ADR-041).
- **Security audit**: OBS-015 executed — actionable items fixed in
  TASK-027; residual ledger (rate limiting incl. login, plain-LAN
  HTTP, static device keys, no read auditing) is the honest boundary.
- **Firmware**: B2B-Firmware TASK-010 (LED polarity config + pin-map
  asserts + diagnostics docs) delivered in the same session; native
  93/93, esp32cam build SUCCESS.
- **Secrets hygiene**: session PAT via git credential helper file
  OUTSIDE the repos (per-command, never persisted in any tree);
  gitleaks green on the pushed tree.
- **Next owner decisions**: DEEPSEEK_API_KEY for live NL smoke (still
  absent); residual-risk triage from OBS-015 (rate limiting first);
  gap ledger remainder (R1 student-side redemption, G1 school
  identity flagged); hardware bench for the camera station.
- **Toolchain**: static PHP 8.4.8 BULK bundle (gd included) — the
  common bundle no longer covers the image tests (OBS-001 successor
  note in RUN-025).
