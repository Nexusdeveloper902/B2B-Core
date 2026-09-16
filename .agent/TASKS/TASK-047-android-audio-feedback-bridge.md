# TASK-047 — Android audible feedback bridge (realtime tap → Bluetooth beep)

Decisions: ADR-066 (`feedback` channel) and ADR-067 (unasked mDNS
re-announcement). Phone side: B2B-App TASK-002 and ADR-003.

## Ask
Live-demo feature: the `pulse-credential` phone app discovers the
backend over mDNS (same contract as the firmware), listens to the
realtime feed, and plays short local beeps through an already-connected
Bluetooth A2DP speaker. Local HCE taps also beep. No firmware changes,
no new event system or API, and the NFC UI stays unchanged.

## Backend (this repo) — DONE
- `RealtimeFeed` rows now carry `feedback` (`accepted`/`rejected`),
  derived from `served`. The same row feeds the hello snapshot and the
  live `tap` frames. It is additive, and dashboards ignore it.
- Test: `RealtimeFeedTest::rows_carry_the_feedback_cue_derived_from_served`.
- Follow-up: repeat same-day taps printed `[OK] event logged` on the
  reader but did not beep, because they write no row and so produce no
  `tap` frame. Fix:
  - new append-only `tap_feedback` table, same pattern as
    `roster_updates`;
  - `TapService` records `accepted/duplicate` and
    `rejected/not_found|inactive` there, best-effort (a failed write is
    logged, never fails the tap);
  - `realtime:serve` pushes those rows as `feedback` frames to admin and
    kitchen connections.
- Follow-up tests: `TapFeedbackTest` and
  `RealtimeServerTest::feedback_cues_reach_staff_speaker_connections_but_not_teachers`.
- Docs: `docs/MDNS.md` / `.es.md` gain the "Android feedback bridge"
  section.
- Auth: unchanged. The phone signs in through the existing web login
  with a staff account (the seeded kitchen login, ADR-054) and uses the
  existing `GET /realtime/token`.

- Follow-up 2: the ESP32 fell back instead of discovering, although avahi
  was publishing correctly.
  - **Measured on the bench:** multicast from the backend host reached
    the ESP32 (it answered a multicast query), but multicast from Wi-Fi
    stations never reached the host (a 30 s passive listen caught
    nothing, and `pulse-reader.local` did not resolve). Turning off
    Wi-Fi power save did not help.
  - **Fix:** `scripts/mdns/announce.py`, started by `serve.sh`, sends
    unsolicited announcements from port 5353 every second.
  - **An ephemeral source port does NOT work:** ESP-IDF ignores such
    responses, as tested.
  - **Result:** 4/4 discovered with the announcer, 0/2 without it. The
    firmware was not changed.

## Phone side (B2B-App/pulse-credential)
NSD discovery with a subnet-probe fallback, a web-login token session,
a hand-rolled RFC 6455 client, SoundPool on USAGE_MEDIA, and an A2DP
keep-alive. See that README's "Audible feedback bridge" section and
`docs/SOUNDS.md`.

## Status
DONE 2026-09-16 (committed in RUN-2026-09-16-core-049).

## Residual
- `tap_feedback` is never pruned. It grows by one small row per repeat
  or unknown tap, the same as the other realtime logs.
- mDNS on a phone-hosted hotspot is not hardware-verified. The subnet
  probe is the fallback.
