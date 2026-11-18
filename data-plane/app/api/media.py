"""Public media endpoints (browser -> data plane). Auth is a signed URL from Laravel
(control-plane/app/Services/Media/SnapshotUrl.php), so this needs no session."""
from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import APIRouter, Depends, HTTPException, Query, Response

from app.api.deps import state
from app.api.internal.routes import _map_errors
from app.core.config import settings
from app.drivers.registry import driver_for

router = APIRouter(prefix="/media")


def snapshot_sig(secret: str, nvr_id: int, channel: int, exp: int) -> str:
    return hmac.new(secret.encode(), f"snap:{nvr_id}:{channel}:{exp}".encode(), hashlib.sha256).hexdigest()


@router.get("/snapshot/{nvr_id}/{channel}")
async def snapshot(nvr_id: int, channel: int, exp: int = Query(...), sig: str = Query(..., max_length=128),
                   st=Depends(state)):
    if exp < time.time() or not hmac.compare_digest(snapshot_sig(settings().hmac_secret, nvr_id, channel, exp), sig):
        raise HTTPException(403, "link expired or invalid")

    async def fetch() -> bytes:
        rec = await st.tunnels.nvr(nvr_id)
        drv = driver_for(await st.tunnels.connection(rec), st.http)
        try:
            return await drv.snapshot(channel)
        finally:
            if hasattr(drv, "close"):
                await drv.close()

    try:
        jpeg = await st.snapshots.get(nvr_id, channel, fetch)
    except Exception as e:
        raise _map_errors(e) from e
    return Response(jpeg, media_type="image/jpeg",
                    headers={"Cache-Control": "private, max-age=2", "X-Content-Type-Options": "nosniff"})
