# TASK-043 — mDNS/DNS-SD advertisement (`_pulse._tcp.local`) for ESP32 discovery

## Ask
Advertise the Pulse backend on the LAN so ESP32 devices discover it
dynamically (no hard-coded IP, DHCP-rotation-proof): service
`_pulse._tcp.local`, hostname preferably `pulse.local`, port = the real
API port, useful DNS-SD TXT metadata — without embedding mDNS in Laravel
if an OS service is cleaner.

## Backend (this repo) — DONE
- `scripts/serve.sh`: `start_mdns()` publishes `Pulse _pulse._tcp
  $PORT version=1 protocol=1 api=/api` via `avahi-publish-service` as a
  background child — same lifecycle as the TASK-016 realtime server
  (starts beside `artisan serve`, withdrawn on Ctrl+C, `B2B_MDNS=0` opts
  out). Tracks `--port=`/`B2B_SERVE_PORT`; missing CLI or down
  `avahi-daemon` only warns (web still starts); loopback bind warns
  (hardware needs `--host=0.0.0.0`). No PHP/mDNS code — Laravel untouched.
- `scripts/mdns/pulse.service`: static Avahi file (same contract, fixed
  8000) for persistent bench machines.
- Contract (ADR-061): the service NAME is the backend identity; `protocol`
  TXT is the firmware gate (`1` spoken, other values rejected, absent
  accepted); realtime `:8081` deliberately NOT advertised (dashboard
  browsers only); no `pulse.local` spoofing (Avahi announces the
  machine's own `<hostname>.local` — rename to `pulse` for the literal
  name; the firmware dials resolved IP + port, never the hostname).
- Docs: `docs/MDNS.md` + `.es.md` (contract table, serve/static paths,
  hostname note, `avahi-browse` check, IP-rotation bench test);
  `docs/SCRIPTS.md` + `.es.md` serve section (parity gate still green).

## Firmware (B2B-Firmware TASK-013) — DONE (separate repo)
- `lib/PulseDiscovery` (ESPmDNS, single mDNS touchpoint) +
  host-tested `PresenceCore/PulseEndpoint.h` selection/policy; boot
  discovery bounded → compiled `API_BASE_URL` fallback; one HTTP choke
  point per image re-points on transport failure (15 s cooldown, failed
  POSTs never retried). No NVS cache, no WS migration, `pulse.local`
  never assumed. Native 118/118; all four board envs SUCCESS.

## Out of scope (recorded, not forgotten)
Link-local mDNS only — a routed/VLAN venue network needs real DNS or an
mDNS reflector. Bench rotation test is human-verified (no hardware in
agent runs).
