"""Allocates frps remote ports (one per exposed NVR port). Stable: the same
agent+LAN target always gets the same port back, so MediaMTX paths survive reconnects."""
from __future__ import annotations

from redis.asyncio import Redis

MAP = "vms:ports:map"        # hash  "<agent>|<lan_host>:<lan_port>" -> remote port
FREED = "vms:ports:freed"    # set of released ports, reused first
NEXT = "vms:ports:next"      # counter


class PortsExhausted(RuntimeError):
    pass


class PortAllocator:
    def __init__(self, redis: Redis, start: int, end: int):
        self.redis, self.start, self.end = redis, start, end

    @staticmethod
    def _field(agent_id: str, lan_host: str, lan_port: int) -> str:
        return f"{agent_id}|{lan_host}:{lan_port}"

    async def allocate(self, agent_id: str, lan_host: str, lan_port: int) -> int:
        field = self._field(agent_id, lan_host, lan_port)
        existing = await self.redis.hget(MAP, field)
        if existing is not None:
            return int(existing)
        port = await self.redis.spop(FREED)
        if port is None:
            n = await self.redis.incr(NEXT)
            port = self.start + n - 1
            if port > self.end:
                raise PortsExhausted(f"no tunnel ports left in {self.start}-{self.end}")
        port = int(port)
        if not await self.redis.hsetnx(MAP, field, port):   # lost a race: give ours back
            await self.redis.sadd(FREED, port)
            return int(await self.redis.hget(MAP, field))
        return port

    async def release(self, agent_id: str, lan_host: str, lan_port: int) -> None:
        field = self._field(agent_id, lan_host, lan_port)
        port = await self.redis.hget(MAP, field)
        if port is not None:
            await self.redis.hdel(MAP, field)
            await self.redis.sadd(FREED, int(port))
