# ADR-003

## Date
2026-09-02

## Context
Phase C needs material classification before awarding recycling points, but no
model, training data, or camera hardware exists. The gap must be closed by a
real, verified classification step — not by awarding points blindly — while
keeping the future model swap free of controller/route changes.

## Decision
Define `App\Contracts\MaterialClassifier` (one method:
`classify(image): {material_class, confidence}`) and inject it into the
classification flow via the service container. Ship three drivers:
`StubClassifier` (default; deterministic per image hash), `LocalModelClassifier`
(HTTP call to a local inference service), `GeminiClassifier` (optional cloud
fallback). Driver selection is `RECYCLING_CLASSIFIER_DRIVER` in `.env`. The
controller depends only on the contract. (Follows the task text: stub is
sanctioned, documented, not hidden.)

## Alternatives Considered
- Hardcode a model call in the controller — rejected: violates the swappability
  requirement and makes CI flaky/model-dependent.
- Skip verification until a model exists — rejected: the verification step is
  the differentiator vs. the competitor reference project.
- Train/integrate a real CV model — explicitly out of scope for this task.

## Reasoning
The interface isolates the only volatile part (how classification happens) so
points, ledger, dashboards, NL query, and CI can be built and exercised NOW. When
a real model exists, it becomes a driver implementation + a `.env` change.

## Consequences
- Tests bind a fake classifier for deterministic assertions (the documented
  test seam; note the container resolves the controller once per route, so
  mutable fakes bound before the first request are the reliable pattern).
- Driver failures surface as 503 with zero points touched — devices retry.

## Status
ACTIVE

## Supersedes
none
