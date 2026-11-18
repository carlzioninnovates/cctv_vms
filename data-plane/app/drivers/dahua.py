"""Dahua CGI (HTTP API). Also covers Dahua OEMs (Amcrest, some Lorex/IMOU) — verify per OEM.
NOTE: Dahua config indexes are 0-based; RTSP/snapshot/PTZ channels are 1-based."""
from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime

from .base import PTZ_ACTIONS, NvrDriver
from .models import Brand, ChannelInfo, Codec, DeviceInfo, DiskHealth, Health
from .parsers import dahua_event_line, dahua_indexed, dahua_kv, looks_cn_region

_PTZ = {"up": "Up", "down": "Down", "left": "Left", "right": "Right", "zoom_in": "ZoomTele", "zoom_out": "ZoomWide"}


class DahuaDriver(NvrDriver):
    brand = Brand.DAHUA

    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        self._last_ptz: dict[int, str] = {}   # Dahua needs the same code to stop a move

    async def _kv(self, path: str) -> dict[str, str]:
        return dahua_kv((await self._get(path)).text)

    async def device_info(self) -> DeviceInfo:
        info = await self._kv("/cgi-bin/magicBox.cgi?action=getSystemInfo")
        ver = await self._kv("/cgi-bin/magicBox.cgi?action=getSoftwareVersion")
        return DeviceInfo(
            model=info.get("deviceType"),
            serial=info.get("serialNumber"),
            firmware=ver.get("version"),
            firmware_region="cn" if looks_cn_region(info.get("deviceType")) else "unknown",
        )

    async def channels(self) -> list[ChannelInfo]:
        titles = dahua_indexed(await self._kv("/cgi-bin/configManager.cgi?action=getConfig&name=ChannelTitle"),
                               "table.ChannelTitle")
        enc = dahua_indexed(await self._kv("/cgi-bin/configManager.cgi?action=getConfig&name=Encode"),
                            "table.Encode")
        out = []
        for idx in sorted(set(titles) | set(enc)):
            e = enc.get(idx, {})
            out.append(ChannelInfo(
                number=idx + 1,
                name=titles.get(idx, {}).get("Name"),
                main_codec=Codec.parse(e.get("MainFormat[0].Video.Compression")),
                sub_codec=Codec.parse(e.get("ExtraFormat[0].Video.Compression")),
            ))
        return out

    async def health(self) -> Health:
        kv = await self._kv("/cgi-bin/storageDevice.cgi?action=getDeviceAllInfo")
        disks = []
        for idx, d in sorted(dahua_indexed(kv, "list.info").items()):
            total = int(d.get("Detail[0].TotalBytes", "0").split(".")[0] or 0)
            used = int(d.get("Detail[0].UsedBytes", "0").split(".")[0] or 0)
            state = (d.get("State") or "").lower()
            disks.append(DiskHealth(
                id=d.get("Name", str(idx)),
                status="ok" if state == "success" else ("error" if state else "unknown"),
                capacity_mb=total // 2**20 or None,
                free_mb=(total - used) // 2**20 if total else None,
            ))
        return Health(disks=disks, recording_channels=None, checked_at=datetime.now(UTC).isoformat())

    async def device_time(self) -> datetime | None:
        kv = await self._kv("/cgi-bin/global.cgi?action=getCurrentTime")
        raw = kv.get("result")
        return datetime.strptime(raw, "%Y-%m-%d %H:%M:%S").replace(tzinfo=self.conn.device_tz) if raw else None

    async def snapshot(self, channel: int) -> bytes:
        return (await self._get(f"/cgi-bin/snapshot.cgi?channel={channel}")).content

    async def ptz(self, channel: int, action: str, speed: int = 4) -> None:
        if action not in PTZ_ACTIONS:
            raise ValueError(action)
        if action == "stop":
            code = self._last_ptz.pop(channel, "Up")
            await self._get(f"/cgi-bin/ptz.cgi?action=stop&channel={channel}&code={code}&arg1=0&arg2=0&arg3=0")
            return
        code = _PTZ[action]
        self._last_ptz[channel] = code
        s = max(1, min(8, speed))
        await self._get(f"/cgi-bin/ptz.cgi?action=start&channel={channel}&code={code}&arg1={s}&arg2={s}&arg3=0")

    async def events(self) -> AsyncIterator[dict]:
        url = self.conn.http_base + "/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5"
        async with self.client.stream("GET", url, auth=self.auth, timeout=None) as r:
            async for line in r.aiter_lines():
                if not line.startswith("Code="):
                    continue  # multipart boundaries, headers, Heartbeat
                ev = dahua_event_line(line)
                idx = ev.get("index")
                yield {
                    "raw_type": ev.get("Code", ""),
                    "raw_state": ev.get("action"),
                    "channel": int(idx) + 1 if idx and idx.isdigit() else None,
                    "occurred_at": datetime.now(self.conn.device_tz).isoformat(),
                    "target": None,
                }
