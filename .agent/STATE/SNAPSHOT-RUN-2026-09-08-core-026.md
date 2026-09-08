# SNAPSHOT-RUN-2026-09-08-core-026

- **HEAD**: main at the RUN-026 follow-up commits (fix(test) + fix(e2e)
  + records; pushed — see RUN-026 for remote verdicts); parents
  d10476a ← 5870b07 ← 6388153 ← 674328d (TASK-027 merge).
- **Remote CI history this run**: #80/#81 (8501089, 6388153) success;
  #82 (5870b07) failed Windows smoke — CardPairingTest ±1 s
  clock-boundary flake → fix 1 (assertEqualsWithDelta);
  #83 (d10476a) Windows smoke GREEN (fix 1 verified on the real
  runner) but http-e2e failed one check — e2e.sh unordered
  `LIMIT 1` card pick drew the non-PAE demo student into the
  TASK-027 PAE gate → fix 2 (deterministic, PAE-filtered,
  coherent single selection, `ORDER BY c.id`).
- **Suite**: 313 passed / 1 by-design skip (assertion totals are
  environment/run-sensitive — 4,746–4,760 local, 2,981 on Windows —
  test COUNTS are the stable metric) · `./run ci` 3/3 stages green ·
  e2e 33/33 · CardPairingTest ×3 = 14/14 · 12/12 reseed proof draws
  Maria González (pae=1) deterministically.
- **Sibling repos**: B2B-Firmware main b88f77e (TASK-010 LED fix;
  no-CI repo — local native 93/93 + esp32cam build stand as gates);
  ESP32-CAM-CV main 5c62dc1 (reference repo, no CI, no work needed).
- **Secrets hygiene**: PAT only via per-command credential helper;
  gitleaks job green on every pushed run including the failures.
- **Durable traps added**: (1) clock-boundary assertSame flake family
  → ±1 s delta; (2) "CI green" records only cover OBSERVED commits —
  observe the run your own record commit triggers; (3) unordered
  `LIMIT 1` over random-keyed rows is planner-dependent (covering
  index → index order) → selection queries feeding test fixtures
  must be ORDER BY-deterministic AND semantically valid for EVERY
  consumer (the PAE gate changed the validity contract; the
  pre-gate-era selection never caught up).
- **Next (owner)**: unchanged — DEEPSEEK_API_KEY (live NL smoke
  still skipped), OBS-015 residual triage (rate limiting first),
  gap-ledger remainder (R1 / G1), camera-station hardware bench.
