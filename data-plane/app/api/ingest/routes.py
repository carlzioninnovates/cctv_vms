"""Event batches from edge agents. mTLS is terminated at the proxy, which forwards the
client cert fingerprint; require_agent maps it to the agent_id."""
from __future__ import annotations

from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.api.deps import AGENT, state
from app.drivers.models import Brand
from app.events.normalizer import normalize

router = APIRouter(prefix="/ingest")


class RawEvent(BaseModel):
    nvr_id: int
    brand: Brand
    raw_type: str
    raw_state: str | None = None
    channel: int | None = None
    occurred_at: datetime
    target: str | None = None
    event_id: str | None = None      # set by agent so resends are idempotent
    buffered: bool = False


class Batch(BaseModel):
    agent_id: str
    events: list[RawEvent] = Field(max_length=500)


@router.post("/events", status_code=202)
async def ingest(batch: Batch, st=Depends(state), agent_id: str = AGENT):
    if batch.agent_id != agent_id:
        raise HTTPException(403, "agent certificate mismatch")
    out, rejected = [], 0
    for r in batch.events:
        try:
            out.append(normalize(nvr_id=r.nvr_id, brand=r.brand, raw_type=r.raw_type, raw_state=r.raw_state,
                                 channel=r.channel, occurred_at=r.occurred_at, target=r.target,
                                 buffered=r.buffered, event_id=r.event_id))
        except ValueError:
            rejected += 1
    await st.events.publish_many(out)
    return {"accepted": len(out), "rejected": rejected}
