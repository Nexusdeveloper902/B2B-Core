# ADR-061: backend advertises itself via mDNS/DNS-SD (`_pulse._tcp`)

## Status
Accepted (2026-09-15, TASK-043)

## Context
Every ESP32 image dials a compile-time `API_BASE_URL`, so each DHCP
rotation of the bench machine meant editing secrets, recompiling and
reflashing every device. The firmware now discovers the backend
(`B2B-Firmware` TASK-013: `PulseDiscovery` → `_pulse._tcp.local`), which
moves the problem here: something must advertise that service with the
machine's current address and the actual serve port. Options were (a) an
mDNS publisher inside Laravel, (b) a static Avahi service file, (c) a
transient `avahi-publish-service` child of `./run serve`.

## Decision
1. **`./run serve` publishes transiently (primary):** `avahi-publish-service
   Pulse _pulse._tcp $PORT version=1 protocol=1 api=/api` as a background
   child — same lifecycle pattern as the TASK-016 realtime server
   (starts beside `artisan serve`, withdrawn on Ctrl+C, graceful warn when
   the CLI/daemon is missing). Dynamic, so it tracks `--port=`; no root
   needed; Laravel itself untouched (no mDNS code in PHP).
2. **Contract:** service `_pulse._tcp.local`, port = the web/API port,
   TXT `version=1 protocol=1 api=/api`. The service name IS the backend
   identity. `protocol` is the firmware's compatibility gate (present and
   != `1` → rejected; absent → accepted). The realtime `:8081` feed is
   NOT advertised — dashboard browsers only; the firmware never opens it.
3. **Static file as fallback:** `scripts/mdns/pulse.service` (same
   contract, fixed port 8000) for persistent bench machines; documented
   in `docs/MDNS.md` (+`.es.md`). No literal `pulse.local` spoofing:
   Avahi announces the machine's own `<hostname>.local`; the firmware
   dials the resolved IP + port, never the hostname.
4. **No NVS/endpoint cache contract on this side:** ordering guarantees
   come from the firmware (fallback → discovery → cooldown-guarded
   rediscovery, failed POSTs never retried).

## Consequences
`serve` on a loopback bind warns (LAN devices need `--host=0.0.0.0`);
`B2B_MDNS=0` opts out. Bench verification is `avahi-browse -rt
_pulse._tcp` plus the IP-rotation tap test in `docs/MDNS.md`. Residual:
link-local mDNS only — a routed/VLAN venue network needs real DNS or a
reflector; out of scope for the bench.
