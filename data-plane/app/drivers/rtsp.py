"""The ONLY place stream URLs are built. Stdlib only, fully unit-tested.

Times: Hikvision and Dahua playback URLs take the NVR's *local* wall-clock time
(Hikvision appends 'Z' but most firmware still reads it as local). Uniview takes
unix seconds. Callers pass timezone-aware datetimes; we convert here.
"""
from __future__ import annotations

from datetime import datetime, tzinfo
from urllib.parse import quote, urlsplit, urlunsplit

from .models import Brand, RtspEndpoint, Stream


def _netloc(ep: RtspEndpoint) -> str:
    host = f"[{ep.host}]" if ":" in ep.host and not ep.host.startswith("[") else ep.host
    userinfo = ""
    if ep.username:
        # Passwords with @ : / # are common on NVRs and break unencoded URLs.
        userinfo = quote(ep.username, safe="")
        if ep.password is not None:
            userinfo += ":" + quote(ep.password, safe="")
        userinfo += "@"
    return f"{userinfo}{host}:{ep.port}"


def _check(channel: int) -> None:
    if not isinstance(channel, int) or channel < 1:
        raise ValueError(f"channel must be a 1-based int, got {channel!r}")


def _aware(dt: datetime) -> datetime:
    if dt.tzinfo is None:
        raise ValueError("datetime must be timezone-aware")
    return dt


def live_url(brand: Brand, ep: RtspEndpoint, channel: int, stream: Stream = Stream.SUB) -> str:
    _check(channel)
    main = stream is Stream.MAIN
    if brand is Brand.HIKVISION:
        path = f"/Streaming/Channels/{channel * 100 + (1 if main else 2)}"
    elif brand is Brand.DAHUA:
        path = f"/cam/realmonitor?channel={channel}&subtype={0 if main else 1}"
    elif brand is Brand.UNIVIEW:
        path = f"/unicast/c{channel}/s{0 if main else 1}/live"
    else:
        raise ValueError("generic ONVIF devices: use GetStreamUri, then rewrite_host()")
    return f"rtsp://{_netloc(ep)}{path}"


def playback_url(
    brand: Brand,
    ep: RtspEndpoint,
    channel: int,
    start: datetime,
    end: datetime,
    device_tz: tzinfo,
    stream: Stream = Stream.MAIN,
) -> str:
    _check(channel)
    start, end = _aware(start), _aware(end)
    if end <= start:
        raise ValueError("end must be after start")
    main = stream is Stream.MAIN

    if brand is Brand.HIKVISION:
        s = start.astimezone(device_tz).strftime("%Y%m%dT%H%M%SZ")
        e = end.astimezone(device_tz).strftime("%Y%m%dT%H%M%SZ")
        track = channel * 100 + (1 if main else 2)
        path = f"/Streaming/tracks/{track}?starttime={s}&endtime={e}"
    elif brand is Brand.DAHUA:
        s = start.astimezone(device_tz).strftime("%Y_%m_%d_%H_%M_%S")
        e = end.astimezone(device_tz).strftime("%Y_%m_%d_%H_%M_%S")
        path = f"/cam/playback?channel={channel}&subtype={0 if main else 1}&starttime={s}&endtime={e}"
    elif brand is Brand.UNIVIEW:
        # VERIFY against target firmware; some builds use /media/video1 style paths.
        path = f"/c{channel}/b{int(start.timestamp())}/e{int(end.timestamp())}/replay/"
    else:
        raise ValueError("generic ONVIF playback: use Profile G GetReplayUri")
    return f"rtsp://{_netloc(ep)}{path}"


def rewrite_host(url: str, ep: RtspEndpoint) -> str:
    """ONVIF returns URIs with the NVR's LAN IP (192.168.x.x). Point them at the tunnel
    and add credentials, keeping path and query intact."""
    parts = urlsplit(url)
    return urlunsplit((parts.scheme or "rtsp", _netloc(ep), parts.path, parts.query, parts.fragment))


def redact(url: str) -> str:
    """For logs. Never log a URL with a password in it."""
    parts = urlsplit(url)
    if "@" not in parts.netloc:
        return url
    host = parts.netloc.rsplit("@", 1)[1]
    return urlunsplit((parts.scheme, f"***@{host}", parts.path, parts.query, parts.fragment))
