"""Agent online/offline tracking (upgrade 1). Heartbeats come in every 15 s; a background
sweep marks agents offline after heartbeat_timeout_s. Status changes go to a Redis stream
that Laravel reads (agents:sync-status)."""
from __future__ import annotations

import json
import time

from redis.asyncio import Redis

from .offline import OfflineCause, SiteSignals, classify, reclassify_on_reconnect

LASTSEEN = "vms:agents:lastseen"      # zset agent_id -> unix ts


class Presence:
    def __init__(self, redis: Redis, stream: str, timeout_s: int = 45, bus=None):
        self.redis, self.stream, self.timeout, self.bus = redis, stream, timeout_s, bus

    async def resync_tunnels(self, agent_id: str) -> bool:
        """Re-send the agent's full tunnel list. Needed after re-enrollment (new tunnel secret, or a
        replacement device with no tunnel config) and after outages."""
        if self.bus is None:
            return False
        raw = await self.redis.hgetall(f"vms:agent:{agent_id}:proxies")
        if not raw:
            return False
        proxies = sorted((json.loads(v) for v in raw.values()), key=lambda p: p["remote_port"])
        await self.bus.push(agent_id, "expose", {"proxies": proxies, "check": []})
        return True

    async def _publish(self, agent_id: str, status: str, **extra) -> None:
        await self.redis.xadd(self.stream, {"event": json.dumps({"agent_id": agent_id, "status": status,
                                                                 "at": time.time(), **extra})},
                              maxlen=100_000, approximate=True)

    async def heartbeat(self, agent_id: str, hb: dict, now: float | None = None) -> None:
        now = now or time.time()
        state_key = f"vms:agent:{agent_id}:state"
        offline_since = await self.redis.hget(state_key, "offline_since")
        await self.redis.set(f"vms:agent:{agent_id}:hb", "1", ex=self.timeout)
        await self.redis.zadd(LASTSEEN, {agent_id: now})
        await self.redis.hset(state_key, mapping={
            "last_signal": hb.get("power") or hb.get("uplink_event") or "",
            "uptime_s": int(hb.get("uptime_s") or 0),
            "buffered_events": int(hb.get("buffered_events") or 0),
            "uplink": hb.get("uplink") or "",
            "version": hb.get("version") or "",
        })
        first_seen = not await self.redis.hexists(state_key, "seen")
        needs_resync = await self.redis.hget(state_key, "needs_resync") is not None
        if first_seen or offline_since is not None or needs_resync:
            await self.redis.hdel(state_key, "needs_resync")
            await self.resync_tunnels(agent_id)
        if first_seen:
            await self.redis.hset(state_key, "seen", "1")
            await self._publish(agent_id, "online", first=True)
        elif offline_since is not None:
            cause = OfflineCause((await self.redis.hget(state_key, "offline_cause") or b"site_unreachable").decode())
            final = reclassify_on_reconnect(cause, agent_uptime_s=float(hb.get("uptime_s") or 0),
                                            offline_duration_s=now - float(offline_since))
            await self.redis.hdel(state_key, "offline_since", "offline_cause")
            await self._publish(agent_id, "online", offline_since=float(offline_since), cause=final.value)

    async def sweep(self, now: float | None = None) -> list[str]:
        """Mark agents with no heartbeat in timeout_s as offline. Returns newly-offline ids."""
        now = now or time.time()
        stale = await self.redis.zrangebyscore(LASTSEEN, "-inf", now - self.timeout)
        newly = []
        for raw in stale:
            agent_id = raw.decode() if isinstance(raw, bytes) else raw
            state_key = f"vms:agent:{agent_id}:state"
            last_seen = await self.redis.zscore(LASTSEEN, agent_id)
            if not await self.redis.hsetnx(state_key, "offline_since", last_seen):
                continue   # already marked
            sig = (await self.redis.hget(state_key, "last_signal") or b"").decode() or None
            cause = classify(SiteSignals(agent_connected=False, last_agent_signal=sig))
            await self.redis.hset(state_key, "offline_cause", cause.value)
            await self._publish(agent_id, "offline", offline_since=last_seen, cause=cause.value)
            newly.append(agent_id)
        return newly
