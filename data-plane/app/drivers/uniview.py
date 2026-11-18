"""Uniview (UNV). Native RTSP URLs from rtsp.py; device control over ONVIF, which UNV
implements well. LAPI is used only for device info, and its paths are marked VERIFY."""
from __future__ import annotations

import logging
from datetime import datetime

from . import rtsp
from .models import Brand, DeviceInfo, Health, NotSupported, Stream
from .onvif_generic import OnvifDriver

log = logging.getLogger(__name__)


class UniviewDriver(OnvifDriver):
    brand = Brand.UNIVIEW

    def live_url(self, channel: int, stream: Stream = Stream.SUB) -> str:
        return rtsp.live_url(self.brand, self.conn.rtsp, channel, stream)

    def playback_url(self, channel: int, start: datetime, end: datetime, stream: Stream = Stream.MAIN) -> str:
        return rtsp.playback_url(self.brand, self.conn.rtsp, channel, start, end, self.conn.device_tz, stream)

    async def device_info(self) -> DeviceInfo:
        try:
            # VERIFY path + field names on a real unit
            r = await self._get("/LAPI/V1.0/System/DeviceBasicInfo")
            d = r.json().get("Response", {}).get("Data", {})
            if d:
                return DeviceInfo(model=d.get("DeviceModel"), serial=d.get("SerialNumber"),
                                  firmware=d.get("FirmwareVersion"))
        except NotSupported:
            raise
        except Exception as e:
            log.info("uniview LAPI device info failed, using ONVIF: %s", e)
        return await super().device_info()

    async def health(self) -> Health:
        # VERIFY: LAPI storage endpoint. Until verified, health comes from HDD events only.
        raise NotSupported("Uniview HDD health not verified yet")
