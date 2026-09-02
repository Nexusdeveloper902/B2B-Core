# ADR-008

## Date
2026-09-02

## Context
The project owner requires the app (and its documentation) to work in English
AND Spanish. Laravel offers several i18n mechanisms; the choice must cover
server-rendered dashboards, JSON API messages to devices, console (seeder)
output, and repository documentation, without a JS build step.

## Decision
- **Dashboard UI**: Laravel lang files (`lang/en/*.php`, `lang/es/*.php`) with
  `__()`, a session-stored locale, a `SetWebLocale` web middleware, and a
  `GET /locale/{en|es}` switcher in the navbar. Default = `APP_LOCALE` (en).
- **Device API messages**: `SetApiLocale` middleware resolves the request
  locale from the `Accept-Language` header (es prefix → Spanish; anything else
  → English fallback).
- **Seeder console output**: intentionally bilingual (EN+ES labels in one
  output block) because the same printout serves both audiences.
- **Documentation**: parallel files per language (README.md/README.es.md,
  docs/API.md/API.es.md, docs/LOCAL_MODEL.md/LOCAL_MODEL.es.md).
- **Guard rails**: an E2E test asserts lang/en and lang/es key parity (a
  missing ES key would silently fall back to English) and DocumentationTest
  asserts the bilingual doc set exists.

## Alternatives Considered
- Two separate deployments/URLs per language — rejected: doubles deployment and
  drift risk.
- Client-side-only translation (JS i18n) — rejected: dashboards are
  server-rendered; devices need server-localized messages.
- Locale from a query param on every request — rejected: session is cleaner
  for the UI; Accept-Language is the standard for APIs.

## Reasoning
Laravel's built-in localization covers both surfaces with no new dependencies;
parity tests turn the bilingual requirement into an enforced invariant instead
of an aspiration.

## Consequences
- Every new user-facing string must land in BOTH lang files (test-enforced).
- API error messages must be added to `lang/{en,es}/api.php`.
- Docs changes must be mirrored in both languages (test checks existence, not
  content parity — human discipline for content).

## Status
ACTIVE

## Supersedes
none
