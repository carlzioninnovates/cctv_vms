"""Upgrade 1: say WHY something is offline. Stdlib only, unit-tested.

Without a UPS signal we can't tell power loss from an internet outage at the moment
it happens. We say 'site_unreachable', then reclassify when the agent reconnects:
if the agent's uptime is shorter than the outage, it rebooted -> power was lost.
"""
from __future__ import annotations

from dataclasses import dataclass
from enum import Enum


class OfflineCause(str, Enum):
    NVR_UNREACHABLE = "nvr_unreachable"
    SITE_INTERNET_DOWN = "site_internet_down"
    POWER_LOSS = "power_loss"
    SITE_UNREACHABLE = "site_unreachable"


# Last thing the agent told us before going quiet
POWER_SIGNALS = {"ups_on_battery", "ups_low_battery", "shutdown"}
UPLINK_SIGNALS = {"uplink_down", "lte_failover_failed"}


@dataclass(frozen=True)
class SiteSignals:
    agent_connected: bool
    nvr_reachable: bool | None = None       # as last reported by the agent
    last_agent_signal: str | None = None


def classify(sig: SiteSignals) -> OfflineCause | None:
    """None means everything is fine."""
    if sig.agent_connected:
        return OfflineCause.NVR_UNREACHABLE if sig.nvr_reachable is False else None
    if sig.last_agent_signal in POWER_SIGNALS:
        return OfflineCause.POWER_LOSS
    if sig.last_agent_signal in UPLINK_SIGNALS:
        return OfflineCause.SITE_INTERNET_DOWN
    return OfflineCause.SITE_UNREACHABLE


def reclassify_on_reconnect(
    cause: OfflineCause, *, agent_uptime_s: float, offline_duration_s: float, grace_s: float = 60
) -> OfflineCause:
    """Called when the agent comes back. Only refines the 'don't know yet' case.
    Caveat: an agent crash/reboot also resets uptime. The agent reports its last
    shutdown reason in its first heartbeat; prefer that when present."""
    if cause is not OfflineCause.SITE_UNREACHABLE:
        return cause
    if agent_uptime_s + grace_s < offline_duration_s:
        return OfflineCause.POWER_LOSS
    return OfflineCause.SITE_INTERNET_DOWN
