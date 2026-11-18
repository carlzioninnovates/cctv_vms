"""HMAC request signing between Laravel and the data plane.
Must match control-plane/app/Services/DataPlane/DataPlaneClient::sign(). Stdlib only."""
from __future__ import annotations

import hashlib
import hmac
import time


def sign(secret: str, timestamp: int, body: bytes) -> str:
    mac = hmac.new(secret.encode(), f"{timestamp}.".encode() + body, hashlib.sha256).hexdigest()
    return f"t={timestamp},v1={mac}"


def verify(secret: str, header: str | None, body: bytes, *, now: float | None = None, tolerance: int = 300) -> bool:
    if not header or not secret:
        return False
    try:
        parts = dict(p.split("=", 1) for p in header.split(","))
        ts = int(parts["t"])
        given = parts["v1"]
    except (KeyError, ValueError):
        return False
    if abs((now if now is not None else time.time()) - ts) > tolerance:
        return False
    expected = sign(secret, ts, body).split("v1=", 1)[1]
    return hmac.compare_digest(expected, given)
