# Project: Presence Platform — Core Platform

## What this is
The real product. A student/staff identity platform built around a single NFC card
per person. The card stores only a credential ID. Independent physical readers feed
one central backend. Core framing: "the card is the identity, the platform is the
intelligence." The architecture is a presence-event model: tap → identify →
timestamp → labeled event. Three applications share this same event stream:

1. Attendance tracking (the foundational application)
2. PAE (school feeding program) — mandatory breakfast/lunch attendance tracking
3. Recycling incentive system — tap → classify → award points, with a real earn
   AND spend loop (this is the explicit differentiator against a known competitor
   project, "Ecopuntos," whose points display had no earn mechanism, no spend
   mechanism, and no verification step)

Two AI/ML components exist because they close real gaps, not for decoration:
- A computer-vision material classifier at the recycling station, triggered by a
  card tap before points are awarded (closes the verification gap)
- A natural-language query interface over the event database, using LLM
  function-calling, for live-demo value with school staff

## CRITICAL: Hardware abstraction principle
No physical hardware (ESP32, NFC readers, cameras) exists yet at the time this
project starts. EVERY hardware integration point in this backend MUST be designed
as a plain HTTP endpoint with a stable, documented JSON/multipart contract that:
(a) can be fully exercised today using Postman or automated tests with fabricated
    data standing in for real device input, and
(b) will require ZERO backend changes when real hardware is wired up later — only
    firmware/software on the device side needs to start calling the same URLs with
    the same payload shapes.
Do not design any endpoint, auth mechanism, or data flow that assumes a specific
piece of hardware exists. Treat "a reader" as "anything that can make an
authenticated HTTP POST request," whether that's Postman, a test script, or an
ESP32 in the future.

## Relationship to the marketplace app
Independent codebase (see TASK-001-marketplace-mvp in the separate marketplace
repo). No shared code, no shared database, no runtime dependency in either
direction. The marketplace links to this platform conceptually in the pitch, not
in code.

## Data
SQLite for now (`database/database.sqlite`), consistent with the project's other
app. Sufficient for demo scale (a handful of students/cards/readers, low event
volume). Migrating to a server-based DB later is a config change, not a rewrite,
because all access goes through Eloquent.

---

## RUN-2026-09-02-core-001 — appended project facts (first implementation run)

This run built the full TASK-002 MVP (Phases A–G). Repository reality updates:

- **Framework**: Laravel 13.30 (PHP 8.3+), SQLite. No npm/Vite build is required
  for the dashboards (hand-rolled CSS; the vite config remains only as skeleton
  residue and is unused).
- **Bilingual requirement (owner-supplied, supersedes any monolingual reading of
  the original task)**: the app works in English AND Spanish — dashboard UI
  (session locale switcher), device-facing API messages (Accept-Language header),
  seeder console output, README/API/LOCAL_MODEL docs, and lang-key parity tests.
- **Classifier strategy (owner-supplied)**: the platform is intended to run fully
  locally at later stages, including the classification model. Implemented as a
  swappable `App\Contracts\MaterialClassifier` with three drivers: `stub`
  (default), `local` (HTTP contract for a local inference service — see
  docs/LOCAL_MODEL.md), `gemini` (optional cloud fallback). Swapping is a .env
  change only.
- **LLM provider**: the owner supplied a Gemini API key (free tier, flash models
  only, light usage) — this replaces the Azure OpenAI assumption in the original
  task text (ADR-006). Live end-to-end NL testing was BLOCKED from this build
  environment by a Google geo-restriction on the sandbox's network location; the
  endpoint honestly reports 503 blocked states and works wherever the Gemini API
  is reachable (the owner's local environment).
- **Testing**: 88 automated tests (unit / feature-integration / E2E suites) +
  a real-HTTP e2e script (`scripts/e2e.sh`, 22 bilingual checks) + GitHub
  Actions CI (lint, matrix unit, integration, e2e, http-e2e, gitleaks, optional
  live-LLM smoke).
