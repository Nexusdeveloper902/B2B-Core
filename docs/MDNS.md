# mDNS service discovery — `_pulse._tcp.local`

> También disponible en: [Español](MDNS.es.md)

ESP32 devices find the Pulse backend with DNS-SD instead of a
hard-coded LAN IP. The backend advertises itself; the firmware
(`B2B-Firmware` `lib/PulseDiscovery` + `PresenceCore/PulseEndpoint.h`)
queries at boot and re-queries after any transport failure — a
DHCP-rotated backend is picked up with no reboot, no reflash and no
config edit.

## Contract

| Item | Value |
|---|---|
| Service | `_pulse._tcp.local` |
| Port | the web/API port (`./run serve` port, default `8000`) |
| TXT `version` | `1` (advertisement format) |
| TXT `protocol` | `1` (firmware speaks `1`; a result carrying any other value is rejected, a missing TXT is accepted) |
| TXT `api` | `/api` (base path, informational) |

The service **name** is the backend identity: any answer to that query
is treated as a Pulse backend. The realtime feed port (`8081`) is
deliberately NOT advertised — only dashboard browsers use that
WebSocket; the firmware is plain-HTTP and never opens it.

## How it is published

`./run serve` publishes the advertisement with `avahi-publish-service`
(OS service, not Laravel code — Laravel itself is untouched):

```bash
./run serve --host=0.0.0.0   # LAN demo: web :8000 + realtime :8081 + _pulse._tcp
B2B_MDNS=0 ./run serve       # start WITHOUT the advertisement
```

### Unasked re-announcement (TASK-047)

avahi only **answers** queries. On some Wi-Fi networks, multicast sent
*to* the backend host never arrives. The bench measured this: the ESP32's
`_pulse._tcp` query never reached the host, while multicast *from* the
host reached the ESP32. A device whose question is lost gets no answer
and falls back to its compiled URL.

`./run serve` therefore also runs `scripts/mdns/announce.py` (python3,
stdlib only), which works in the direction that does get through:

- **What it sends:** every second, an unsolicited mDNS response (RFC 6762
  §8.3) with the same PTR, SRV, TXT and A records, on every IPv4
  interface, each carrying that interface's own address.
- **Source port:** the response comes from port **5353**, because
  receivers (ESP-IDF included) ignore responses from any other port. A
  short-lived socket is used, so avahi's own traffic is unaffected.
- **TTL:** records last 120 s, so a stale address ages out quickly.
- **Network changes:** it follows interface changes, such as joining a
  hotspot or a DHCP renewal.

A device browsing during its query window receives it whether or not its
query got through. Bench result on the multicast-lossy LAN:

| Announcer | ESP32 boots | Discovered |
|---|---|---|
| on | 4 | 4 |
| off | 2 | 0 (compiled fallback) |

Without python3, `serve` only warns, and avahi still answers.

Manual use: `python3 scripts/mdns/announce.py --port 8000`.

The publisher tracks `--port=`/`B2B_SERVE_PORT` automatically and is
withdrawn on Ctrl+C with the servers. A missing CLI or a down
`avahi-daemon` only warns — the web server still starts and devices keep
their compiled `API_BASE_URL` fallback. Binding to loopback (the
default `127.0.0.1`) also warns: LAN devices cannot reach a loopback
server, so hardware demos need `--host=0.0.0.0`.

Static alternative (persistent bench machine, fixed port only):
`scripts/mdns/pulse.service` → copy to `/etc/avahi/services/` and set
`<port>` to the serve port. Prefer `./run serve` — the static file
cannot follow a custom port.

## Hostname

Avahi advertises the machine's own `<hostname>.local` in the SRV
record. For a literal `pulse.local`, rename the bench machine
(`sudo hostnamectl set-hostname pulse`). The firmware does not need it:
it dials the resolved IP + port, never the hostname.

## Verify

```bash
avahi-browse -rt _pulse._tcp
# = eth0 IPv4 Pulse  _pulse._tcp local
#   hostname = [bench-host.local], port = [8000], txt = ["version=1" "protocol=1" "api=/api"]
```

IP-rotation bench test: note the device's `Backend:` line, change the
server's LAN IP (or restart `serve` on another interface), tap once
(expect a single `NetworkError` + a `[DISC] backend re-discovered`
line), tap again — the event logs against the new address.

## Android feedback bridge (TASK-047)

The `pulse-credential` phone app is a second client of this contract. It
browses `_pulse._tcp` with Android NSD and dials the resolved IP and
port. If mDNS returns nothing, for example because multicast fails on the
phone's own hotspot, it sweeps its private /24-sized subnets on `:8000`
and confirms Pulse via `/manifest.webmanifest`. It then signs in with a
staff account (the seeded kitchen login) and calls `GET /realtime/token`,
whose `url` already carries the discovered host. After that it opens the
realtime feed. There is still **no** need to advertise `8081`.

Each live `tap` frame's event carries `feedback`: `accepted` (served) or
`rejected` (flagged, `served=false`). The phone maps that value to a
local beep and plays it through its media output, which is the Bluetooth
speaker when one is connected. The field is derived from `served`, so the
kitchen desk and the phone always agree.

Some taps are answered but write **no** event row: a repeat classroom tap
on the same day (first tap counts) and an unknown or inactive card. Those
taps land in the append-only `tap_feedback` table instead, and
`realtime:serve` pushes each one to admin and kitchen connections as

`{"type":"feedback","feedback":{"cue":"accepted|rejected","reason":"duplicate|not_found|inactive",...}}`

Every tap therefore produces exactly one cue. The write is best-effort,
so it can never fail the device's tap.

Demo topology: the phone runs the hotspot, and the backend laptop joins
it and runs `./run serve --host=0.0.0.0`.
