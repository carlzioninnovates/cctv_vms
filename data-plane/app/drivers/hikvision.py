"""Hikvision ISAPI. Also covers Hikvision OEMs (LTS, Annke, some Hilook) — verify per OEM."""
from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime

from .base import PTZ_ACTIONS, NvrDriver
from .models import Brand, ChannelInfo, Codec, DeviceInfo, DiskHealth, Health
from .parsers import looks_cn_region, xml_strip_ns, xml_text

_PTZ = {  # pan, tilt, zoom in -100..100
    "up": (0, 1, 0), "down": (0, -1, 0), "left": (-1, 0, 0), "right": (1, 0, 0),
    "zoom_in": (0, 0, 1), "zoom_out": (0, 0, -1), "stop": (0, 0, 0),
}
_HDD_STATUS = {"ok": "ok", "idle": "ok", "error": "error", "unformatted": "unformatted", "nohdd": "missing"}


class HikvisionDriver(NvrDriver):
    brand = Brand.HIKVISION

    async def device_info(self) -> DeviceInfo:
        x = xml_strip_ns((await self._get("/ISAPI/System/deviceInfo")).content)
        name, desc = xml_text(x, "deviceName"), xml_text(x, "deviceDescription")
        return DeviceInfo(
            model=xml_text(x, "model"),
            serial=xml_text(x, "serialNumber"),
            firmware=" ".join(filter(None, [xml_text(x, "firmwareVersion"), xml_text(x, "firmwareReleasedDate")])),
            firmware_region="cn" if looks_cn_region(name, desc) else "unknown",
        )

    async def channels(self) -> list[ChannelInfo]:
        # IP channels on an NVR. DVRs (analog) use /ISAPI/System/Video/inputs/channels instead.
        chans: dict[int, ChannelInfo] = {}
        try:
            x = xml_strip_ns((await self._get("/ISAPI/ContentMgmt/InputProxy/channels")).content)
            for c in x.iter("InputProxyChannel"):
                n = int(xml_text(c, "id") or 0)
                if n:
                    chans[n] = ChannelInfo(number=n, name=xml_text(c, "name"))
            st = xml_strip_ns((await self._get("/ISAPI/ContentMgmt/InputProxy/channels/status")).content)
            for c in st.iter("InputProxyChannelStatus"):
                n = int(xml_text(c, "id") or 0)
                if n in chans:
                    chans[n].online = (xml_text(c, "online") or "").lower() == "true"
        except Exception:
            x = xml_strip_ns((await self._get("/ISAPI/System/Video/inputs/channels")).content)
            for c in x.iter("VideoInputChannel"):
                n = int(xml_text(c, "id") or 0)
                if n:
                    chans[n] = ChannelInfo(number=n, name=xml_text(c, "name"))

        # Codecs: StreamingChannel id = channel*100 + stream (1 main, 2 sub)
        sx = xml_strip_ns((await self._get("/ISAPI/Streaming/channels")).content)
        for sc in sx.iter("StreamingChannel"):
            sid = int(xml_text(sc, "id") or 0)
            ch, kind = divmod(sid, 100)
            if ch not in chans:
                continue
            codec = Codec.parse(xml_text(sc, "Video/videoCodecType"))
            if kind == 1:
                chans[ch].main_codec = codec
            elif kind == 2:
                chans[ch].sub_codec = codec
        return sorted(chans.values(), key=lambda c: c.number)

    async def health(self) -> Health:
        x = xml_strip_ns((await self._get("/ISAPI/ContentMgmt/Storage")).content)
        disks = []
        for h in x.iter("hdd"):
            status = (xml_text(h, "status") or "unknown").lower()
            disks.append(DiskHealth(
                id=xml_text(h, "id") or "?",
                status=_HDD_STATUS.get(status, "unknown"),
                capacity_mb=int(xml_text(h, "capacity") or 0) or None,
                free_mb=int(xml_text(h, "freeSpace") or 0) or None,
            ))
        # VERIFY: per-channel recording state endpoint differs across firmware; unknown for now.
        return Health(disks=disks, recording_channels=None, checked_at=datetime.now(UTC).isoformat())

    async def device_time(self) -> datetime | None:
        x = xml_strip_ns((await self._get("/ISAPI/System/time")).content)
        lt = xml_text(x, "localTime")
        return datetime.fromisoformat(lt) if lt else None

    async def snapshot(self, channel: int) -> bytes:
        return (await self._get(f"/ISAPI/Streaming/channels/{channel * 100 + 1}/picture")).content

    async def ptz(self, channel: int, action: str, speed: int = 4) -> None:
        if action not in PTZ_ACTIONS:
            raise ValueError(action)
        p, t, z = (v * round(speed / 7 * 100) for v in _PTZ[action])
        body = f"<PTZData><pan>{p}</pan><tilt>{t}</tilt><zoom>{z}</zoom></PTZData>"
        await self._req("PUT", f"/ISAPI/PTZCtrl/channels/{channel}/continuous",
                        content=body, headers={"Content-Type": "application/xml"})

    async def events(self) -> AsyncIterator[dict]:
        # Long-lived multipart stream of <EventNotificationAlert> documents.
        url = self.conn.http_base + "/ISAPI/Event/notification/alertStream"
        buf = b""
        async with self.client.stream("GET", url, auth=self.auth, timeout=None) as r:
            async for chunk in r.aiter_bytes():
                buf += chunk
                while (end := buf.find(b"</EventNotificationAlert>")) != -1:
                    start = buf.find(b"<EventNotificationAlert")
                    doc, buf = buf[start:end + 25], buf[end + 25:]
                    x = xml_strip_ns(doc)
                    etype = xml_text(x, "eventType") or ""
                    if etype.lower() == "videoloss" and (xml_text(x, "activeState") or "") == "inactive":
                        continue  # Hikvision sends 'videoloss inactive' as a keep-alive
                    ch = xml_text(x, "channelID") or xml_text(x, "dynChannelID")
                    yield {
                        "raw_type": etype,
                        "raw_state": xml_text(x, "activeState"),
                        "channel": int(ch) if ch and ch.isdigit() else None,
                        "occurred_at": xml_text(x, "dateTime"),
                        "target": xml_text(x, "DetectionRegionList/DetectionRegionEntry/detectionTarget"),
                    }
