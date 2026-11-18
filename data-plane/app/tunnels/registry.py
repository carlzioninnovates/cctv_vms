"""Where is this NVR reachable from the cloud?

The edge agent runs frpc and exposes each NVR LAN port as an frps port that is bound to the
INTERNAL network only (see infra/frps/frps.toml proxyBindAddr). On connect it registers:
  HSET vms:tunnel:<agent_id>  "<lan_host>:<lan_port>"  "<frps_host>:<remote_port>"
plus a heartbeat key with TTL. This module reads those mappings.
"""
from __future__ import annotations

import json
from dataclasses import dataclass
from zoneinfo import ZoneInfo

from redis.asyncio import Redis

from app.core.secrets import CredentialStore
from app.drivers.models import Brand, DeviceConnection, DeviceUnreachable, RtspEndpoint

HEARTBEAT_TTL_S = 45


@dataclass
class NvrRecord:
    """Cached copy of the Laravel nvrs row, stored at probe time."""
    nvr_id: int
    brand: str
    agent_id: str
    lan_host: str
    http_port: int
    rtsp_port: int
    onvif_port: int
    credential_ref: str
    device_tz: str = "Asia/Manila"
    channel_codecs: dict | None = None    # {"1": {"main": "h265", "sub": "h264"}}


class TunnelRegistry:
    def __init__(self, redis: Redis, creds: CredentialStore):
        self.redis = redis
        self.creds = creds

    async def save_nvr(self, rec: NvrRecord) -> None:
        await self.redis.set(f"vms:nvr:{rec.nvr_id}", json.dumps(rec.__dict__))

    async def nvr(self, nvr_id: int) -> NvrRecord:
        raw = await self.redis.get(f"vms:nvr:{nvr_id}")
        if raw is None:
            raise KeyError(f"nvr {nvr_id} not registered with data plane; probe it first")
        return NvrRecord(**json.loads(raw))

    async def agent_online(self, agent_id: str) -> bool:
        return bool(await self.redis.exists(f"vms:agent:{agent_id}:hb"))

    async def _mapped(self, agent_id: str, lan_host: str, lan_port: int) -> tuple[str, int]:
        if not await self.agent_online(agent_id):
            raise DeviceUnreachable(f"edge agent {agent_id} offline")
        v = await self.redis.hget(f"vms:tunnel:{agent_id}", f"{lan_host}:{lan_port}")
        if v is None:
            raise DeviceUnreachable(f"agent {agent_id} has no tunnel for {lan_host}:{lan_port}")
        host, port = (v.decode() if isinstance(v, bytes) else v).rsplit(":", 1)
        return host, int(port)

    async def connection(self, rec: NvrRecord) -> DeviceConnection:
        user, pw = await self.creds.get(rec.credential_ref)
        hh, hp = await self._mapped(rec.agent_id, rec.lan_host, rec.http_port)
        rh, rp = await self._mapped(rec.agent_id, rec.lan_host, rec.rtsp_port)
        onvif_port = None
        if rec.onvif_port != rec.http_port:
            try:
                _, onvif_port = await self._mapped(rec.agent_id, rec.lan_host, rec.onvif_port)
            except DeviceUnreachable:
                pass
        return DeviceConnection(
            brand=Brand(rec.brand),
            http_base=f"http://{hh}:{hp}",
            rtsp=RtspEndpoint(rh, rp, user, pw),
            username=user,
            password=pw,
            device_tz=ZoneInfo(rec.device_tz),
            onvif_port=onvif_port,
        )
