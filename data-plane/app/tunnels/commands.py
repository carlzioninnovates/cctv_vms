"""Command channel to edge agents. Agents can't accept inbound connections (CGNAT), so they
long-poll GET /agent/commands. The data plane pushes a command and awaits the reply
with a timeout. Fully async: BLPOP, never a sleep loop."""
from __future__ import annotations

import json
import time
import uuid

from redis.asyncio import Redis

from app.drivers.models import DeviceUnreachable

COMMANDS = {"discover", "expose", "check_tcp"}


class AgentCommandBus:
    def __init__(self, redis: Redis):
        self.redis = redis

    async def send(self, agent_id: str, ctype: str, payload: dict, timeout: float = 20) -> dict:
        if ctype not in COMMANDS:
            raise ValueError(f"unknown command {ctype}")
        cmd_id = str(uuid.uuid4())
        cmd = {"id": cmd_id, "type": ctype, "payload": payload, "expires_at": time.time() + timeout}
        await self.redis.set(f"vms:cmd:{cmd_id}:agent", agent_id, ex=int(timeout) + 60)
        await self.redis.rpush(f"vms:agent:{agent_id}:cmds", json.dumps(cmd))
        await self.redis.expire(f"vms:agent:{agent_id}:cmds", int(timeout) + 60)
        res = await self.redis.blpop([f"vms:cmdres:{cmd_id}"], timeout=timeout)
        if res is None:
            raise DeviceUnreachable(f"edge agent {agent_id} did not answer '{ctype}' within {timeout:.0f}s")
        result = json.loads(res[1])
        if not result.get("ok", False):
            raise RuntimeError(result.get("error") or f"agent failed '{ctype}'")
        return result.get("data") or {}

    async def push(self, agent_id: str, ctype: str, payload: dict, ttl: float = 120) -> str:
        """Queue a command without waiting for the result (e.g. restoring tunnels on reconnect)."""
        if ctype not in COMMANDS:
            raise ValueError(f"unknown command {ctype}")
        cmd_id = str(uuid.uuid4())
        cmd = {"id": cmd_id, "type": ctype, "payload": payload, "expires_at": time.time() + ttl}
        await self.redis.set(f"vms:cmd:{cmd_id}:agent", agent_id, ex=int(ttl) + 60)
        await self.redis.rpush(f"vms:agent:{agent_id}:cmds", json.dumps(cmd))
        await self.redis.expire(f"vms:agent:{agent_id}:cmds", int(ttl) + 60)
        return cmd_id

    async def next_for(self, agent_id: str, wait: float) -> dict | None:
        """Agent side: wait for the next unexpired command."""
        deadline = time.monotonic() + wait
        while (left := deadline - time.monotonic()) > 0:
            res = await self.redis.blpop([f"vms:agent:{agent_id}:cmds"], timeout=max(1, int(left)))
            if res is None:
                return None
            cmd = json.loads(res[1])
            if cmd.get("expires_at", 0) >= time.time():
                return cmd
        return None

    async def complete(self, agent_id: str, cmd_id: str, result: dict) -> bool:
        owner = await self.redis.get(f"vms:cmd:{cmd_id}:agent")
        if owner is None or (owner.decode() if isinstance(owner, bytes) else owner) != agent_id:
            return False     # unknown, expired, or another agent's command
        key = f"vms:cmdres:{cmd_id}"
        await self.redis.rpush(key, json.dumps(result))
        await self.redis.expire(key, 60)
        await self.redis.delete(f"vms:cmd:{cmd_id}:agent")
        return True
