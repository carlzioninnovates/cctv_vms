import asyncio
import json
import stat
import tomllib

import httpx
import pytest
from cryptography import x509

from agent.commands import CommandHandler, tcp_reachable
from agent.config import AgentConfig
from agent.enroll import enroll, normalize_code
from agent.tunnel.frpc import render

FRPS = {"host": "tunnel.example.ph", "port": 7000, "token": "shared", "tunnel_secret": "s3cr3t"}
UID = "6f1c2a7e-0000-4000-8000-000000000001"


def test_render_frpc_is_valid_toml():
    text = render(UID, FRPS, [
        {"lan_host": "192.168.1.108", "lan_port": 80, "remote_port": 40000, "kind": "http"},
        {"lan_host": "192.168.1.108", "lan_port": 554, "remote_port": 40001, "kind": "rtsp"},
    ], "/etc/vms-agent")
    conf = tomllib.loads(text)
    assert conf["metadatas"] == {"agent_uid": UID, "secret": "s3cr3t"}
    assert [p["remotePort"] for p in conf["proxies"]] == [40000, 40001]
    assert conf["proxies"][1]["transport"]["useCompression"] is False      # don't compress video
    assert conf["webServer"]["addr"] == "127.0.0.1"


@pytest.mark.parametrize("bad", [
    {"lan_host": '1.2.3.4"\nserverAddr = "evil', "lan_port": 80, "remote_port": 40000},
    {"lan_host": "192.168.1.1", "lan_port": 0, "remote_port": 40000},
    {"lan_host": "192.168.1.1", "lan_port": 80, "remote_port": 40000, "kind": "udp"},
])
def test_render_rejects_injection(bad):
    with pytest.raises(ValueError):
        render(UID, FRPS, [bad], "/etc/vms-agent")


def test_normalize_code():
    assert normalize_code("abcd efgh") == "ABCD-EFGH"
    assert normalize_code("ABCD-EFGH") == "ABCD-EFGH"
    with pytest.raises(ValueError):
        normalize_code("ABC")


async def test_enroll_writes_files_and_keeps_key_local(tmp_path):
    sent = {}

    def handler(req: httpx.Request):
        sent.update(json.loads(req.content))
        csr = x509.load_pem_x509_csr(sent["csr_pem"].encode())
        assert csr.is_signature_valid
        return httpx.Response(200, json={
            "agent_uid": UID, "cert_pem": "CERT", "ca_pem": "CA",
            "agent_url": "https://agent.example.ph", "frps": FRPS})

    async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as c:
        cfg = await enroll("abcd-efgh", "https://app.example.ph", c, tmp_path)
    assert sent["code"] == "ABCD-EFGH"
    assert "PRIVATE KEY" not in json.dumps(sent)                            # key never sent
    assert "PRIVATE KEY" in (tmp_path / "agent.key").read_text()
    assert stat.S_IMODE((tmp_path / "agent.key").stat().st_mode) == 0o600
    assert AgentConfig.load(tmp_path) == cfg and cfg.frps["tunnel_secret"] == "s3cr3t"


async def test_enroll_bad_code(tmp_path):
    t = httpx.MockTransport(lambda r: httpx.Response(422, json={"message": "code expired"}))
    async with httpx.AsyncClient(transport=t) as c:
        with pytest.raises(SystemExit, match="code expired"):
            await enroll("ABCD-EFGH", "https://x", c, tmp_path)
    assert not (tmp_path / "agent.key").exists()


async def test_tcp_reachable():
    server = await asyncio.start_server(lambda r, w: w.close(), "127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]
    assert await tcp_reachable("127.0.0.1", port)
    server.close()
    await server.wait_closed()
    assert not await tcp_reachable("127.0.0.1", port, timeout=1)


class FakeFrpc:
    def __init__(self):
        self.applied = None

    async def apply(self, uid, frps, proxies):
        self.applied = proxies


async def test_expose_command_checks_lan_and_applies_tunnel():
    server = await asyncio.start_server(lambda r, w: w.close(), "127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]
    frpc = FakeFrpc()
    h = CommandHandler(UID, FRPS, frpc)
    proxies = [{"lan_host": "127.0.0.1", "lan_port": port, "remote_port": 40000, "kind": "http"}]
    res = await h.handle({"id": "1", "type": "expose", "payload": {
        "proxies": proxies, "check": [{"host": "127.0.0.1", "port": port}, {"host": "127.0.0.1", "port": 1}]}})
    server.close()
    assert res == {"ok": True, "data": {"reachable": {f"127.0.0.1:{port}": True, "127.0.0.1:1": False}}}
    assert frpc.applied == proxies


async def test_unknown_command_and_errors_reported():
    h = CommandHandler(UID, FRPS, FakeFrpc())
    assert (await h.handle({"id": "1", "type": "format_disk"}))["ok"] is False
    res = await h.handle({"id": "2", "type": "check_tcp", "payload": {}})
    assert res["ok"] is False and "KeyError" in res["error"]


async def test_frpc_supervisor_stops_child(tmp_path):
    """Regression: the agent left frpc running after it exited."""
    from agent.tunnel.frpc import FrpcSupervisor
    fake = tmp_path / "frpc"
    fake.write_text("#!/bin/sh\nexec sleep 300\n")
    fake.chmod(0o755)
    (tmp_path / "frpc.toml").write_text("")
    sup = FrpcSupervisor(str(tmp_path), binary=str(fake))
    await sup.start()
    pid = sup.proc.pid
    await sup.stop()
    assert sup.proc.returncode is not None
    import os
    try:
        os.kill(pid, 0)
        alive = True
    except ProcessLookupError:
        alive = False
    assert not alive


async def test_resume_rerenders_with_current_secret(tmp_path):
    """Regression: after re-enrollment the old frpc.toml kept the old tunnel secret."""
    from agent.tunnel.frpc import FrpcSupervisor
    fake = tmp_path / "frpc"
    fake.write_text("#!/bin/sh\nexec sleep 300\n")
    fake.chmod(0o755)
    sup = FrpcSupervisor(str(tmp_path), binary=str(fake))
    proxies = [{"lan_host": "192.168.1.108", "lan_port": 554, "remote_port": 40000, "kind": "rtsp"}]
    await sup.apply(UID, {**FRPS, "tunnel_secret": "old-secret"}, proxies)
    await sup.stop()

    sup2 = FrpcSupervisor(str(tmp_path), binary=str(fake))          # agent restarts after re-enroll
    await sup2.resume(UID, {**FRPS, "tunnel_secret": "new-secret"})
    conf = tomllib.loads((tmp_path / "frpc.toml").read_text())
    await sup2.stop()
    assert conf["metadatas"]["secret"] == "new-secret"
    assert [p["remotePort"] for p in conf["proxies"]] == [40000]
