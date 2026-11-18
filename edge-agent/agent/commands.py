"""Handles commands the cloud sends over the long-poll channel."""
from __future__ import annotations

import asyncio
import logging

import httpx

from agent.discovery.ws_discovery import discover
from agent.tunnel.frpc import FrpcSupervisor

log = logging.getLogger(__name__)


async def tcp_reachable(host: str, port: int, timeout: float = 3.0) -> bool:
    try:
        _, w = await asyncio.wait_for(asyncio.open_connection(host, port), timeout)
    except (TimeoutError, OSError):
        return False
    w.close()
    try:
        await w.wait_closed()
    except OSError:
        pass
    return True


class CommandHandler:
    def __init__(self, agent_uid: str, frps: dict, frpc: FrpcSupervisor):
        self.agent_uid, self.frps, self.frpc = agent_uid, frps, frpc

    async def handle(self, cmd: dict) -> dict:
        t, p = cmd["type"], cmd.get("payload") or {}
        try:
            if t == "discover":
                return {"ok": True, "data": {"devices": await discover(float(p.get("timeout", 4)))}}
            if t == "expose":
                checks = p.get("check") or []
                results = await asyncio.gather(*(tcp_reachable(c["host"], int(c["port"])) for c in checks))
                await self.frpc.apply(self.agent_uid, self.frps, p["proxies"])
                return {"ok": True, "data": {
                    "reachable": {f"{c['host']}:{c['port']}": ok for c, ok in zip(checks, results, strict=True)}}}
            if t == "check_tcp":
                return {"ok": True, "data": {"reachable": await tcp_reachable(p["host"], int(p["port"]))}}
            return {"ok": False, "error": f"unsupported command {t}"}
        except Exception as e:
            log.exception("command %s failed", t)
            return {"ok": False, "error": f"{type(e).__name__}: {e}"}


async def command_loop(client: httpx.AsyncClient, agent_url: str, handler: CommandHandler) -> None:
    backoff = 1
    while True:
        try:
            r = await client.get(f"{agent_url}/agent/commands", params={"wait": 25}, timeout=40)
            if r.status_code == 204:
                backoff = 1
                continue
            r.raise_for_status()
            cmd = r.json()
            result = await handler.handle(cmd)
            await client.post(f"{agent_url}/agent/commands/{cmd['id']}/result", json=result)
            backoff = 1
        except httpx.HTTPError as e:
            log.warning("command poll failed: %s (retry in %ss)", e, backoff)
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, 60)
