"""Vendor event -> contracts/schemas/event.v1.json. Stdlib only, unit-tested.

Unmapped vendor codes become type 'unknown' with raw_type kept, so nothing is dropped
silently. Add mappings here (and a test) when a new code shows up in logs.
"""
from __future__ import annotations

import uuid
from dataclasses import asdict, dataclass
from datetime import UTC, datetime

from app.drivers.models import Brand

# Hikvision ISAPI alertStream <eventType> (lower-cased)
HIKVISION = {
    "vmd": "motion",
    "linedetection": "line_crossing",
    "fielddetection": "intrusion",
    "regionentrance": "intrusion",
    "regionexiting": "intrusion",
    "videoloss": "video_loss",
    "shelteralarm": "tamper",           # video tampering
    "diskerror": "hdd_error",
    "diskfull": "hdd_full",
    "nohdd": "hdd_error",
    "recordexception": "recording_stopped",
    "io": "io_alarm",
    "facedetection": "face",
}
# Hikvision AcuSense: VMD with a target type becomes human/vehicle
HIKVISION_TARGETS = {"human": "human", "vehicle": "vehicle"}

# Dahua eventManager.cgi Code=
DAHUA = {
    "videomotion": "motion",
    "smartmotionhuman": "human",
    "smartmotionvehicle": "vehicle",
    "crosslinedetection": "line_crossing",
    "crossregiondetection": "intrusion",
    "videoloss": "video_loss",
    "videoblind": "tamper",
    "storagefailure": "hdd_error",
    "storagenotexist": "hdd_error",
    "storagelowspace": "hdd_full",
    "recordfailure": "recording_stopped",   # VERIFY name on target firmware
    "alarmlocal": "io_alarm",
    "facedetection": "face",
}

# ONVIF topics (suffix match). Used for generic devices and Uniview.
ONVIF = [
    ("ruleengine/cellmotiondetector/motion", "motion"),
    ("videosource/motionalarm", "motion"),
    ("ruleengine/linedetector/crossed", "line_crossing"),
    ("ruleengine/fielddetector/objectsinside", "intrusion"),
    ("videosource/globalscenechange", "tamper"),
    ("videosource/signalloss", "video_loss"),
    ("device/hardwarefailure/storagefailure", "hdd_error"),
    ("device/trigger/digitalinput", "io_alarm"),
]

# Uniview native names. VERIFY against a real unit; ONVIF topics are tried first.
UNIVIEW = {
    "motiondetectionalarm": "motion",
    "crosslinedetection": "line_crossing",
    "intrusiondetection": "intrusion",
    "videoloss": "video_loss",
    "tamperingdetection": "tamper",
    "diskerror": "hdd_error",
    "diskfull": "hdd_full",
}

DEVICE_LEVEL = {"hdd_error", "hdd_full"}

_START = {"start", "active", "true", "1", "on"}
_STOP = {"stop", "inactive", "false", "0", "off"}


@dataclass
class NormalizedEvent:
    event_id: str
    nvr_id: int
    channel: int | None
    brand: str
    type: str
    state: str
    occurred_at: str
    received_at: str
    raw_type: str
    snapshot_key: str | None = None
    ai: dict | None = None
    buffered: bool = False

    def to_dict(self) -> dict:
        return asdict(self)


def map_type(brand: Brand, raw_type: str, target: str | None = None) -> str:
    key = raw_type.strip().lower()
    if brand is Brand.HIKVISION:
        t = HIKVISION.get(key, "unknown")
        if t == "motion" and target:
            t = HIKVISION_TARGETS.get(target.lower(), t)
        return t
    if brand is Brand.DAHUA:
        return DAHUA.get(key, "unknown")
    for suffix, t in ONVIF:
        if key.endswith(suffix):
            return t
    if brand is Brand.UNIVIEW:
        return UNIVIEW.get(key, "unknown")
    return "unknown"


def map_state(raw_state: str | None) -> str:
    s = (raw_state or "").strip().lower()
    if s in _START:
        return "start"
    if s in _STOP:
        return "stop"
    return "pulse"


def _iso_utc(dt: datetime) -> str:
    if dt.tzinfo is None:
        raise ValueError("occurred_at must be timezone-aware")
    return dt.astimezone(UTC).isoformat().replace("+00:00", "Z")


def normalize(
    *,
    nvr_id: int,
    brand: Brand,
    raw_type: str,
    raw_state: str | None,
    channel: int | None,
    occurred_at: datetime,
    target: str | None = None,
    buffered: bool = False,
    now: datetime | None = None,
    event_id: str | None = None,
) -> NormalizedEvent:
    etype = map_type(brand, raw_type, target)
    if etype in DEVICE_LEVEL:
        channel = None
    elif channel is not None and channel < 1:
        raise ValueError("channel must be 1-based; convert Dahua 0-based index before calling")
    return NormalizedEvent(
        event_id=event_id or str(uuid.uuid4()),
        nvr_id=nvr_id,
        channel=channel,
        brand=brand.value,
        type=etype,
        state=map_state(raw_state),
        occurred_at=_iso_utc(occurred_at),
        received_at=_iso_utc(now or datetime.now(UTC)),
        raw_type=raw_type,
        buffered=buffered,
    )
