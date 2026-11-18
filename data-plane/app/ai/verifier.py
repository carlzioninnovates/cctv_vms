"""Upgrade 6: cut false alarms by checking the event SNAPSHOT (never the live stream).

Run the model in a separate worker process/GPU box (e.g. YOLO via ONNX Runtime) that
reads from a queue; this module is only the interface so the API stays non-blocking.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

VERIFIABLE = {"motion", "line_crossing", "intrusion", "human", "vehicle"}


@dataclass
class Verdict:
    verdict: str                # confirmed, rejected, skipped
    label: str | None = None
    confidence: float | None = None


class Verifier(Protocol):
    async def verify(self, event_type: str, jpeg: bytes) -> Verdict: ...


class NoopVerifier:
    async def verify(self, event_type: str, jpeg: bytes) -> Verdict:
        return Verdict("skipped")
