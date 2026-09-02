# OBS-002

## Date
2026-09-02

## Observation
The Gemini API (including the models-list endpoint, so it is not
model-specific) is geo-blocked from this build environment's network location:
HTTP 400 `{"error":{"code":400,"message":"User location is not supported for
the API use.","status":"FAILED_PRECONDITION"}}` with the supplied key. The key
itself cannot be validated from here.

## Evidence
One direct `generateContent` call (NL query) + one models-list call, both
returning the same location error. The app's error path behaved exactly as
designed: structured 503 `blocked` responses.

## Impact
Live end-to-end Phase E verification must happen from the owner's own
environment (no such restriction is expected there). NOT a code defect — the
function-calling orchestration is fully covered by mocked-transport tests
(NlQueryServiceTest). Do not "fix" this by faking LLM responses.

## Related Task
TASK-002-core-platform-mvp (Phase E)

## Status
CONFIRMED
