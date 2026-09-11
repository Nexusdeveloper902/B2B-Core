# TASK-030 — Pulse productization pass (post-TASK-029 remainder)

## Date opened
2026-09-11

## Origin
Owner's consolidated Pulse rundown (chat, 2026-09-11): "implement these
fixes (some were done previously, others werent, check .agent state and
commits); when you are done with one, test it into oblivion before
moving to the next one." Verified against the tree + .agent memory on
branch `feature/TASK-030-pulse-productization-pass` (baseline: 332
passed / 3 skipped, suite green).

## Verified-already-DONE (audited, not re-implemented)
NL execution pipeline (mock-verified; live needs DEEPSEEK_API_KEY),
concise prompt + markdown.js rendering, analytical NL functions (code;
execute() tests missing — see C), America/Bogota wall-clock
consistency, teacher StudentScope wall (+login intended-URL fix),
teacher NL desk, PAE gate (code; rejection test missing — see C),
ENTRY/EXIT sessions (code; session test missing — see C), reader PUT
label+mode, per-card unpair GUI, capture-image door, EcoStation
realtime hub, rewards earn+spend loop, 4 WS channels + passover,
translation key parity, firmware LED + capture-endpoint integration.

## Remainder (this task, in delivery order — one item fully verified
before the next starts)
- [ ] **A. Auto-provision student accounts** (ADR-044): create/import
      mints the 1:1 login (convention email + initial password +
      forced first-login rotation + `POST …/students/{student}/account`
      backfill + desk account column). MISSING by design before.
- [ ] **B. Reader creation from GUI + API-key-once lifecycle**
      (generate on create, display once, never again). MISSING.
- [ ] **C. Oblivion tests for shipped-but-unpinned behavior**: NL
      analytical `execute()` paths, PAE rejection, ENTRY/EXIT
      sessions, intended-URL edges. Code exists; tests do not.
- [ ] **D. Realistic seeders**: multi-class roster, access/PAE events,
      EcoStation deposits+ledger, dedicated pae/entry readers —
      dashboards must not look empty after `db:seed`.
- [ ] **E. Contact/social presence**: Instagram `puls.e1681` + contact
      channels as functional links (Core footer; marketplace noted).
- [ ] **F. Translation micro-gaps**: hardcoded `PTS`, realtime.js
      string fallbacks on history/leaderboard/ecostation boots.
- [ ] **G. Deferred (recorded, not this run)**: EcoStation
      bottle-count-event CV rewrite (needs count-sensor hardware;
      backend capture/associate flows stand), NFC cloning posture
      decision, student self-redeem (R1), CV training-data plan,
      trend/anomaly dashboards beyond NL functions.

## Constraints
- Branches only; `main` untouched. PAT via per-command helper, never
  persisted or recorded.
- Full pyramid per item: focused tests → suite → quality → e2e (×3 on
  flake suspicion) → adversarial subagent audit for A/B.

## Acceptance
- [ ] A–F shipped on the branch with tests pinning each claim
- [ ] Suite + quality + e2e green after EVERY item (not just at end)
- [ ] RUN record + STATE snapshot appended; G items filed as follow-ups
