from __future__ import annotations

from fastapi import Depends, HTTPException, Request

from app.core import security
from app.core.config import settings


async def require_hmac(request: Request) -> None:
    body = await request.body()
    if not security.verify(settings().hmac_secret, request.headers.get("x-vms-signature"), body):
        raise HTTPException(status_code=401, detail="bad signature")


def state(request: Request):
    return request.app.state


HMAC = Depends(require_hmac)


async def require_agent(request: Request) -> str:
    """Edge-agent auth. The TLS proxy verifies the client cert against the agent CA and forwards
    its SHA-256 fingerprint (Caddy: {http.request.tls.client.fingerprint}). NOTE: nginx's
    $ssl_client_fingerprint is SHA-1 and will not match."""
    fp = (request.headers.get("x-client-cert-fingerprint") or "").lower().replace(":", "")
    if not fp:
        raise HTTPException(403, "client certificate required")
    agent = await request.app.state.redis.get(f"vms:certfp:{fp}")
    if agent is None:
        raise HTTPException(403, "unknown or revoked agent certificate")
    return agent.decode() if isinstance(agent, bytes) else agent


AGENT = Depends(require_agent)
