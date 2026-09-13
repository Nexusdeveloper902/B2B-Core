# STATE SNAPSHOT — RUN-2026-09-14-core-042

## Overall Status
PAE Full Program (TASK-037) implemented and locally verified: suite
530/1-skip, quality PASS, e2e 33/33, browser-proven kitchen realtime +
settings + reports + PDF + parent badges. Commit ready to push;
remote CI verdict pending observation.

## Completed
- Per-meal enrollment (breakfast/lunch independent; UI checkboxes +
  CSV with legacy single-column support; superset migration).
- MealServingService: weekday rule, single-window auto meal detection,
  per-meal enrollment, strictly-earlier same-day CLASS_ATTENDANCE,
  duplicate protection; flagged (served=false + reason) attempts
  auditable everywhere, counted nowhere.
- ENTRY/EXIT fully removed (ADR-054 supersedes ADR-038).
- Kitchen role + /kitchen glanceable realtime desk (green/red states,
  WS-scope without admin channels).
- Runtime settings (table + service + API + bilingual desk) driving
  windows/cutoff/pairing/accounts live.
- Reports (general + per-student, SVG charts, missed meals + trends,
  flagged attempts) with dompdf PDF + CSV exports.
- Parent timeline meal badges (served vs flagged styling).
- NL parity: 27 functions (−2 entry/exit, +7 PAE).
- EN/ES across every new surface; DocumentationTest pins.
- DemoSeeder scenario day + kitchen user + settings rows; PilotSeeder
  per-meal; e2e meal-phase with weekday/weekend branches.

## In Progress
- Nothing (push + CI observation are the delivery tail).

## Blocked
- Remote CI verdict on the pushed commit (observe post-push).
- Live-LLM smoke stays key-gated (no DEEPSEEK_API_KEY) — by design.

## Known Problems
- None new.

## Important Current Facts
- events.served (default true) + events.reason are the valid-vs-flagged
  columns; PAE_ATTEMPT is engine-written only — never a reader mode
  (validReaderModes()).
- SettingsService: dotted keys, nested-path validation via data_set,
  per-request static cache (TestCase flushes it every test).
- Kitchen realtime scope: taps yes, pairing/roster no (resolveScope).
- DemoSeeder seeds one PAST school day of scenarios; TODAY is clean by
  design (taps + e2e own today; fixtures wipe events after seeding).
- dompdf is the only new composer dependency (pure PHP).
- Test count 531 (530 pass + 1 by-design skip); e2e 33 checks.
