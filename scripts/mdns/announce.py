#!/usr/bin/env python3
"""Periodic DNS-SD announcer for the Pulse backend (`_pulse._tcp.local`).

Why this exists / Por qué existe
--------------------------------
avahi only ANSWERS queries. On some Wi-Fi networks multicast *from* other
stations never reaches this host (measured on the bench: the ESP32's own
`_pulse._tcp` query never arrived, while multicast sent *from* this host
reached the ESP32 fine). A device whose question is dropped never gets an
answer, and falls back to its compiled URL.

So this script sends the answer unasked, every second, in the direction
that works: an unsolicited mDNS response (RFC 6762 §8.3 announcement)
carrying the exact records avahi publishes — PTR, SRV, TXT
(version=1 protocol=1 api=/api) and A — on every non-loopback IPv4
interface, each with that interface's own address. A device that is
browsing receives it within its query window, whether or not its query got
through. Records use a 120 s TTL so a stale address ages out quickly.

It complements avahi (which still answers queries); it does not replace it.
Stdlib only. Started and stopped by scripts/serve.sh.

/ Anuncia el servicio cada segundo sin esperar preguntas, en la dirección
  de multidifusión que sí funciona. Complementa a avahi.

Usage: announce.py --port 8000 [--name Pulse] [--host jperez] [--interval 1]
"""
import argparse
import fcntl
import socket
import struct
import sys
import time

SERVICE = "_pulse._tcp.local"
MDNS_ADDR = ("224.0.0.251", 5353)
TXT = [b"version=1", b"protocol=1", b"api=/api"]
TTL = 120
CLASS_IN = 1
CACHE_FLUSH = 0x8000
T_A, T_PTR, T_TXT, T_SRV = 1, 12, 16, 33


def name(n):
    out = b""
    for label in n.rstrip(".").split("."):
        raw = label.encode("utf-8")
        out += bytes([len(raw)]) + raw
    return out + b"\x00"


def rr(owner, rtype, rdata, flush):
    cls = CLASS_IN | (CACHE_FLUSH if flush else 0)
    return name(owner) + struct.pack(">HHIH", rtype, cls, TTL, len(rdata)) + rdata


def packet(instance, host, port, ipv4):
    fqdn = f"{instance}.{SERVICE}"
    target = f"{host}.local"
    answers = [
        rr(SERVICE, T_PTR, name(fqdn), False),  # shared record: no cache-flush
        rr(fqdn, T_SRV, struct.pack(">HHH", 0, 0, port) + name(target), True),
        rr(fqdn, T_TXT, b"".join(bytes([len(t)]) + t for t in TXT), True),
        rr(target, T_A, socket.inet_aton(ipv4), True),
    ]
    # id 0, flags QR|AA, 0 questions, N answers (RFC 6762 §18)
    return struct.pack(">HHHHHH", 0, 0x8400, 0, len(answers), 0, 0) + b"".join(answers)


def ipv4_interfaces():
    """(ifname, address) for every up, non-loopback IPv4 interface."""
    out = []
    for _, ifname in socket.if_nameindex():
        if ifname == "lo":
            continue
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            flags = struct.unpack("H", fcntl.ioctl(s, 0x8913, struct.pack("256s", ifname.encode()[:15]))[16:18])[0]
            if not flags & 0x1 or flags & 0x8:  # IFF_UP, IFF_LOOPBACK
                continue
            addr = fcntl.ioctl(s, 0x8915, struct.pack("256s", ifname.encode()[:15]))[20:24]  # SIOCGIFADDR
            out.append((ifname, socket.inet_ntoa(addr)))
        except OSError:
            pass  # no IPv4 address on this interface
        finally:
            s.close()
    return out


def send(ipv4, payload):
    """One datagram from a short-lived socket on port 5353.

    Receivers MUST ignore responses whose source port is not 5353 (RFC 6762
    §6 — ESP-IDF does; verified on the bench), so the socket shares 5353
    with avahi. It is closed right after sending: a socket that stayed bound
    there could be handed unicast mDNS datagrams meant for avahi.
    """
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 255)  # RFC 6762 §11
    s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_LOOP, 0)  # never feed our own avahi
    s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF, socket.inet_aton(ipv4))
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
    try:
        s.bind(("0.0.0.0", 5353))
        s.sendto(payload, MDNS_ADDR)
    finally:
        s.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, required=True)
    ap.add_argument("--name", default="Pulse")
    ap.add_argument("--host", default=socket.gethostname().split(".")[0])
    ap.add_argument("--interval", type=float, default=1.0)
    ap.add_argument("--once", action="store_true", help="one round, then exit (diagnostics)")
    args = ap.parse_args()

    last = None
    while True:
        ifaces = ipv4_interfaces()
        if ifaces != last:  # network changed (hotspot joined, DHCP renewal): follow it
            print(f"announcing {args.name}.{SERVICE}:{args.port} on "
                  + (", ".join(f"{i}={a}" for i, a in ifaces) or "(no IPv4 interface)"), flush=True)
            last = ifaces
        for ifname, addr in ifaces:
            try:
                send(addr, packet(args.name, args.host, args.port, addr))
            except OSError as e:
                print(f"send on {ifname} failed: {e}", file=sys.stderr, flush=True)
        if args.once:
            return
        time.sleep(args.interval)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
