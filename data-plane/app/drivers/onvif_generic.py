"""Generic ONVIF (Profile S) driver — fallback for any brand, and the PTZ path for Uniview.

Two traps handled here:
- ONVIF services return LAN addresses (http://192.168.1.64/onvif/...). Over a tunnel we
  rewrite them to the tunnel endpoint.
- NVRs list profiles inconsistently (per channel, per stream, or only IP channels).
  We group by VideoSourceConfiguration token and take the highest resolution as main.
"""
from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime
from urllib.parse import urlsplit

from onvif import ONVIFCamera  # onvif-zeep-async

from . import rtsp
from .base import PTZ_ACTIONS, NvrDriver
from .models import Brand, ChannelInfo, Codec, DeviceInfo, Health, NotSupported, Stream

_PTZ = {"up": (0, 1, 0), "down": (0, -1, 0), "left": (-1, 0, 0), "right": (1, 0, 0),
        "zoom_in": (0, 0, 1), "zoom_out": (0, 0, -1)}


class OnvifDriver(NvrDriver):
    brand = Brand.ONVIF

    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        self._cam: ONVIFCamera | None = None
        self._profiles: dict[int, dict[str, str]] = {}   # channel -> {"main": token, "sub": token}

    async def _camera(self) -> ONVIFCamera:
        if self._cam is None:
            base = urlsplit(self.conn.http_base)
            cam = ONVIFCamera(base.hostname, base.port or self.conn.onvif_port or 80,
                              self.conn.username, self.conn.password)
            try:
                await cam.update_xaddrs()
            except BaseException:
                await cam.close()  # otherwise its HTTP session leaks on every failed probe
                raise
            # Rewrite LAN xaddrs to the tunnel endpoint
            for ns, url in list(cam.xaddrs.items()):
                p = urlsplit(url)
                cam.xaddrs[ns] = f"{base.scheme}://{base.netloc}{p.path}"
            self._cam = cam
        return self._cam

    async def close(self) -> None:
        if self._cam is not None:
            await self._cam.close()

    async def device_info(self) -> DeviceInfo:
        dm = (await self._camera()).devicemgmt
        i = await dm.GetDeviceInformation()
        return DeviceInfo(model=i.Model, serial=i.SerialNumber, firmware=i.FirmwareVersion)

    async def channels(self) -> list[ChannelInfo]:
        media = await (await self._camera()).create_media_service()
        by_source: dict[str, list] = {}
        for p in await media.GetProfiles():
            vsc = getattr(p, "VideoSourceConfiguration", None)
            if vsc is None:
                continue
            by_source.setdefault(vsc.SourceToken, []).append(p)
        out = []
        for n, (_, profiles) in enumerate(sorted(by_source.items()), start=1):
            def area(p):
                r = p.VideoEncoderConfiguration.Resolution if p.VideoEncoderConfiguration else None
                return (r.Width * r.Height) if r else 0
            profiles.sort(key=area, reverse=True)
            main, sub = profiles[0], (profiles[1] if len(profiles) > 1 else None)
            self._profiles[n] = {"main": main.token, "sub": (sub or main).token}
            enc = lambda p: Codec.parse(str(p.VideoEncoderConfiguration.Encoding)) if p and p.VideoEncoderConfiguration else Codec.UNKNOWN
            out.append(ChannelInfo(
                number=n, name=main.Name, main_codec=enc(main),
                sub_codec=enc(sub) if sub else Codec.NONE,
                ptz=getattr(main, "PTZConfiguration", None) is not None,
                onvif_profile_token=main.token,
            ))
        return out

    async def _token(self, channel: int, stream: Stream) -> str:
        if not self._profiles:
            await self.channels()
        try:
            return self._profiles[channel][stream.value]
        except KeyError:
            raise ValueError(f"no ONVIF profile for channel {channel}") from None

    async def stream_uri(self, channel: int, stream: Stream = Stream.SUB) -> str:
        media = await (await self._camera()).create_media_service()
        r = await media.GetStreamUri({
            "StreamSetup": {"Stream": "RTP-Unicast", "Transport": {"Protocol": "RTSP"}},
            "ProfileToken": await self._token(channel, stream),
        })
        return rtsp.rewrite_host(r.Uri, self.conn.rtsp)

    def live_url(self, channel: int, stream: Stream = Stream.SUB) -> str:
        raise NotSupported("ONVIF: await stream_uri() instead")

    def playback_url(self, *a, **kw) -> str:
        raise NotSupported("Profile G replay is rarely implemented on NVRs")

    async def health(self) -> Health:
        raise NotSupported("ONVIF has no standard HDD health")

    async def device_time(self) -> datetime | None:
        t = await (await self._camera()).devicemgmt.GetSystemDateAndTime()
        u = t.UTCDateTime
        return datetime(u.Date.Year, u.Date.Month, u.Date.Day, u.Time.Hour, u.Time.Minute, u.Time.Second,
                        tzinfo=UTC) if u else None

    async def snapshot(self, channel: int) -> bytes:
        media = await (await self._camera()).create_media_service()
        r = await media.GetSnapshotUri({"ProfileToken": await self._token(channel, Stream.MAIN)})
        p = urlsplit(r.Uri)
        path = p.path + (f"?{p.query}" if p.query else "")
        return (await self._get(path)).content

    async def ptz(self, channel: int, action: str, speed: int = 4) -> None:
        if action not in PTZ_ACTIONS:
            raise ValueError(action)
        ptz = await (await self._camera()).create_ptz_service()
        token = await self._token(channel, Stream.MAIN)
        if action == "stop":
            await ptz.Stop({"ProfileToken": token, "PanTilt": True, "Zoom": True})
            return
        x, y, z = (v * speed / 7 for v in _PTZ[action])
        await ptz.ContinuousMove({"ProfileToken": token,
                                  "Velocity": {"PanTilt": {"x": x, "y": y}, "Zoom": {"x": z}}})

    async def events(self) -> AsyncIterator[dict]:
        # PullPoint subscription; implemented in the edge agent where latency to the device is low.
        raise NotSupported("run ONVIF PullPoint from edge-agent")
        yield {}  # pragma: no cover
