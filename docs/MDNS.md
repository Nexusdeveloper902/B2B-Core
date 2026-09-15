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
