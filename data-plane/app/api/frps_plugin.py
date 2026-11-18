"""frps server plugin. Without it, any agent holding the shared frps token could log in after
revocation, or bind ANOTHER customer's remote port and intercept their NVR traffic.

  Login        valid agent + secret
  NewProxy     + the remote port is allocated to this agent, tcp only
  Ping         re-checked every heartbeat (15 s): a revoked agent is disconnected
  NewWorkConn  re-checked for every new connection through the tunnel: after revocation no new
               video flows even before the next heartbeat

Reachable only on the backend network; the public proxy must not route /frps/."""
from __future__ import annotations

import hmac
import logging

from fastapi import APIRouter, Depends, Query, Request

from app.api.deps import state

router = APIRouter(prefix="/frps")
log = logging.getLogger("vms.security")


def _reject(reason: str, op: str = "", content: dict | None = None) -> dict:
    # Every refusal is a possible attack (stolen device, port theft): keep a trail for incident review.
    c = content or {}
    metas = c.get("metas") or (c.get("user") or {}).get("metas") or {}
    log.warning("frps plugin rejected op=%s agent=%s remote_port=%s from=%s reason=%s", op,
                metas.get("agent_uid", "?"), c.get("remote_port", "-"),
                c.get("client_address") or (c.get("user") or {}).get("client_address", "?"), reason)
    return {"reject": True, "reject_reason": reason}


OK = {"reject": False, "unchange": True}


async def _agent_ok(st, metas: dict) -> str | None:
    uid, given = metas.get("agent_uid"), metas.get("secret")
    if not uid or not given:
        return None
    expected = await st.redis.get(f"vms:agent:{uid}:tunnel_secret")
    if expected is None:
        return None
    expected = expected.decode() if isinstance(expected, bytes) else expected
    return uid if hmac.compare_digest(expected, given) else None


@router.post("/plugin")
async def plugin(request: Request, op: str = Query(...), st=Depends(state)):
    body = await request.json()
    content = body.get("content", {})
    if op == "Login":
        return OK if await _agent_ok(st, content.get("metas") or {}) else _reject("unknown or revoked agent", op, content)
    if op == "NewProxy":
        user = content.get("user") or {}
        uid = await _agent_ok(st, user.get("metas") or {})
        if uid is None:
            return _reject("unknown or revoked agent", op, content)
        if content.get("proxy_type") != "tcp":
            return _reject("only tcp proxies allowed", op, content)
        port = content.get("remote_port")
        allocated = {int(v) for k, v in (await st.redis.hgetall("vms:ports:map")).items()
                     if (k.decode() if isinstance(k, bytes) else k).startswith(f"{uid}|")}
        return OK if port in allocated else _reject(f"remote port {port} not allocated to this agent", op, content)
    if op in ("Ping", "NewWorkConn"):
        user = content.get("user") or {}
        return OK if await _agent_ok(st, user.get("metas") or {}) else _reject("agent revoked", op, content)
    return OK
