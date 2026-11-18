"""Capability probe: find out what THIS device can actually do, then store it.
Stops on the first 401 so we never lock the NVR account."""
from __future__ import annotations

import logging
from datetime import UTC, datetime

import httpx

from .base import NvrDriver
from .models import Brand, Capabilities, DeviceAuthError, DeviceConnection, DeviceInfo, NotSupported
from .onvif_generic import OnvifDriver
from .registry import driver_for

log = logging.getLogger(__name__)


async def probe(conn: DeviceConnection, client: httpx.AsyncClient) -> Capabilities:
    warnings: list[str] = []
    drv: NvrDriver = driver_for(conn, client)
    vendor_api = conn.brand is not Brand.ONVIF

    # 1. Identity (vendor API first; DeviceAuthError propagates — do not retry)
    try:
        info = await drv.device_info()
    except DeviceAuthError:
        raise
    except Exception as e:
        vendor_api = False
        warnings.append(f"vendor API failed ({type(e).__name__}); falling back to ONVIF")
        drv = OnvifDriver(conn, client)
        info = await _safe(drv.device_info(), warnings, "onvif device info") or DeviceInfo()

    # 2. ONVIF available? (needed for PTZ on some brands; often disabled on Hikvision)
    onvif_ok = isinstance(drv, OnvifDriver)
    if not onvif_ok:
        o = OnvifDriver(conn, client)
        try:
            await o.device_info()
            onvif_ok = True
        except DeviceAuthError:
            warnings.append("ONVIF user missing or different password — enable ONVIF and add an ONVIF user")
        except Exception:
            warnings.append("ONVIF disabled or unreachable — PTZ via vendor API only")
        finally:
            await o.close()

    # 3. Channels + codecs
    channels = await _safe(drv.channels(), warnings, "channel list") or []
    for ch in channels:
        if not ch.sub_codec.webrtc_safe and ch.sub_codec.value not in ("unknown", "none"):
            warnings.append(f"channel {ch.number}: substream is {ch.sub_codec.value}; set it to H.264 for WebRTC")
    if any(ch.main_codec.value == "h265" for ch in channels):
        warnings.append("main streams are H.265: fullscreen will use HLS unless transcoding is enabled")

    # 4. Health
    hdd = False
    try:
        await drv.health()
        hdd = True
    except NotSupported:
        pass
    except DeviceAuthError:
        raise
    except Exception as e:
        warnings.append(f"HDD health unavailable ({type(e).__name__})")

    # 5. Clock drift (upgrade 16)
    drift = None
    t = await _safe(drv.device_time(), warnings, "device time")
    if t:
        drift = round((t - datetime.now(UTC)).total_seconds(), 1)
        if abs(drift) > 30:
            warnings.append(f"NVR clock off by {drift:+.0f}s — enable NTP (playback search will be wrong)")

    if info.firmware_region == "cn":
        warnings.append("CN-region firmware detected — some features and updates may be unavailable")

    if isinstance(drv, OnvifDriver):
        await drv.close()

    return Capabilities(
        device=info,
        vendor_api=vendor_api,
        onvif=onvif_ok,
        event_stream="vendor" if vendor_api and conn.brand in (Brand.HIKVISION, Brand.DAHUA)
        else ("onvif_pullpoint" if onvif_ok else "none"),
        playback="vendor_rtsp" if conn.brand is not Brand.ONVIF else "none",
        hdd_health=hdd,
        clock_drift_seconds=drift,
        channels=channels,
        warnings=warnings,
    )


async def _safe(coro, warnings: list[str], what: str):
    try:
        return await coro
    except DeviceAuthError:
        raise
    except Exception as e:
        log.info("probe step failed: %s: %s", what, e)
        warnings.append(f"{what} failed ({type(e).__name__})")
        return None
