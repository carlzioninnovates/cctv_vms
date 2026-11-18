"""Onboarding endpoints Laravel calls (HMAC-signed): issue agent certs, store NVR
credentials, scan the site LAN, and open tunnels to an NVR."""
from __future__ import annotations

import json
import secrets
from typing import Literal

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.api.deps import HMAC, state
from app.core.ca import CsrRejected
from app.core.config import settings
from app.drivers.models import DeviceUnreachable
from app.tunnels.ports import PortsExhausted

router = APIRouter(prefix="/internal", dependencies=[HMAC])


class CsrRequest(BaseModel):
    csr_pem: str = Field(max_length=8192)


class Credentials(BaseModel):
    username: str = Field(min_length=1, max_length=64)
    password: str = Field(min_length=1, max_length=128)


class ExposePort(BaseModel):
    port: int = Field(ge=1, le=65535)
    kind: Literal["http", "rtsp", "onvif"]


class ExposeRequest(BaseModel):
    lan_host: str = Field(pattern=r"^[A-Za-z0-9.\-:]+$", max_length=255)
    ports: list[ExposePort] = Field(min_length=1, max_length=4)


def _agent_err(e: Exception) -> HTTPException:
    if isinstance(e, DeviceUnreachable):
        return HTTPException(504, str(e))
    if isinstance(e, PortsExhausted):
        return HTTPException(503, str(e))
    return HTTPException(502, str(e))


# ---- agent identity ----
@router.post("/agents/{agent_uid}/certificate")
async def issue_certificate(agent_uid: str, body: CsrRequest, st=Depends(state)):
    s = settings()
    try:
        issued = st.ca.sign_csr(body.csr_pem, agent_uid, days=s.agent_cert_days)
    except CsrRejected as e:
        raise HTTPException(422, str(e)) from e
    old = await st.redis.get(f"vms:agent:{agent_uid}:cert")
    if old is not None:   # re-enrollment replaces the previous cert
        await st.redis.delete(f"vms:certfp:{old.decode() if isinstance(old, bytes) else old}")
    tunnel_secret = secrets.token_urlsafe(32)
    await st.redis.set(f"vms:certfp:{issued.fingerprint_sha256}", agent_uid)
    await st.redis.set(f"vms:agent:{agent_uid}:cert", issued.fingerprint_sha256)
    await st.redis.set(f"vms:agent:{agent_uid}:tunnel_secret", tunnel_secret)
    # New secret (or new device): push the tunnel list again on its first heartbeat
    await st.redis.hset(f"vms:agent:{agent_uid}:state", "needs_resync", "1")
    return {
        "cert_pem": issued.cert_pem,
        "ca_pem": issued.ca_pem,
        "fingerprint": issued.fingerprint_sha256,
        "expires_at": issued.expires_at,
        "frps": {"host": s.frps_public_host, "port": s.frps_public_port, "token": s.frps_token,
                 "tunnel_secret": tunnel_secret},
    }


@router.delete("/agents/{agent_uid}/certificate", status_code=204)
async def revoke(agent_uid: str, st=Depends(state)):
    fp = await st.redis.get(f"vms:agent:{agent_uid}:cert")
    if fp is not None:
        await st.redis.delete(f"vms:certfp:{fp.decode() if isinstance(fp, bytes) else fp}")
    # Tunnel secret gone -> frps plugin rejects its next login and any new proxy
    await st.redis.delete(f"vms:agent:{agent_uid}:cert", f"vms:agent:{agent_uid}:tunnel_secret",
                          f"vms:agent:{agent_uid}:hb")


# ---- credentials (password never reaches the Laravel DB) ----
@router.post("/credentials")
async def put_credentials(body: Credentials, st=Depends(state)):
    return {"credential_ref": await st.creds.put(body.username, body.password)}


@router.delete("/credentials/{ref}", status_code=204)
async def delete_credentials(ref: str, st=Depends(state)):
    await st.creds.delete(ref)


# ---- site LAN ----
@router.post("/agents/{agent_uid}/discover")
async def discover(agent_uid: str, st=Depends(state)):
    if not await st.tunnels.agent_online(agent_uid):
        raise HTTPException(504, "edge agent offline")
    try:
        data = await st.bus.send(agent_uid, "discover", {"timeout": 4}, timeout=15)
    except Exception as e:
        raise _agent_err(e) from e
    return {"devices": data.get("devices", [])}


@router.post("/agents/{agent_uid}/expose")
async def expose(agent_uid: str, body: ExposeRequest, st=Depends(state)):
    """Allocate frps ports for an NVR, push the agent's full proxy list, and record the mapping
    once the agent confirms. Also returns whether each port answered on the LAN."""
    s = settings()
    if not await st.tunnels.agent_online(agent_uid):
        raise HTTPException(504, "edge agent offline")
    desired = f"vms:agent:{agent_uid}:proxies"
    try:
        for p in body.ports:
            remote = await st.ports.allocate(agent_uid, body.lan_host, p.port)
            await st.redis.hset(desired, f"{body.lan_host}:{p.port}", json.dumps(
                {"lan_host": body.lan_host, "lan_port": p.port, "remote_port": remote, "kind": p.kind}))
        proxies = [json.loads(v) for v in (await st.redis.hgetall(desired)).values()]
        data = await st.bus.send(agent_uid, "expose", {
            "proxies": sorted(proxies, key=lambda x: x["remote_port"]),
            "check": [{"host": body.lan_host, "port": p.port} for p in body.ports],
        }, timeout=30)
    except Exception as e:
        raise _agent_err(e) from e

    for px in proxies:
        await st.redis.hset(f"vms:tunnel:{agent_uid}", f"{px['lan_host']}:{px['lan_port']}",
                            f"{s.frps_internal_host}:{px['remote_port']}")
    reachable = data.get("reachable", {})
    return {
        "reachable": {f"{body.lan_host}:{p.port}": bool(reachable.get(f"{body.lan_host}:{p.port}")) for p in body.ports},
        "ports": {str(px["lan_port"]): px["remote_port"] for px in proxies if px["lan_host"] == body.lan_host},
    }
