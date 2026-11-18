"""Short-lived snapshot cache. Many viewers of the same camera in low-data mode share one
NVR request every couple of seconds instead of each hitting a weak NVR web server."""
from __future__ import annotations

import asyncio
import time
from collections import OrderedDict
from collections.abc import Awaitable, Callable

MAX_JPEG_BYTES = 3 * 1024 * 1024


class SnapshotCache:
    def __init__(self, ttl_s: float = 2.0, max_entries: int = 5000):
        self.ttl, self.max = ttl_s, max_entries
        self._data: OrderedDict[tuple[int, int], tuple[float, bytes]] = OrderedDict()
        self._locks: dict[tuple[int, int], asyncio.Lock] = {}

    async def get(self, nvr_id: int, channel: int, fetch: Callable[[], Awaitable[bytes]]) -> bytes:
        key = (nvr_id, channel)
        hit = self._data.get(key)
        if hit and time.monotonic() - hit[0] < self.ttl:
            return hit[1]
        lock = self._locks.setdefault(key, asyncio.Lock())
        async with lock:                       # concurrent misses wait for one fetch
            hit = self._data.get(key)
            if hit and time.monotonic() - hit[0] < self.ttl:
                return hit[1]
            jpeg = await fetch()
            if len(jpeg) > MAX_JPEG_BYTES or not jpeg.startswith(b"\xff\xd8"):
                raise ValueError("device returned something that isn't a JPEG")
            self._data[key] = (time.monotonic(), jpeg)
            self._data.move_to_end(key)
            while len(self._data) > self.max:
                old, _ = self._data.popitem(last=False)
                self._locks.pop(old, None)
            return jpeg
