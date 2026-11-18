import tempfile
from pathlib import Path

from agent.buffer.store import EventBuffer
from agent.discovery.ws_discovery import parse_probe_match
from agent.health.power import parse_upsc


def test_buffer_store_and_forward():
    with tempfile.TemporaryDirectory() as d:
        b = EventBuffer(Path(d) / "e.db", max_rows=3)
        for i in range(5):
            b.put({"event_id": f"e{i}", "raw_type": "VMD" if i < 3 else "linedetection"})
        b.put({"event_id": "e4", "raw_type": "linedetection"})      # duplicate ignored
        assert len(b) == 3
        kept = [e["event_id"] for _, e in b.peek()]
        assert "e3" in kept and "e4" in kept                        # alarms kept, oldest motion dropped
        rows = b.peek(2)
        b.ack([i for i, _ in rows])
        assert len(b) == 1


def test_buffer_survives_reopen():
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "e.db"
        EventBuffer(p).put({"event_id": "x", "raw_type": "VMD"})
        assert len(EventBuffer(p)) == 1


def test_ws_discovery_parse():
    xml = b"""<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
      xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing">
      <s:Body><d:ProbeMatches><d:ProbeMatch>
      <a:EndpointReference><a:Address>urn:uuid:1234</a:Address></a:EndpointReference>
      <d:Scopes>onvif://www.onvif.org/name/HIKVISION onvif://www.onvif.org/hardware/DS-7608NI-K2</d:Scopes>
      <d:XAddrs>http://192.168.1.64/onvif/device_service</d:XAddrs>
      </d:ProbeMatch></d:ProbeMatches></s:Body></s:Envelope>"""
    m = parse_probe_match(xml)
    assert m["brand_guess"] == "hikvision"
    assert m["hardware"] == "DS-7608NI-K2"
    assert m["xaddrs"] == ["http://192.168.1.64/onvif/device_service"]
    assert parse_probe_match(b"garbage") is None


def test_ups_parse():
    assert parse_upsc("battery.charge: 40\nups.status: OB DISCHRG\n") == "ups_on_battery"
    assert parse_upsc("ups.status: OB LB\n") == "ups_low_battery"
    assert parse_upsc("ups.status: OL\n") == "mains_ok"
