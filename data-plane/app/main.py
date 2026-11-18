from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager, suppress

import httpx
from fastapi import FastAPI
from prometheus_client import make_asgi_app

from app.api.agent.routes import router as agent_router
from app.api.frps_plugin import router as frps_router
from app.api.ingest.routes import router as ingest_router
from app.api.internal.agents import router as internal_agents_router
from app.api.internal.routes import router as internal_router
from app.api.media import router as media_router
from app.core.ca import AgentCA
from app.core.config import settings
from app.core.redis import make_redis
from app.core.secrets import CredentialStore
from app.events.publisher import EventPublisher
from app.media.mediamtx import MediaMTX
from app.media.snapshots import SnapshotCache
from app.tunnels.commands import AgentCommandBus
from app.tunnels.ports import PortAllocator
from app.tunnels.presence import Presence
from app.tunnels.registry import TunnelRegistry

log = logging.getLogger(__name__)


async def sweep_loop(presence: Presence) -> None:
    while True:
        try:
            await presence.sweep()
        except Exception:
            log.exception("presence sweep failed")
        await asyncio.sleep(10)


@asynccontextmanager
async def lifespan(app: FastAPI):
    s = settings()
    redis = make_redis(s.redis_url)
    # One pooled client for thousands of devices. NVR web servers are weak: keep per-host limits low.
    http = httpx.AsyncClient(
        timeout=httpx.Timeout(s.device_timeout_s, connect=5.0),
        limits=httpx.Limits(max_connections=s.max_device_connections, max_keepalive_connections=500),
        verify=False,  # NVRs use self-signed certs; traffic is inside the tunnel
    )
    creds = CredentialStore(redis, s.credential_key)
    app.state.redis = redis
    app.state.http = http
    app.state.creds = creds
    app.state.tunnels = TunnelRegistry(redis, creds)
    app.state.media = MediaMTX(http, s.mediamtx_api, s.mediamtx_public_webrtc, s.mediamtx_public_hls, s.hmac_secret)
    app.state.events = EventPublisher(redis, s.events_stream, s.events_stream_maxlen)
    app.state.ca = AgentCA.from_files(s.ca_cert_path, s.ca_key_path)
    app.state.ports = PortAllocator(redis, s.tunnel_port_start, s.tunnel_port_end)
    app.state.bus = AgentCommandBus(redis)
    app.state.snapshots = SnapshotCache()
    app.state.presence = Presence(redis, s.agent_status_stream, s.heartbeat_timeout_s, bus=app.state.bus)
    sweeper = asyncio.create_task(sweep_loop(app.state.presence))
    yield
    sweeper.cancel()
    with suppress(asyncio.CancelledError):
        await sweeper
    await http.aclose()
    await redis.aclose()


app = FastAPI(title="VMS data plane", lifespan=lifespan)
app.include_router(internal_router)
app.include_router(internal_agents_router)
app.include_router(ingest_router)
app.include_router(agent_router)
app.include_router(frps_router)
app.include_router(media_router)
app.mount("/metrics", make_asgi_app())  # upgrade 15


@app.get("/healthz")
async def healthz():
    return {"ok": True, "node": settings().node_id}
