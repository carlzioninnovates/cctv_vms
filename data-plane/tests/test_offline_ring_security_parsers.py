from app.core import security
from app.drivers.parsers import (
    dahua_event_line,
    dahua_indexed,
    dahua_kv,
    looks_cn_region,
    xml_strip_ns,
    xml_text,
)
from app.tunnels.offline import OfflineCause as C
from app.tunnels.offline import SiteSignals, classify, reclassify_on_reconnect
from app.tunnels.ring import HashRing


# ---- offline cause ----
def test_offline_classification():
    assert classify(SiteSignals(True, True)) is None
    assert classify(SiteSignals(True, False)) is C.NVR_UNREACHABLE
    assert classify(SiteSignals(False, last_agent_signal="ups_on_battery")) is C.POWER_LOSS
    assert classify(SiteSignals(False, last_agent_signal="uplink_down")) is C.SITE_INTERNET_DOWN
    assert classify(SiteSignals(False)) is C.SITE_UNREACHABLE


def test_reclassify_on_reconnect():
    # agent up for 5 min after a 2 h outage -> it rebooted -> power
    assert reclassify_on_reconnect(C.SITE_UNREACHABLE, agent_uptime_s=300, offline_duration_s=7200) is C.POWER_LOSS
    # agent never restarted -> internet
    assert reclassify_on_reconnect(C.SITE_UNREACHABLE, agent_uptime_s=90000, offline_duration_s=7200) \
        is C.SITE_INTERNET_DOWN
    assert reclassify_on_reconnect(C.POWER_LOSS, agent_uptime_s=1, offline_duration_s=999) is C.POWER_LOSS


# ---- hash ring ----
def test_ring_is_stable_and_moves_few_keys():
    r = HashRing(["dp-1", "dp-2", "dp-3"])
    keys = [f"agent-{i}" for i in range(5000)]
    before = {k: r.node_for(k) for k in keys}
    assert before == {k: r.node_for(k) for k in keys}
    counts = {n: list(before.values()).count(n) for n in r.nodes}
    assert min(counts.values()) > 1000, counts           # roughly balanced
    r.add("dp-4")
    moved = sum(1 for k in keys if r.node_for(k) != before[k])
    assert moved < 5000 * 0.35, moved                     # ~1/4 expected
    assert all(r.node_for(k) in ("dp-4", before[k]) for k in keys)
    r.remove("dp-4")
    assert before == {k: r.node_for(k) for k in keys}


# ---- HMAC (vector produced by the PHP DataPlaneClient::sign) ----
PHP = "t=1762761600,v1=b076af70dea279c9b7c811da6e6dd7ef7cb695de79bd2f57bd5ab689e67469db"


def test_hmac_matches_php():
    body = b'{"stream":"sub"}'
    assert security.sign("s3cret", 1762761600, body) == PHP
    assert security.verify("s3cret", PHP, body, now=1762761600 + 10)


def test_hmac_rejects_tamper_replay_garbage():
    body = b'{"stream":"sub"}'
    assert not security.verify("s3cret", PHP, b'{"stream":"main"}', now=1762761600)
    assert not security.verify("s3cret", PHP, body, now=1762761600 + 301)
    assert not security.verify("wrong", PHP, body, now=1762761600)
    assert not security.verify("s3cret", "nonsense", body)
    assert not security.verify("s3cret", None, body)


# ---- vendor parsers ----
def test_dahua_config_grouping():
    kv = dahua_kv(
        "table.Encode[0].MainFormat[0].Video.Compression=H.265\r\n"
        "table.Encode[0].ExtraFormat[0].Video.Compression=H.264\r\n"
        "table.Encode[1].MainFormat[0].Video.Compression=H.264\r\n"
    )
    g = dahua_indexed(kv, "table.Encode")
    assert g[0]["MainFormat[0].Video.Compression"] == "H.265"
    assert g[0]["ExtraFormat[0].Video.Compression"] == "H.264"
    assert set(g) == {0, 1}


def test_dahua_event_line_with_json_data():
    ev = dahua_event_line('Code=CrossLineDetection;action=Start;index=2;data={"a":1;"b":2}')
    assert ev["Code"] == "CrossLineDetection" and ev["index"] == "2" and ev["data"] == '{"a":1;"b":2}'


def test_hikvision_xml_namespaces():
    x = xml_strip_ns(
        b'<?xml version="1.0"?><DeviceInfo xmlns="http://www.hikvision.com/ver20/XMLSchema">'
        b"<model>DS-7608NI-K2</model><serialNumber>ABC123</serialNumber></DeviceInfo>"
    )
    assert xml_text(x, "model") == "DS-7608NI-K2" and xml_text(x, "serialNumber") == "ABC123"


def test_cn_region_heuristic():
    assert looks_cn_region("网络视频录像机")
    assert not looks_cn_region("Network Video Recorder", None)
