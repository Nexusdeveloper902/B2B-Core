# OBS-010 — PHP "executes" plain cmd/bat text: never php-validate wrappers

## Date
2026-09-04 (TASK-008, windows-smoke run 33815966369)

## Observation
On the real windows-latest runner, `php composer.bat --version` **exits 0
and prints the .bat file's own text** (`@ECHO off …`) — the PHP CLI will
run ANY plain-text file as a PHP script, and cmd-syntax lines usually
parse as harmless junk echo statements. Consequence: a `.bat`/`.cmd`
candidate can FALSELY PASS a "can PHP run this phar?" validation and be
selected for php-mediated invocation (`php composer.bat install …`),
which would emit garbage instead of running Composer.

## Machine evidence
First windows-smoke log (before the fix):

```
✔ /c/tools/php/composer.bat → @ECHO off (via /c/tools/php/php)
```

After the fix (a951b06, run 33816386157):

```
✔ /c/tools/php/composer.bat → Composer version 2.10.3 2026-08-27 (direct — Windows wrapper)
```

## Rule adopted
Windows wrappers (`.bat`/`.cmd`) are validated AND invoked **directly
only** — never through the PHP binary. Applies to `resolve_composer` in
`scripts/_lib/common.sh` and the doctor candidate loop. Generalized:
php-mediated validation is only meaningful for actual PHP payloads
(phars / PHP scripts); treat any non-PHP wrapper as its own execution
mode.

## Why it mattered here
The runner resolved the extensionless composer shim first, so the bug
was latent (harmless in that run) — but on machines exposing ONLY
`composer.bat`, `composer_cmd` would have picked php-mode and every
`./run setup` would fail confusingly. Found by reading the GREEN job's
log, not by a red job: green logs of new platforms deserve the same
forensics as red ones.
