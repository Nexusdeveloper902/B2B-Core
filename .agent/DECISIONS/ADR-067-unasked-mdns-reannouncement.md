# ADR-067 — unasked `_pulse._tcp` re-announcement next to avahi

## Date
2026-09-16

## Context
ADR-061 publishes `_pulse._tcp` with `avahi-publish-service`. avahi only
**answers** queries. On the owner's bench Wi-Fi, the ESP32 still fell
back to its compiled URL on every boot, although `avahi-browse` showed
the service correctly. The following was measured, with the ESP32
reset over `/dev/ttyUSB0` and its boot log read over serial:

- **Host → ESP32 multicast works.** The ESP32 answered a multicast
  query sent from the host.
- **Station → host multicast is lost.** A 30 s passive listen on 5353
  caught nothing from any device, and `pulse-reader.local` did not
  resolve.
- **Power save is not the cause.** The owner disabled Wi-Fi power save,
  with no change.

The ESP32's question never reached avahi, so there was never an answer.
The firmware must not change for this (owner constraint).

## Decision
`./run serve` also starts `scripts/mdns/announce.py` (python3, stdlib
only).

- **What it sends:** every second, an **unsolicited mDNS response**
  (RFC 6762 §8.3) with the same PTR, SRV, TXT (`version=1 protocol=1
  api=/api`) and A records avahi publishes.
- **Where:** on every up, non-loopback IPv4 interface, each carrying
  that interface's own address. It re-reads interfaces every round, so
  it follows a hotspot join or a DHCP renewal.
- **Source port 5353, required.** Receivers must ignore responses from
  any other port (RFC 6762 §6). ESP-IDF does: an ephemeral source port
  was tested and did not work.
- **Short-lived socket, `SO_REUSEPORT`.** It is bound, sends once and
  closes, so it never sits in the 5353 lookup path and never takes
  unicast datagrams meant for avahi. `IP_MULTICAST_LOOP=0` keeps the
  local avahi from seeing it.
- **TTL:** 120 s, so a stale address ages out quickly.
- **Without python3:** `serve` warns and avahi keeps answering.

## Bench result
| Setup | ESP32 boots | Discovered |
|---|---|---|
| announcer off | 2 | 0 (compiled fallback) |
| ephemeral source port | 1 | 0 |
| port 5353 | 4 | 4 |

avahi-browse was unaffected while it ran.

## Alternatives rejected
- **Change the firmware** (unicast queries, longer windows): out of
  bounds per the owner.
- **Restart `avahi-publish-service` periodically to force
  announcements:** each restart sends a goodbye (TTL 0), so devices
  would drop the service.
- **Put the announcer in PHP:** the toolchain PHP has no `sockets`
  extension, so it cannot set `IP_MULTICAST_IF`/`LOOP`.
- **Fixed IP in `secrets.h`:** breaks on the demo hotspot, where the
  address is not known ahead of time.

## Consequences
- Discovery no longer depends on the network delivering station →
  host multicast, only host → station, which ordinary Wi-Fi delivers.
  The phone app's NSD browse benefits from the same announcements.
- About four small multicast packets per second per interface,
  including virtual ones such as ZeroTier (harmless, since each carries
  its own interface's address).
- **Not yet verified:** the phone-hosted hotspot (same mechanism, not
  yet run there).

## Status
ACTIVE (extends ADR-061)
