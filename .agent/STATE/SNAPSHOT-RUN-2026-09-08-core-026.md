# SNAPSHOT-RUN-2026-09-08-core-026

- **HEAD**: main at the RUN-026 fix + docs commits (pushed; see
  RUN-026 for the remote verdict), tree clean; parents 5870b07 ←
  6388153 ← 674328d (TASK-027 merge).
- **Remote CI history**: #80/#81 (8501089, 6388153) success; #82
  (5870b07) failed Windows smoke — 1 flaky clock-boundary assertion
  in CardPairingTest, fixed by RUN-026 with ±1 s tolerance
  (assertEqualsWithDelta, TapEventTest convention).
- **Suite**: 313 passed / 1 by-design skip (4,746 assertions local;
  assertion totals are environment-sensitive — test counts are the
  stable metric) · `./run ci` 3/3 stages green · e2e 33/33 ·
  CardPairingTest ×3 = 14/14.
- **Sibling repos**: B2B-Firmware main b88f77e (TASK-010 LED fix;
  no-CI repo — local native 93/93 + esp32cam build stand as gates);
  ESP32-CAM-CV main 5c62dc1 (reference repo, no CI, no work needed).
- **Secrets hygiene**: PAT only via per-command credential helper;
  gitleaks job green on every pushed run including #82.
- **Durable traps added**: clock-boundary assertSame flake family;
  "observe the run triggered by your own record commit before
  declaring CI green".
- **Next (owner)**: unchanged — DEEPSEEK_API_KEY (live NL smoke
  still skipped), OBS-015 residual triage (rate limiting first),
  gap-ledger remainder (R1 / G1), camera-station hardware bench.
