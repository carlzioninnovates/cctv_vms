"""Shared test app: real FastAPI routes, in-memory Redis, fake network (httpx.MockTransport)."""
from __future__ import annotations

import json
import time
from types import SimpleNamespace

import fakeredis
import httpx
import pytest
from cryptography.fernet import Fernet

from app.core import security
from app.core.config import settings

SECRET = "test-secret"


@pytest.fixture
async def dp(monkeypatch):
    monkeypatch.setenv("VMS_HMAC_SECRET", SECRET)
    monkeypatch.setenv("VMS_CREDENTIAL_KEY", Fernet.generate_key().decode())
    monkeypatch.setenv("VMS_FRPS_TOKEN", "frps-shared")
    settings.cache_clear()

    from app.core.ca import AgentCA
    from app.core.secrets import CredentialStore
    from app.events.publisher import EventPublisher
    from app.main import app
    from app.media.mediamtx import MediaMTX
    from app.media.snapshots import SnapshotCache
    from app.tunnels.commands import AgentCommandBus
    from app.tunnels.ports import PortAllocator
    from app.tunnels.presence import Presence
    from app.tunnels.registry import TunnelRegistry

    handler = {"fn": lambda req: httpx.Response(404)}
    redis = fakeredis.aioredis.FakeRedis()
    http = httpx.AsyncClient(transport=httpx.MockTransport(lambda req: handler["fn"](req)))
    creds = CredentialStore(redis, settings().credential_key)
    st = app.state
    st.redis, st.http, st.creds = redis, http, creds
    st.tunnels = TunnelRegistry(redis, creds)
    st.media = MediaMTX(http, "http://mediamtx:9997", "https://media.test", "https://media.test:8888", SECRET)
    st.events = EventPublisher(redis, "vms:events", 1000)
    st.ca = AgentCA.generate("Test CA")
    st.ports = PortAllocator(redis, 40000, 40009)
    st.bus = AgentCommandBus(redis)
    st.snapshots = SnapshotCache()
    st.presence = Presence(redis, "vms:agent-status", 45, bus=st.bus)

    client = httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://dp", timeout=60)
    yield SimpleNamespace(client=client, redis=redis, handler=handler, st=st, creds=creds)
    await client.aclose()
    await http.aclose()
    settings.cache_clear()


async def signed(client, method, path, payload=None):
    body = b"" if payload is None else json.dumps(payload).encode()
    sig = security.sign(SECRET, int(time.time()), body)
    return await client.request(method, path, content=body,
                                headers={"X-VMS-Signature": sig, "Content-Type": "application/json"})
