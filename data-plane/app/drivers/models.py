"""Plain data types shared by all drivers. Stdlib only."""
from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import tzinfo
from enum import Enum


class Brand(str, Enum):
    HIKVISION = "hikvision"
    DAHUA = "dahua"
    UNIVIEW = "uniview"
    ONVIF = "onvif"


class Stream(str, Enum):
    MAIN = "main"
    SUB = "sub"


class Codec(str, Enum):
    H264 = "h264"
    H265 = "h265"
    MJPEG = "mjpeg"
    UNKNOWN = "unknown"
    NONE = "none"

    @classmethod
    def parse(cls, raw: str | None) -> Codec:
        if not raw:
            return cls.UNKNOWN
        r = raw.lower().replace(".", "").replace("-", "")
        if "265" in r or "hevc" in r:
            return cls.H265
        if "264" in r or "avc" in r:
            return cls.H264
        if "mjpeg" in r or "jpeg" in r:
            return cls.MJPEG
        return cls.UNKNOWN

    @property
    def webrtc_safe(self) -> bool:
        # H.265 over WebRTC depends on browser + hardware; treat it as not safe.
        return self is Codec.H264


@dataclass(frozen=True)
class RtspEndpoint:
    """Where MediaMTX connects. Through a tunnel this is the frps host + mapped port."""
    host: str
    port: int = 554
    username: str | None = None
    password: str | None = None


@dataclass(frozen=True)
class DeviceConnection:
    brand: Brand
    http_base: str          # e.g. http://frps.internal:40123 (tunnelled NVR web port)
    rtsp: RtspEndpoint
    username: str
    password: str
    device_tz: tzinfo       # NVR's local timezone; playback URLs use device-local time
    onvif_port: int | None = None


@dataclass
class ChannelInfo:
    number: int                         # 1-based
    name: str | None = None
    online: bool = True
    main_codec: Codec = Codec.UNKNOWN
    sub_codec: Codec = Codec.UNKNOWN
    ptz: bool = False
    onvif_profile_token: str | None = None


@dataclass
class DeviceInfo:
    model: str | None = None
    serial: str | None = None
    firmware: str | None = None
    firmware_region: str = "unknown"    # intl, cn, unknown


@dataclass
class DiskHealth:
    id: str
    status: str                         # ok, error, unformatted, missing, unknown
    capacity_mb: int | None = None
    free_mb: int | None = None


@dataclass
class Health:
    disks: list[DiskHealth] = field(default_factory=list)
    recording_channels: list[int] | None = None   # None = device can't tell us
    checked_at: str | None = None


@dataclass
class Capabilities:
    device: DeviceInfo
    vendor_api: bool
    onvif: bool
    event_stream: str                   # vendor, onvif_pullpoint, none
    playback: str                       # vendor_rtsp, onvif_profile_g, none
    hdd_health: bool
    clock_drift_seconds: float | None
    channels: list[ChannelInfo]
    warnings: list[str] = field(default_factory=list)

    def to_dict(self) -> dict:
        d = asdict(self)
        flat = d.pop("device")
        d.update(flat)
        for ch in d["channels"]:
            ch["main_codec"] = ch["main_codec"].value if isinstance(ch["main_codec"], Codec) else ch["main_codec"]
            ch["sub_codec"] = ch["sub_codec"].value if isinstance(ch["sub_codec"], Codec) else ch["sub_codec"]
        return d


class DeviceAuthError(Exception):
    """NVR rejected credentials. Never retry automatically: NVRs lock accounts after a few failures."""


class DeviceUnreachable(Exception):
    pass


class NotSupported(Exception):
    pass
