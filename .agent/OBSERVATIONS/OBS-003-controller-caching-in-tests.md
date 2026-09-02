# OBS-003

## Date
2026-09-02

## Observation
In Laravel feature tests, a controller (and its constructor-injected service
graph) is resolved ONCE per Route object and cached on it for the remainder of
the test's app lifetime. Consequences for test seams:
- Binding a fake via `swap()` BEFORE the first request works.
- Swapping a NEW fake instance AFTER requests have run does NOT affect that
  route's already-cached controller.
- Mutating the ORIGINAL bound fake instance (public properties) DOES affect
  subsequent requests.

## Evidence
Debug run: call1 → fake A used; swap to new fake B; call2 → still fake A;
swap back to mutated fake A (material changed); call3 → mutated value used.
(Recorded in the run ledger; reproducible with any controller DI.)

## Impact
Tests that need to vary classifier (or similar injected service) behavior
mid-journey must bind ONE mutable fake before the first request (the pattern
used in tests/E2E/FullJourneyTest.php). ADR-003 documents this seam.

## Related Task
TASK-002-core-platform-mvp

## Status
CONFIRMED
