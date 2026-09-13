# STATE SNAPSHOT — RUN-2026-09-12-core-039

## Overall Status
Realtime event toasts delivered (uncommitted). Full gates green.

## Completed
- Tap toasts on admin (all types) + teacher (CLASS_ATTENDANCE)
  dashboards; EcoStation deposit/points toasts; student hub "+N PTS"
  toast on own awards — all guarded (hidden tab silent, 2 s cooldown,
  stack cap) and localized (ADR-050 narrowing ADR-048).
- Suite 453/3-skip + quality PASS (one self-caught red: the PTS
  unit-honesty pin — fixed via labels.pointsUnit / points_unit key).

## In Progress
- Nothing.

## Blocked
- Push + remote CI observation pending owner PAT (RUN-036→039 all
  uncommitted).

## Known Problems
- None new.

## Important Current Facts
- ADR-050 supersedes ADR-048's "ambient realtime never toasts" clause
  for EXTERNAL events; self-caused WS replays (roster frames) still
  never toast.
- The reader bench loop the owner is running now produces: tap → live
  feed row + toast on any open dashboard within ~300 ms; PAIRING-mode
  rejections toast on the pairing desk.
- App server: owner-run instance on 0.0.0.0:8000 + realtime :8081,
  MariaDB-backed (RUN-038); my user-level pulse-mariadb service is the
  DB it talks to.
