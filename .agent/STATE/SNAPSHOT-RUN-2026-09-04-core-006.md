# STATE SNAPSHOT — after RUN-2026-09-04-core-006

## Repository state

- Branch: main at a951b06 (records commit on top)
- TASK-008 commits: 92ce002, b4dc326, 42f57bd, cc0ec88, a951b06
- Working tree: clean; `core.fileMode=false` still active (OBS-009 — no
  new executable shell scripts this task; run.cmd needs no +x)
- Remote: origin/main == local main; push-triggered tip run 33816810463
  green (13/13)

## What the project can do now

- Everything from TASK-002..007 (bilingual platform, ./run suite, real
  CI 13/13, Event Ledger UI, live NL queries, self-explaining LLM
  failures + ./run llm-check).
- **Windows support as an auto-detected fallback (ADR-017)**: the same
  ./run suite runs on Windows (Git Bash) with OS-aware candidates —
  Linux ELF .tools/php never probed there; composer.bat/.cmd/.phar
  probed and (wrappers) invoked direct; venv Scripts/ layout; taskkill
  //F //T via /proc/<pid>/winpid; python3 -> python -> py resolution;
  run.cmd for cmd/PowerShell; .gitattributes line-ending contract
  (sh/run LF, cmd/bat CRLF); winget/choco/php.net guidance everywhere.
- WSL uses the normal Linux path (including the hermetic toolchain).
- ScriptSuiteTest now carries 31 tests including 10 Windows contracts
  (behavioral B2B_OS=windows simulations run on every OS).

## Ops truths (machine-verified this run)

- windows-smoke on windows-latest: setup --ci -> doctor -> quality
  (shellcheck via choco) -> test (125 passed) -> e2e 22/22 — green on
  the FIRST dispatch (run 33815966369) and again after the fix
  (33816386157).
- OBS-010: `php composer.bat --version` exits 0 printing the bat text —
  never php-validate .bat/.cmd wrappers (validate + invoke direct only).
- Native Windows curl.exe cannot read msys /tmp paths — e2e keeps its
  test image project-relative.
- macOS: supported-by-construction (unix chain), still not CI-exercised
  (now explicitly documented).

## Repo secrets

- `GEMINI_API_KEY` (Actions only): unchanged this run. Full-diff pattern
  scan = 0 matches; no credentials touched.

## Open follow-ups

- gemini vision-classifier live path still unexercised (stub/local by
  design).
- Interactions API migration deferred (OBS-008 trigger conditions).
- If a future task adds executable .sh scripts, remember OBS-009
  (`git update-index --chmod=+x`).
