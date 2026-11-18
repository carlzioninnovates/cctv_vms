from datetime import UTC, datetime
from zoneinfo import ZoneInfo

from app.drivers import rtsp
from app.drivers.models import Brand, RtspEndpoint, Stream

EP = RtspEndpoint("10.8.0.5", 40554, "admin", "p@ss:w/rd#1")
MNL = ZoneInfo("Asia/Manila")


def _raises(fn, exc=ValueError):
    try:
        fn()
    except exc:
        return
    raise AssertionError(f"expected {exc.__name__}")


def test_password_special_chars_are_encoded():
    url = rtsp.live_url(Brand.HIKVISION, EP, 1, Stream.MAIN)
    assert url == "rtsp://admin:p%40ss%3Aw%2Frd%231@10.8.0.5:40554/Streaming/Channels/101"


def test_hikvision_live_ids():
    ep = RtspEndpoint("h", 554)
    assert rtsp.live_url(Brand.HIKVISION, ep, 1, Stream.SUB).endswith("/Streaming/Channels/102")
    assert rtsp.live_url(Brand.HIKVISION, ep, 12, Stream.MAIN).endswith("/Streaming/Channels/1201")


def test_dahua_live():
    ep = RtspEndpoint("h", 554)
    assert rtsp.live_url(Brand.DAHUA, ep, 3, Stream.MAIN).endswith("/cam/realmonitor?channel=3&subtype=0")
    assert rtsp.live_url(Brand.DAHUA, ep, 3, Stream.SUB).endswith("subtype=1")


def test_uniview_live():
    ep = RtspEndpoint("h", 554)
    assert rtsp.live_url(Brand.UNIVIEW, ep, 2, Stream.SUB).endswith("/unicast/c2/s1/live")


def test_channel_must_be_one_based():
    _raises(lambda: rtsp.live_url(Brand.DAHUA, EP, 0))


def test_onvif_live_not_templated():
    _raises(lambda: rtsp.live_url(Brand.ONVIF, EP, 1))


def test_playback_uses_device_local_time():
    start = datetime(2026, 11, 10, 0, 0, 0, tzinfo=UTC)   # 08:00 Manila
    end = datetime(2026, 11, 10, 0, 30, 0, tzinfo=UTC)
    ep = RtspEndpoint("h", 554)
    hik = rtsp.playback_url(Brand.HIKVISION, ep, 1, start, end, MNL)
    assert hik.endswith("/Streaming/tracks/101?starttime=20261110T080000Z&endtime=20261110T083000Z")
    dh = rtsp.playback_url(Brand.DAHUA, ep, 1, start, end, MNL)
    assert "starttime=2026_11_10_08_00_00&endtime=2026_11_10_08_30_00" in dh
    unv = rtsp.playback_url(Brand.UNIVIEW, ep, 1, start, end, MNL)
    assert unv.endswith(f"/c1/b{int(start.timestamp())}/e{int(end.timestamp())}/replay/")


def test_playback_rejects_naive_and_reversed():
    ep = RtspEndpoint("h", 554)
    naive = datetime(2026, 11, 10, 8)
    aware = datetime(2026, 11, 10, 8, tzinfo=MNL)
    _raises(lambda: rtsp.playback_url(Brand.DAHUA, ep, 1, naive, naive, MNL))
    _raises(lambda: rtsp.playback_url(Brand.DAHUA, ep, 1, aware, aware, MNL))


def test_rewrite_onvif_lan_uri_to_tunnel():
    lan = "rtsp://192.168.1.64:554/Streaming/Channels/101?transportmode=unicast&profile=Profile_1"
    out = rtsp.rewrite_host(lan, EP)
    assert out.startswith("rtsp://admin:p%40ss%3Aw%2Frd%231@10.8.0.5:40554/Streaming/Channels/101?")
    assert "profile=Profile_1" in out


def test_ipv6_host():
    assert "@[fd00::5]:554/" in rtsp.live_url(Brand.UNIVIEW, RtspEndpoint("fd00::5", 554, "a", "b"), 1)


def test_redact():
    assert rtsp.redact(rtsp.live_url(Brand.HIKVISION, EP, 1)) == "rtsp://***@10.8.0.5:40554/Streaming/Channels/102"
