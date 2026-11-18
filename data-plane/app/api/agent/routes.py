"""Endpoints the edge agent calls (mTLS). Agents only ever dial out."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Query, Response
from pydantic import BaseModel

from app.api.deps import AGENT, state

router = APIRouter(prefix="/agent")


class Heartbeat(BaseModel):
    uptime_s: int
    power: str | None = None           # ups_on_battery, ups_low_battery, mains_ok, shutdown
    uplink: str | None = None          # wired, wifi, lte
    uplink_event: str | None = None    # uplink_down, lte_failover_failed
    buffered_events: int = 0
    version: str | None = None


class CommandResult(BaseModel):
    ok: bool
    data: dict | None = None
    error: str | None = None


@router.post("/heartbeat", status_code=204)
async def heartbeat(hb: Heartbeat, agent_id: str = AGENT, st=Depends(state)):
    await st.presence.heartbeat(agent_id, hb.model_dump())


@router.get("/commands")
async def next_command(wait: int = Query(25, ge=0, le=55), agent_id: str = AGENT, st=Depends(state)):
    cmd = await st.bus.next_for(agent_id, wait)
    return cmd if cmd is not None else Response(status_code=204)


@router.post("/commands/{cmd_id}/result", status_code=204)
async def command_result(cmd_id: str, res: CommandResult, agent_id: str = AGENT, st=Depends(state)):
    if not await st.bus.complete(agent_id, cmd_id, res.model_dump()):
        raise HTTPException(404, "unknown or expired command")
