"""Endpoints Laravel calls. All HMAC-signed. Hardware errors map to clean HTTP codes."""
from __future__ import annotations

import asyncio
from datetime import datetime
from typing import Literal

from fastapi import APIRouter, Depends, HTTPException, Response
from pydantic import BaseModel, Field

from app.api.deps import HMAC, state
from app.drivers.models import Codec, DeviceAuthError, DeviceUnreachable, NotSupported, Stream
from app.drivers.onvif_generic import OnvifDriver
from app.drivers.probe import probe
from app.drivers.registry import driver_for
from app.tunnels.registry import NvrRecord

router = APIRouter(prefix="/internal", dependencies=[HMAC])


class ProbeRequest(BaseModel):
    brand: Literal["hikvision", "dahua", "uniview", "onvif"]
    credential_ref: str
    agent_id: str
    lan_host: str
    http_port: int = 80
    rtsp_port: int = 554
    onvif_port: int = 80
    device_tz: str = "Asia/Manila"


class LiveRequest(BaseModel):
    stream: Literal["main", "sub"] = "sub"


class PlaybackRequest(BaseModel):
    start: datetime
    end: datetime


class PtzRequest(BaseModel):
    action: Literal["up", "down", "left", "right", "zoom_in", "zoom_out", "stop"]
    speed: int = Field(4, ge=1, le=7)


def _map_errors(e: Exception) -> HTTPException:
    if isinstance(e, DeviceAuthError):
        return HTTPException(401, "device rejected credentials")
    if isinstance(e, DeviceUnreachable | asyncio.TimeoutError):
        return HTTPException(504, str(e) or "device unreachable")
    if isinstance(e, NotSupported):
        return HTTPException(501, str(e))
    if isinstance(e, KeyError):
        return HTTPException(404, str(e))
    if isinstance(e, ValueError):
        return HTTPException(422, str(e))
    return HTTPException(502, f"device error: {type(e).__name__}")


async def _driver(st, nvr_id: int):
    rec = await st.tunnels.nvr(nvr_id)
    conn = await st.tunnels.connection(rec)
    return rec, driver_for(conn, st.http)


@router.post("/nvrs/{nvr_id}/probe")
async def probe_nvr(nvr_id: int, body: ProbeRequest, st=Depends(state)):
    rec = NvrRecord(nvr_id=nvr_id, **body.model_dump())
    try:
        conn = await st.tunnels.connection(rec)
        caps = await asyncio.wait_for(probe(conn, st.http), timeout=55)
    except Exception as e:
        raise _map_errors(e) from e
    rec.channel_codecs = {str(c.number): {"main": c.main_codec.value, "sub": c.sub_codec.value}
                          for c in caps.channels}
    await st.tunnels.save_nvr(rec)
    return caps.to_dict()


@router.post("/nvrs/{nvr_id}/channels/{channel}/live")
async def live(nvr_id: int, channel: int, body: LiveRequest, st=Depends(state)):
    try:
        rec, drv = await _driver(st, nvr_id)
        stream = Stream(body.stream)
        if type(drv) is OnvifDriver:  # generic ONVIF: ask the device for its URI
            src = await drv.stream_uri(channel, stream)
            await drv.close()
        else:
            src = drv.live_url(channel, stream)
        name = st.media.path_name("live", nvr_id, channel, body.stream)
        await st.media.ensure_path(name, src)
    except Exception as e:
        raise _map_errors(e) from e
    codec = Codec((rec.channel_codecs or {}).get(str(channel), {}).get(body.stream, "unknown"))
    return {**st.media.urls(name), "browser_playable_webrtc": codec.webrtc_safe}


@router.post("/nvrs/{nvr_id}/channels/{channel}/playback")
async def playback(nvr_id: int, channel: int, body: PlaybackRequest, st=Depends(state)):
    try:
        _, drv = await _driver(st, nvr_id)
        src = drv.playback_url(channel, body.start, body.end)
        name = st.media.path_name("pb", nvr_id, channel, f"{body.start.isoformat()}-{body.end.isoformat()}")
        await st.media.ensure_path(name, src, close_after="5s")
    except Exception as e:
        raise _map_errors(e) from e
    return {**st.media.urls(name), "browser_playable_webrtc": False}


@router.get("/nvrs/{nvr_id}/channels/{channel}/snapshot")
async def snapshot(nvr_id: int, channel: int, st=Depends(state)):
    try:
        _, drv = await _driver(st, nvr_id)
        jpeg = await drv.snapshot(channel)
    except Exception as e:
        raise _map_errors(e) from e
    return Response(jpeg, media_type="image/jpeg", headers={"Cache-Control": "max-age=2"})


@router.post("/nvrs/{nvr_id}/channels/{channel}/ptz", status_code=204)
async def ptz(nvr_id: int, channel: int, body: PtzRequest, st=Depends(state)):
    try:
        _, drv = await _driver(st, nvr_id)
        await drv.ptz(channel, body.action, body.speed)
    except Exception as e:
        raise _map_errors(e) from e


@router.get("/nvrs/{nvr_id}/health")
async def health(nvr_id: int, st=Depends(state)):
    try:
        _, drv = await _driver(st, nvr_id)
        h = await drv.health()
    except Exception as e:
        raise _map_errors(e) from e
    return {"disks": [d.__dict__ for d in h.disks], "recording_channels": h.recording_channels,
            "checked_at": h.checked_at}
