from datetime import UTC, datetime

from app.drivers.models import Brand
from app.events.normalizer import normalize

T = datetime(2026, 11, 10, 12, 0, tzinfo=UTC)


def n(brand, raw, state="start", ch=1, **kw):
    return normalize(nvr_id=7, brand=brand, raw_type=raw, raw_state=state, channel=ch, occurred_at=T, now=T, **kw)


def test_hikvision():
    assert n(Brand.HIKVISION, "VMD", "active").type == "motion"
    assert n(Brand.HIKVISION, "VMD", "active").state == "start"
    assert n(Brand.HIKVISION, "linedetection", "inactive").state == "stop"
    assert n(Brand.HIKVISION, "fielddetection").type == "intrusion"
    assert n(Brand.HIKVISION, "VMD", target="human").type == "human"


def test_dahua():
    assert n(Brand.DAHUA, "CrossLineDetection", "Start").type == "line_crossing"
    assert n(Brand.DAHUA, "SmartMotionHuman", "Pulse").state == "pulse"
    assert n(Brand.DAHUA, "VideoBlind").type == "tamper"


def test_onvif_topics_for_uniview_and_generic():
    assert n(Brand.UNIVIEW, "tns1:RuleEngine/LineDetector/Crossed", "true").type == "line_crossing"
    assert n(Brand.ONVIF, "tns1:RuleEngine/CellMotionDetector/Motion", "false").state == "stop"


def test_device_level_events_drop_channel():
    e = n(Brand.DAHUA, "StorageFailure", ch=1)
    assert e.type == "hdd_error" and e.channel is None


def test_unknown_kept_not_dropped():
    e = n(Brand.HIKVISION, "someNewThing")
    assert e.type == "unknown" and e.raw_type == "someNewThing"


def test_zero_based_channel_rejected():
    try:
        n(Brand.DAHUA, "VideoMotion", ch=0)
    except ValueError:
        return
    raise AssertionError


def test_times_are_utc_z():
    from zoneinfo import ZoneInfo
    e = normalize(nvr_id=1, brand=Brand.DAHUA, raw_type="VideoMotion", raw_state="Start", channel=1,
                  occurred_at=datetime(2026, 11, 10, 20, 0, tzinfo=ZoneInfo("Asia/Manila")), now=T)
    assert e.occurred_at == "2026-11-10T12:00:00Z"


def test_idempotent_event_id_passthrough():
    assert n(Brand.DAHUA, "VideoMotion", event_id="abc").event_id == "abc"
