"""Onboarding through the real routes: cert issuance, heartbeat/presence, command channel,
discovery, tunnel exposure, frps plugin checks, and revocation. A fake edge agent long-polls
for commands exactly like the real one."""
from __future__ import annotations

import asyncio
import json
import time

import pytest
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

from tests.conftest import signed

UID = "6f1c2a7e-0000-4000-8000-000000000001"
OTHER = "6f1c2a7e-0000-4000-8000-000000000002"


def make_csr(cn: str = "anything") -> str:
    key = ec.generate_private_key(ec.SECP256R1())
    csr = (x509.CertificateSigningRequestBuilder()
           .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, cn)]))
           .sign(key, hashes.SHA256()))
    return csr.public_bytes(serialization.Encoding.PEM).decode()


async def enroll(dp, uid=UID) -> dict:
    r = await signed(dp.client, "POST", f"/internal/agents/{uid}/certificate", {"csr_pem": make_csr("evil-other-id")})
    assert r.status_code == 200, r.text
    return r.json()


def agent_headers(fp: str) -> dict:
    return {"X-Client-Cert-Fingerprint": fp}


class FakeAgent:
    """Long-polls /agent/commands and answers like the real edge agent."""

    def __init__(self, dp, fp: str, reachable: bool = True):
        self.dp, self.h, self.reachable = dp, agent_headers(fp), reachable
        self.received: list[dict] = []
        self.task: asyncio.Task | None = None

    async def run(self):
        while True:
            r = await self.dp.client.get("/agent/commands?wait=2", headers=self.h)
            if r.status_code == 204:
                continue
            cmd = r.json()
            self.received.append(cmd)
            if cmd["type"] == "discover":
                data = {"devices": [{"ip": "192.168.1.108", "brand_guess": "dahua", "hardware": "NVR4108HS"}]}
            elif cmd["type"] == "expose":
                data = {"reachable": {f"{c['host']}:{c['port']}": self.reachable for c in cmd["payload"]["check"]}}
            else:
                data = {}
            await self.dp.client.post(f"/agent/commands/{cmd['id']}/result", json={"ok": True, "data": data},
                                      headers=self.h)

    async def __aenter__(self):
        await self.dp.client.post("/agent/heartbeat", json={"uptime_s": 100}, headers=self.h)
        self.task = asyncio.create_task(self.run())
        return self

    async def __aexit__(self, *exc):
        self.task.cancel()
        try:
            await self.task
        except asyncio.CancelledError:
            pass


async def test_certificate_is_bound_to_agent_uid(dp):
    out = await enroll(dp)
    cert = x509.load_pem_x509_certificate(out["cert_pem"].encode())
    assert cert.subject.get_attributes_for_oid(NameOID.COMMON_NAME)[0].value == UID   # CSR's CN ignored
    assert out["frps"]["tunnel_secret"] and out["frps"]["token"] == "frps-shared"
    assert out["ca_pem"].startswith("-----BEGIN CERTIFICATE-----")
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5}, headers=agent_headers(out["fingerprint"]))
    assert r.status_code == 204


async def test_bad_csr_and_unknown_cert(dp):
    r = await signed(dp.client, "POST", f"/internal/agents/{UID}/certificate", {"csr_pem": "nope"})
    assert r.status_code == 422
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5}, headers=agent_headers("deadbeef"))
    assert r.status_code == 403
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5})
    assert r.status_code == 403


async def test_reenroll_invalidates_old_cert(dp):
    first = await enroll(dp)
    second = await enroll(dp)
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5}, headers=agent_headers(first["fingerprint"]))
    assert r.status_code == 403
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5}, headers=agent_headers(second["fingerprint"]))
    assert r.status_code == 204


async def test_discover_round_trip(dp):
    out = await enroll(dp)
    async with FakeAgent(dp, out["fingerprint"]):
        r = await signed(dp.client, "POST", f"/internal/agents/{UID}/discover")
    assert r.status_code == 200, r.text
    assert r.json()["devices"][0]["brand_guess"] == "dahua"


async def test_discover_agent_offline(dp):
    await enroll(dp)
    r = await signed(dp.client, "POST", f"/internal/agents/{UID}/discover")
    assert r.status_code == 504


async def test_expose_allocates_stable_ports_and_maps_tunnel(dp):
    out = await enroll(dp)
    body = {"lan_host": "192.168.1.108", "ports": [{"port": 80, "kind": "http"}, {"port": 554, "kind": "rtsp"}]}
    async with FakeAgent(dp, out["fingerprint"]) as agent:
        r = await signed(dp.client, "POST", f"/internal/agents/{UID}/expose", body)
        assert r.status_code == 200, r.text
        first = r.json()
        assert first["reachable"] == {"192.168.1.108:80": True, "192.168.1.108:554": True}
        assert first["ports"] == {"80": 40000, "554": 40001}

        # Second NVR on the same site: agent gets the FULL list, first NVR keeps its ports
        r = await signed(dp.client, "POST", f"/internal/agents/{UID}/expose",
                         {"lan_host": "192.168.1.109", "ports": [{"port": 554, "kind": "rtsp"}]})
        assert r.json()["ports"] == {"554": 40002}
        assert len(agent.received[-1]["payload"]["proxies"]) == 3

        # Re-expose first NVR: same ports
        r = await signed(dp.client, "POST", f"/internal/agents/{UID}/expose", body)
        assert r.json()["ports"] == {"80": 40000, "554": 40001}

    tunnel = {k.decode(): v.decode() for k, v in (await dp.redis.hgetall(f"vms:tunnel:{UID}")).items()}
    assert tunnel["192.168.1.108:554"] == "172.30.0.10:40001"


async def test_expose_reports_unreachable_nvr(dp):
    out = await enroll(dp)
    async with FakeAgent(dp, out["fingerprint"], reachable=False):
        r = await signed(dp.client, "POST", f"/internal/agents/{UID}/expose",
                         {"lan_host": "192.168.1.200", "ports": [{"port": 80, "kind": "http"}]})
    assert r.json()["reachable"] == {"192.168.1.200:80": False}


async def test_expose_rejects_bad_host(dp):
    r = await signed(dp.client, "POST", f"/internal/agents/{UID}/expose",
                     {"lan_host": "1.2.3.4; rm -rf /", "ports": [{"port": 80, "kind": "http"}]})
    assert r.status_code == 422


async def test_command_result_from_wrong_agent_rejected(dp):
    a = await enroll(dp, UID)
    b = await enroll(dp, OTHER)
    await dp.client.post("/agent/heartbeat", json={"uptime_s": 1}, headers=agent_headers(a["fingerprint"]))
    send = asyncio.create_task(dp.st.bus.send(UID, "discover", {}, timeout=3))
    r = await dp.client.get("/agent/commands?wait=2", headers=agent_headers(a["fingerprint"]))
    cmd_id = r.json()["id"]
    r = await dp.client.post(f"/agent/commands/{cmd_id}/result", json={"ok": True, "data": {"devices": ["fake"]}},
                             headers=agent_headers(b["fingerprint"]))
    assert r.status_code == 404
    r = await dp.client.post(f"/agent/commands/{cmd_id}/result", json={"ok": True, "data": {"devices": []}},
                             headers=agent_headers(a["fingerprint"]))
    assert r.status_code == 204
    assert (await send) == {"devices": []}


async def test_frps_plugin_login_newproxy_and_revoke(dp, caplog):
    a = await enroll(dp, UID)
    b = await enroll(dp, OTHER)
    pa = await dp.st.ports.allocate(UID, "192.168.1.108", 554)
    pb = await dp.st.ports.allocate(OTHER, "192.168.1.50", 554)
    metas = {"agent_uid": UID, "secret": a["frps"]["tunnel_secret"]}

    async def plugin(op, content):
        r = await dp.client.post(f"/frps/plugin?op={op}", json={"version": "0.1.0", "op": op, "content": content})
        return r.json()

    assert (await plugin("Login", {"metas": metas}))["reject"] is False
    assert (await plugin("Login", {"metas": {"agent_uid": UID, "secret": "guess"}}))["reject"] is True
    ok = {"user": {"metas": metas}, "proxy_type": "tcp", "remote_port": pa}
    assert (await plugin("NewProxy", ok))["reject"] is False
    # Agent A tries to grab agent B's port -> rejected
    assert (await plugin("NewProxy", {**ok, "remote_port": pb}))["reject"] is True
    assert any("rejected op=NewProxy" in r.message and f"remote_port={pb}" in r.message and UID in r.message
               for r in caplog.records)                                      # security trail
    assert (await plugin("NewProxy", {**ok, "proxy_type": "http"}))["reject"] is True

    ping = {"user": {"metas": metas}, "timestamp": 1}
    assert (await plugin("Ping", ping))["reject"] is False
    assert (await plugin("NewWorkConn", ping))["reject"] is False

    r = await signed(dp.client, "DELETE", f"/internal/agents/{UID}/certificate")
    assert r.status_code == 204
    assert (await plugin("Login", {"metas": metas}))["reject"] is True
    # Open sessions are cut too: next heartbeat and any new tunnel connection are refused
    assert (await plugin("Ping", ping))["reject"] is True
    assert (await plugin("NewWorkConn", ping))["reject"] is True
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5}, headers=agent_headers(a["fingerprint"]))
    assert r.status_code == 403
    assert b  # other agent unaffected
    r = await dp.client.post("/agent/heartbeat", json={"uptime_s": 5}, headers=agent_headers(b["fingerprint"]))
    assert r.status_code == 204


async def test_credentials_store_round_trip(dp):
    r = await signed(dp.client, "POST", "/internal/credentials", {"username": "admin", "password": "p@ss"})
    ref = r.json()["credential_ref"]
    assert await dp.creds.get(ref) == ("admin", "p@ss")
    raw = await dp.redis.get(f"vms:cred:{ref}")
    assert b"p@ss" not in raw                                                       # encrypted at rest
    await signed(dp.client, "DELETE", f"/internal/credentials/{ref}")
    with pytest.raises(KeyError):
        await dp.creds.get(ref)


async def _status_events(dp):
    return [json.loads(f[b"event"]) for _, f in await dp.redis.xrange("vms:agent-status")]


async def test_presence_offline_then_power_loss_on_reconnect(dp):
    p = dp.st.presence
    t0 = time.time()
    await p.heartbeat(UID, {"uptime_s": 1000}, now=t0)
    assert (await _status_events(dp))[-1]["status"] == "online"
    assert await p.sweep(now=t0 + 30) == []
    assert await p.sweep(now=t0 + 60) == [UID]
    assert await p.sweep(now=t0 + 90) == []                                         # not re-announced
    off = (await _status_events(dp))[-1]
    assert off["status"] == "offline" and off["cause"] == "site_unreachable"
    # Back after 2h with 5 min uptime -> it rebooted -> power loss
    await p.heartbeat(UID, {"uptime_s": 300}, now=t0 + 7200)
    back = (await _status_events(dp))[-1]
    assert back["status"] == "online" and back["cause"] == "power_loss"


async def test_presence_ups_signal_means_power_loss_immediately(dp):
    p = dp.st.presence
    t0 = time.time()
    await p.heartbeat(UID, {"uptime_s": 1000, "power": "ups_low_battery"}, now=t0)
    await p.sweep(now=t0 + 60)
    assert (await _status_events(dp))[-1]["cause"] == "power_loss"


async def test_tunnels_restored_after_reenrollment(dp):
    """Regression: after re-enrollment (new secret / replacement device) nothing re-sent the tunnels."""
    out = await enroll(dp)
    async with FakeAgent(dp, out["fingerprint"]):
        await signed(dp.client, "POST", f"/internal/agents/{UID}/expose",
                     {"lan_host": "192.168.1.108", "ports": [{"port": 554, "kind": "rtsp"}]})
    await dp.redis.delete(f"vms:agent:{UID}:cmds")

    new = await enroll(dp)                                       # "Replace agent" -> new cert + secret
    assert new["frps"]["tunnel_secret"] != out["frps"]["tunnel_secret"]
    await dp.client.post("/agent/heartbeat", json={"uptime_s": 3}, headers=agent_headers(new["fingerprint"]))
    r = await dp.client.get("/agent/commands?wait=1", headers=agent_headers(new["fingerprint"]))
    cmd = r.json()
    assert cmd["type"] == "expose"
    assert cmd["payload"]["proxies"] == [{"lan_host": "192.168.1.108", "lan_port": 554, "remote_port": 40000, "kind": "rtsp"}]

    # Only once: the next heartbeat doesn't queue it again
    await dp.client.post("/agent/heartbeat", json={"uptime_s": 18}, headers=agent_headers(new["fingerprint"]))
    r = await dp.client.get("/agent/commands?wait=1", headers=agent_headers(new["fingerprint"]))
    assert r.status_code == 204
