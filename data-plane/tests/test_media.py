"""Snapshot endpoint: signature, expiry, cache sharing, and error mapping.
The signature vector below is produced by PHP SnapshotUrl::make (see control-plane test)."""
from __future__ import annotations

import asyncio
import time

import httpx

from app.api.media import snapshot_sig
from app.media.snapshots import SnapshotCache
from tests.conftest import SECRET

AGENT, LAN = "agent-1", "192.168.1.108"
JPEG = b"\xff\xd8\xff\xe0" + b"x" * 100


def test_signature_matches_php():
    # php -r 'echo hash_hmac("sha256","snap:7:2:1762761600","test-secret");'
    assert snapshot_sig("test-secret", 7, 2, 1762761600) == \
        "68ed29d918e543754c49a64f1ceebb2775735833788d8bc9e0ecb6b0d092841d"


async def setup(dp, handler):
    from app.tunnels.registry import NvrRecord
    ref = await dp.creds.put("admin", "pw")
    await dp.st.tunnels.save_nvr(NvrRecord(nvr_id=1, brand="dahua", agent_id=AGENT, lan_host=LAN, http_port=80,
                                           rtsp_port=554, onvif_port=80, credential_ref=ref))
    await dp.redis.set(f"vms:agent:{AGENT}:hb", "1", ex=45)
    await dp.redis.hset(f"vms:tunnel:{AGENT}", mapping={f"{LAN}:80": "127.0.0.1:1", f"{LAN}:554": "127.0.0.1:2"})
    dp.handler["fn"] = handler


def url(nvr=1, ch=1, exp=None, sig=None):
    exp = exp or int(time.time()) + 600
    return f"/media/snapshot/{nvr}/{ch}?exp={exp}&sig={sig or snapshot_sig(SECRET, nvr, ch, exp)}"


async def test_valid_link_returns_jpeg_and_shares_cache(dp):
    calls = []

    def handler(req):
        calls.append(str(req.url))
        return httpx.Response(200, content=JPEG)

    await setup(dp, handler)
    rs = await asyncio.gather(*(dp.client.get(url()) for _ in range(10)))
    assert all(r.status_code == 200 and r.content == JPEG for r in rs)
    assert rs[0].headers["content-type"] == "image/jpeg"
    assert len(calls) == 1 and calls[0].endswith("/cgi-bin/snapshot.cgi?channel=1")   # 10 viewers, 1 NVR hit


async def test_bad_or_expired_or_other_camera_signature(dp):
    await setup(dp, lambda r: httpx.Response(200, content=JPEG))
    assert (await dp.client.get(url(sig="0" * 64))).status_code == 403
    assert (await dp.client.get(url(exp=int(time.time()) - 1))).status_code == 403
    exp = int(time.time()) + 600
    other = snapshot_sig(SECRET, 1, 2, exp)                       # signed for channel 2
    assert (await dp.client.get(url(ch=1, exp=exp, sig=other))).status_code == 403


async def test_device_errors_map_cleanly(dp):
    await setup(dp, lambda r: httpx.Response(200, content=b"<html>login</html>"))
    assert (await dp.client.get(url())).status_code == 422      # not a JPEG
    await dp.redis.delete(f"vms:agent:{AGENT}:hb")
    assert (await dp.client.get(url(ch=3))).status_code == 504  # agent offline
    assert (await dp.client.get(url(nvr=99))).status_code == 404


async def test_cache_expires():
    n = 0

    async def fetch():
        nonlocal n
        n += 1
        return JPEG

    c = SnapshotCache(ttl_s=0.05)
    await c.get(1, 1, fetch)
    await c.get(1, 1, fetch)
    assert n == 1
    await asyncio.sleep(0.06)
    await c.get(1, 1, fetch)
    assert n == 2


async def test_cache_is_bounded():
    c = SnapshotCache(max_entries=3)

    async def fetch():
        return JPEG

    for ch in range(1, 6):
        await c.get(1, ch, fetch)
    assert len(c._data) == 3 and (1, 1) not in c._data
