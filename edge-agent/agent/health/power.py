"""Upgrade 1: power state from a UPS via NUT (`upsc`). Optional — without a UPS, the cloud
infers power loss from agent uptime after reconnect."""
from __future__ import annotations

import asyncio
import shutil


def parse_upsc(text: str) -> str | None:
    """Map NUT ups.status flags to the signal names the data plane understands."""
    vals = dict(line.split(": ", 1) for line in text.splitlines() if ": " in line)
    flags = set(vals.get("ups.status", "").split())
    if "LB" in flags:
        return "ups_low_battery"
    if "OB" in flags:
        return "ups_on_battery"
    if "OL" in flags:
        return "mains_ok"
    return None


async def read_ups(name: str = "ups@localhost") -> str | None:
    if not shutil.which("upsc"):
        return None
    proc = await asyncio.create_subprocess_exec("upsc", name, stdout=asyncio.subprocess.PIPE,
                                                stderr=asyncio.subprocess.DEVNULL)
    out, _ = await asyncio.wait_for(proc.communicate(), timeout=5)
    return parse_upsc(out.decode()) if proc.returncode == 0 else None
