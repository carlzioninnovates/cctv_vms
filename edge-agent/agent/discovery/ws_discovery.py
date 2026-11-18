"""WS-Discovery probe for ONVIF devices on the site LAN. asyncio + stdlib only.
Many NVRs answer only on the first interface, and some (Hikvision) only when ONVIF is enabled;
the installer UI should also allow manual IP entry."""
from __future__ import annotations

import asyncio
import socket
import uuid
import xml.etree.ElementTree as ET

MCAST = ("239.255.255.250", 3702)

PROBE = """<?xml version="1.0" encoding="UTF-8"?>
<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"
 xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"
 xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"
 xmlns:dn="http://www.onvif.org/ver10/network/wsdl">
 <e:Header><w:MessageID>uuid:{mid}</w:MessageID>
 <w:To e:mustUnderstand="true">urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>
 <w:Action e:mustUnderstand="true">http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action></e:Header>
 <e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body></e:Envelope>"""


def parse_probe_match(data: bytes) -> dict | None:
    try:
        root = ET.fromstring(data)
    except ET.ParseError:
        return None
    def find(local):
        for el in root.iter():
            if isinstance(el.tag, str) and el.tag.endswith("}" + local) and el.text:
                return el.text.strip()
        return None
    xaddrs = find("XAddrs")
    if not xaddrs:
        return None
    scopes = (find("Scopes") or "").split()
    def scope(key):
        for s in scopes:
            if f"/{key}/" in s:
                return s.rsplit("/", 1)[1]
        return None
    hw, name = scope("hardware"), scope("name")
    text = f"{hw} {name} {' '.join(scopes)}".lower()
    brand = next((b for b in ("hikvision", "dahua", "uniview") if b in text), None)
    if brand is None and ("unv" in text or "ipc-" in text):
        brand = "uniview"  # heuristic; confirm on probe
    return {"xaddrs": xaddrs.split(), "hardware": hw, "name": name, "brand_guess": brand or "onvif",
            "endpoint": find("Address")}


class _Proto(asyncio.DatagramProtocol):
    def __init__(self):
        self.found: dict[str, dict] = {}

    def datagram_received(self, data, addr):
        m = parse_probe_match(data)
        if m:
            self.found[m["endpoint"] or addr[0]] = {**m, "ip": addr[0]}


async def discover(timeout: float = 3.0) -> list[dict]:
    loop = asyncio.get_running_loop()
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 2)
    sock.bind(("", 0))
    transport, proto = await loop.create_datagram_endpoint(_Proto, sock=sock)
    try:
        for _ in range(2):  # UDP: send twice
            transport.sendto(PROBE.format(mid=uuid.uuid4()).encode(), MCAST)
            await asyncio.sleep(timeout / 2)
    finally:
        transport.close()
    return list(proto.found.values())
