# OBS-009 — git core.fileMode=false silently strips +x from NEW scripts

Date: 2026-09-04
Discovered in: TASK-007 (run 33811411626 — 3 jobs red)

## Observation

This repo sets `core.fileMode=false` (TASK-003 legacy: the worktree
shows spurious mode noise otherwise). Side effect: when a NEW script is
added, `git add` records mode 100644 even though `chmod +x` ran before
the add — git cannot SEE the mode change to stage it, and the default
for new blobs is non-executable. Result: `scripts/llm-check.sh` shipped
non-executable; the ScriptSuiteTest executability check failed THREE CI
jobs (unit, arch-smoke, hermetic-smoke) even though the local suite was
green — because locally the file was executable on disk (fileMode=false
also makes the local run blind to the index mode).

## Fix used

```bash
git update-index --chmod=+x scripts/llm-check.sh   # stage the mode directly
git commit -m "…"
```

## Rule extracted

With `core.fileMode=false`, every NEW executable script needs an
explicit `git update-index --chmod=+x <path>` before committing —
chmod on disk is invisible to the index. The existing
ScriptSuiteTest::every_command_delegates_to_an_executable_script guard
is the net that catches this in CI; keep it authoritative over local
`./run test` results for mode questions.
