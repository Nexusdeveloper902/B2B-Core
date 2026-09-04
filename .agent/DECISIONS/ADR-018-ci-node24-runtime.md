# ADR-018 — CI actions run on the node24 runtime (deprecated node20 removed)

## Status
ACCEPTED (2026-09-05, TASK-009)

## Context
Every action-consuming job of run 33922483187 (13/13 success on main)
carried the same check-run annotation: *"Node.js 20 is deprecated. The
following actions target Node.js 20 but are being forced to run on
Node.js 24: actions/checkout@v4, actions/cache@v4,
gitleaks/gitleaks-action@v2."* Job conclusions stay `success` — the rot
is invisible in status and only shows via the check-runs annotations
API (the OBS-010 "green logs deserve forensics" lesson, one level up).

GitHub's timeline (2025-09-19 changelog, corroborated by the
gitleaks-action v3 notes): the runner default flipped to Node 24 on
2026-06-02 (node20 actions force-run with warnings since), and **Node
20 is removed from hosted runners on 2026-09-16** — after which every
`actions/checkout@v4` step hard-fails. checkout is the first step of 10
of 13 jobs: the pipeline would go fully red without a single repo
change.

## Decision
**Bump to the CURRENT major of each action, all at once, and verify
via the annotations API — not just green conclusions.**

| Pin | New | Uses | Verified `runs.using` |
|---|---|---|---|
| `actions/checkout@v4` | **v7** | 10 | v4 = node20, v7 = node24 |
| `actions/cache@v4` | **v6** | 2 | v4 (incl. 4.2.4) = node20, v6 = node24 |
| `gitleaks/gitleaks-action@v2` | **v3** | 1 | v2 = node20, v3 = node24 |
| `shivammathur/setup-php@v2` | unchanged | 7 | already node24 (absent from warnings) |

Why the *current* majors rather than the oldest node24-capable ones:
the checkout backport lines (v5.1.0/v6.1.0) already carry the same
2026-06-18 breaking change (`allow-unsafe-pr-checkout`), so staying on
an old major buys no stability — only a shorter runway. Our usage
touches none of the documented breaking surfaces: plain `pull_request`
trigger (never `pull_request_target`), default ref checkout, standard
cache inputs (path/key/restore-keys), gitleaks "no changes to inputs,
outputs, or behavior".

arch-smoke and hermetic-smoke clone with plain `git` (node-free by
design, ADR-010) — immune to this rot class, deliberately untouched.

## Consequences
- The deprecation annotations are gone: acceptance for this change was
  "13/13 green AND 0 annotations on every check-run of the run"
  (verified on branch dispatch 33922978231 and main tip push
  33923747128).
- The CI is now deadline-proof past 2026-09-16.
- New rule of thumb recorded as OBS-011: when a platform layer is
  scheduled for removal, the migration lands BEFORE the deadline, and
  "green" is proven at the annotation level, not just the conclusion.
- `shivammathur/setup-php@v2` remains on its own release cadence; if
  it ever appears in a node deprecation warning again, bump it the same
  way (it is the only remaining third-party action with a v2 major).
