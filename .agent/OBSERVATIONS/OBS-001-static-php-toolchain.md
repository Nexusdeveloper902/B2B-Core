# OBS-001

## Date
2026-09-02

## Observation
The build environment (this run's sandbox) has no system PHP/Composer and no
root access. A static PHP 8.4.23 build (static-php-cli, dl.static-php.dev,
"common" bundle) + Composer 2.10.3 installed under /home/z/my-project/tools
provided everything Laravel 13 needs (sqlite3, pdo_sqlite, mbstring, curl, zip,
dom, fileinfo, gd, openssl, pcntl for `artisan serve`).

## Evidence
`php artisan migrate --seed`, full PHPUnit suite, `php artisan serve` +
real-HTTP curl flows, and Pint all ran on the static binary.

## Impact
Future agents in similar sandboxes can skip apt/sudo entirely; note that
`php artisan serve` requires the `--no-reload` flag to pass environment
variables (e.g. a throwaway `DB_DATABASE`) through to the built-in server —
without it, the child re-reads `.env` and ignores your exported vars.

## Related Task
TASK-002-core-platform-mvp

## Status
CONFIRMED
