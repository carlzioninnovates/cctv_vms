"""Abstract driver. One instance per NVR request; shares a pooled httpx.AsyncClient."""
from __future__ import annotations

from abc import ABC, abstractmethod
from collections.abc import AsyncIterator
from datetime import datetime

import httpx

from . import rtsp
from .models import (
    Brand,
    ChannelInfo,
    DeviceAuthError,
    DeviceConnection,
    DeviceInfo,
    DeviceUnreachable,
    Health,
    Stream,
)

PTZ_ACTIONS = {"up", "down", "left", "right", "zoom_in", "zoom_out", "stop"}


class NvrDriver(ABC):
    brand: Brand

    def __init__(self, conn: DeviceConnection, client: httpx.AsyncClient):
        self.conn = conn
        self.client = client
        self.auth = httpx.DigestAuth(conn.username, conn.password)

    # ---- HTTP helper: maps errors to our exceptions, never retries a 401 ----
    async def _get(self, path: str, **kw) -> httpx.Response:
        return await self._req("GET", path, **kw)

    async def _req(self, method: str, path: str, **kw) -> httpx.Response:
        try:
            r = await self.client.request(method, self.conn.http_base + path, auth=self.auth, **kw)
        except (httpx.ConnectError, httpx.TimeoutException) as e:
            raise DeviceUnreachable(str(e)) from e
        if r.status_code == 401:
            raise DeviceAuthError(f"{self.brand.value} rejected credentials")
        r.raise_for_status()
        return r

    # ---- Stream URLs: pure, delegated to rtsp.py ----
    def live_url(self, channel: int, stream: Stream = Stream.SUB) -> str:
        return rtsp.live_url(self.brand, self.conn.rtsp, channel, stream)

    def playback_url(self, channel: int, start: datetime, end: datetime, stream: Stream = Stream.MAIN) -> str:
        return rtsp.playback_url(self.brand, self.conn.rtsp, channel, start, end, self.conn.device_tz, stream)

    # ---- Device operations each brand implements ----
    @abstractmethod
    async def device_info(self) -> DeviceInfo: ...

    @abstractmethod
    async def channels(self) -> list[ChannelInfo]: ...

    @abstractmethod
    async def health(self) -> Health: ...

    @abstractmethod
    async def device_time(self) -> datetime | None:
        """NVR clock as aware datetime, for drift detection (upgrade 16)."""

    @abstractmethod
    async def snapshot(self, channel: int) -> bytes: ...

    @abstractmethod
    async def ptz(self, channel: int, action: str, speed: int = 4) -> None: ...

    @abstractmethod
    def events(self) -> AsyncIterator[dict]:
        """Yield raw events: {raw_type, raw_state, channel (1-based), occurred_at, target}.
        Normally run by the edge agent on the LAN, not over the tunnel."""
