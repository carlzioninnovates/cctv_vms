"""End-to-end through the FastAPI app with a fake Dahua NVR, fake MediaMTX and in-memory Redis.
Covers: HMAC auth, probe, live provisioning, event ingest, offline agent, and no-retry on 401."""
from __future__ import annotations

import json
from datetime import datetime
from zoneinfo import ZoneInfo

import httpx
import pytest

from tests.conftest import signed

AGENT = "agent-1"
LAN = "192.168.1.108"
# Port 1 on localhost refuses instantly, so the ONVIF check (its own HTTP client) fails fast.
TUNNEL = "127.0.0.1"


class FakeWorld:
    def __init__(self):
        self.device_calls: list[str] = []
        self.mediamtx_paths: dict[str, dict] = {}
        self.reject_auth = False

    def handler(self, req: httpx.Request) -> httpx.Response:
        url = str(req.url)
        if "/v3/config/paths/patch/" in url:
            name = req.url.path.split("/patch/", 1)[1]
            if name not in self.mediamtx_paths:
                return httpx.Response(404)
            self.mediamtx_paths[name] = json.loads(req.content)
            return httpx.Response(200)
        if "/v3/config/paths/add/" in url:
            self.mediamtx_paths[req.url.path.split("/add/", 1)[1]] = json.loads(req.content)
            return httpx.Response(200)
        self.device_calls.append(url)
        if self.reject_auth:
            return httpx.Response(401)
        q = req.url.params
        p = req.url.path
        if p == "/cgi-bin/magicBox.cgi" and q["action"] == "getSystemInfo":
            return _kv("deviceType=DHI-NVR4108HS-8P-4KS2", "serialNumber=7J0ABC123")
        if p == "/cgi-bin/magicBox.cgi" and q["action"] == "getSoftwareVersion":
            return _kv("version=4.001.0000000.3.R,build:2023-01-01")
        if p == "/cgi-bin/configManager.cgi" and q["name"] == "ChannelTitle":
            return _kv("table.ChannelTitle[0].Name=Gate", "table.ChannelTitle[1].Name=Lobby")
        if p == "/cgi-bin/configManager.cgi" and q["name"] == "Encode":
            return _kv(*[f"table.Encode[{i}].{s}Format[0].Video.Compression={c}"
                         for i in (0, 1) for s, c in (("Main", "H.265"), ("Extra", "H.264"))])
        if p == "/cgi-bin/storageDevice.cgi":
            return _kv("list.info[0].Name=/dev/sda", "list.info[0].State=Success",
                       "list.info[0].Detail[0].TotalBytes=4000000000000.000000",
                       "list.info[0].Detail[0].UsedBytes=1000000000000.000000")
        if p == "/cgi-bin/global.cgi":
            now = datetime.now(ZoneInfo("Asia/Manila")).strftime("%Y-%m-%d %H:%M:%S")
            return _kv(f"result={now}")
        return httpx.Response(404)


def _kv(*lines: str) -> httpx.Response:
    return httpx.Response(200, text="\r\n".join(lines) + "\r\n")


@pytest.fixture
async def env(dp):
    world = FakeWorld()
    dp.handler["fn"] = world.handler
    ref = await dp.creds.put("admin", "p@ss#1")
    await dp.redis.set(f"vms:agent:{AGENT}:hb", "1", ex=45)
    await dp.redis.hset(f"vms:tunnel:{AGENT}", mapping={f"{LAN}:80": f"{TUNNEL}:1", f"{LAN}:554": f"{TUNNEL}:40554"})
    await dp.redis.set("vms:certfp:fp-abc", AGENT)
    return dp.client, world, dp.redis, ref


def probe_body(ref):
    return {"brand": "dahua", "credential_ref": ref, "agent_id": AGENT, "lan_host": LAN}


async def test_unsigned_request_rejected(env):
    client, *_ = env
    r = await client.post("/internal/nvrs/1/probe", json={})
    assert r.status_code == 401


async def test_probe_then_live(env):
    client, world, _redis, ref = env

    r = await signed(client, "POST", "/internal/nvrs/1/probe", probe_body(ref))
    assert r.status_code == 200, r.text
    caps = r.json()
    assert caps["model"] == "DHI-NVR4108HS-8P-4KS2" and caps["serial"] == "7J0ABC123"
    assert [c["number"] for c in caps["channels"]] == [1, 2]          # Dahua 0-based -> 1-based
    assert caps["channels"][0]["name"] == "Gate"
    assert caps["channels"][0]["main_codec"] == "h265" and caps["channels"][0]["sub_codec"] == "h264"
    assert caps["hdd_health"] is True and caps["vendor_api"] is True
    assert caps["onvif"] is False
    assert any("H.265" in w for w in caps["warnings"])
    assert abs(caps["clock_drift_seconds"]) < 5

    r = await signed(client, "POST", "/internal/nvrs/1/channels/1/live", {"stream": "sub"})
    assert r.status_code == 200, r.text
    out = r.json()
    assert out["browser_playable_webrtc"] is True                     # H.264 substream
    assert out["whep_url"].startswith("https://media.test/live/1/1/") and out["whep_url"].endswith("/whep")
    conf = world.mediamtx_paths[out["path"]]
    assert conf["source"] == "rtsp://admin:p%40ss%231@127.0.0.1:40554/cam/realmonitor?channel=1&subtype=1"
    assert conf["sourceOnDemand"] is True and conf["rtspTransport"] == "tcp"

    r = await signed(client, "POST", "/internal/nvrs/1/channels/1/live", {"stream": "main"})
    assert r.json()["browser_playable_webrtc"] is False               # H.265 main -> client uses HLS

    # Repeat view of the same stream: updated in place, not re-added
    n = len(world.mediamtx_paths)
    r = await signed(client, "POST", "/internal/nvrs/1/channels/1/live", {"stream": "sub"})
    assert r.status_code == 200 and len(world.mediamtx_paths) == n


async def test_agent_offline_gives_504(env):
    client, _, redis, ref = env
    await signed(client, "POST", "/internal/nvrs/1/probe", probe_body(ref))
    await redis.delete(f"vms:agent:{AGENT}:hb")
    r = await signed(client, "POST", "/internal/nvrs/1/channels/1/live", {"stream": "sub"})
    assert r.status_code == 504


async def test_bad_credentials_not_retried(env):
    client, world, _, ref = env
    world.reject_auth = True
    r = await signed(client, "POST", "/internal/nvrs/1/probe", probe_body(ref))
    assert r.status_code == 401
    assert len(world.device_calls) == 1                               # never hammer a locked NVR


async def test_unknown_nvr_404(env):
    client, *_ = env
    r = await signed(client, "POST", "/internal/nvrs/99/channels/1/live", {"stream": "sub"})
    assert r.status_code == 404


async def test_ingest_events(env):
    client, _, redis, _ = env
    batch = {"agent_id": AGENT, "events": [
        {"nvr_id": 1, "brand": "dahua", "raw_type": "CrossLineDetection", "raw_state": "Start", "channel": 2,
         "occurred_at": "2026-11-10T20:00:00+08:00", "event_id": "e-1"},
        {"nvr_id": 1, "brand": "dahua", "raw_type": "VideoMotion", "channel": 0,
         "occurred_at": "2026-11-10T20:00:00+08:00"},                 # bad 0-based channel -> rejected
    ]}
    r = await client.post("/ingest/events", json=batch, headers={"X-Client-Cert-Fingerprint": "wrong"})
    assert r.status_code == 403
    r = await client.post("/ingest/events", json=batch, headers={"X-Client-Cert-Fingerprint": "fp-abc"})
    assert r.status_code == 202 and r.json() == {"accepted": 1, "rejected": 1}
    entries = await redis.xrange("vms:events")
    ev = json.loads(entries[0][1][b"event"])
    assert ev["type"] == "line_crossing" and ev["channel"] == 2 and ev["occurred_at"] == "2026-11-10T12:00:00Z"
