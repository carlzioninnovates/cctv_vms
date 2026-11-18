"""Local live-video lab: real data plane + real MediaMTX + a simulated Hikvision NVR.

    data-plane/.venv/bin/python infra/dev/live_lab.py      (Ctrl-C stops everything)

Needs: ffmpeg on PATH, MediaMTX in infra/dev/bin/mediamtx-<version>/, and the Laravel dev
server on :8000 with `php artisan db:seed --class=LabSeeder` run once.

What is simulated: only the NVR (ffmpeg -> MediaMTX #1 with Hikvision RTSP paths and a login)
and the edge-agent tunnel (Redis mapping LAN 10.99.0.10:554 -> 127.0.0.1:8654). Everything
else is the production code path.
"""
from __future__ import annotations

import asyncio
import json
import os
import signal
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEV = ROOT / "infra" / "dev"
STATE = DEV / "state"
LOGS = STATE / "logs"
MTX = DEV / "bin" / f"mediamtx-{(DEV / 'bin' / 'MEDIAMTX_VERSION').read_text().strip()}" / "mediamtx"
PY = ROOT / "data-plane" / ".venv" / "bin" / "python"
DB = ROOT / "control-plane" / "database" / "database.sqlite"

REDIS_PORT = 6390
SIM_RTSP = 8654
SIM_USER, SIM_PASS = "admin", "Sim@Pass#1"      # special chars on purpose: tests URL encoding
LAN_HOST = "10.99.0.10"
AGENT = "lab-agent"
SECRET = "change-me"                              # = control-plane DATA_PLANE_SECRET
FRPS = DEV / "bin" / "frp-v0.71.1" / "frps"
FRPS_PORT = 7000
FRPS_TOKEN = "lab-frps-token"
TUNNEL_PORTS = (40000, 40099)


def laravel_env(key: str) -> str | None:
    for line in (ROOT / "control-plane" / ".env").read_text().splitlines():
        if line.startswith(key + "="):
            return line.split("=", 1)[1].strip().strip('"')
    return None


NVRSIM_YML = f"""
logLevel: info
authMethod: internal
authInternalUsers:
  - user: {SIM_USER}
    pass: "{SIM_PASS}"
    ips: []
    permissions: [{{action: publish}}, {{action: read}}]
rtsp: yes
rtspAddress: :{SIM_RTSP}
rtspTransports: [tcp]
rtmp: no
hls: no
webrtc: no
srt: no
api: no
moq: no
paths:
  all_others:
"""


def frps_toml() -> str:
    """Production infra/frps/frps.toml with lab paths. The server cert is the lab gateway cert
    (signed by the agent CA, CN/SAN localhost) so frpc can verify it like production."""
    return f"""bindAddr = "127.0.0.1"
bindPort = {FRPS_PORT}
auth.method = "token"
auth.token = "{FRPS_TOKEN}"
transport.tls.force = true
transport.tls.certFile = "{STATE / 'gateway.crt'}"
transport.tls.keyFile = "{STATE / 'gateway.key'}"
transport.tls.trustedCaFile = "{STATE / 'agent-ca.crt'}"
proxyBindAddr = "127.0.0.1"
allowPorts = [{{ start = {TUNNEL_PORTS[0]}, end = {TUNNEL_PORTS[1]} }}]
maxPortsPerClient = 64
transport.heartbeatTimeout = 90
log.level = "info"

[[httpPlugins]]
name = "vms-auth"
addr = "127.0.0.1:8001"
path = "/frps/plugin"
ops = ["Login", "NewProxy", "Ping", "NewWorkConn"]
"""


def cloud_yml() -> str:
    """Production config (media-engine/mediamtx.yml) with only local-dev overrides."""
    import yaml
    conf = yaml.safe_load((ROOT / "media-engine" / "mediamtx.yml").read_text())
    conf.update({
        "authHTTPAddress": "http://127.0.0.1:8000/api/internal/media/auth",
        "webrtcAdditionalHosts": [],
        "webrtcICEServers2": [],          # no TURN locally
        "webrtcIPsFromInterfaces": True,
    })
    return yaml.safe_dump(conf, sort_keys=False)


def ffmpeg_cmd(path: str, codec: str, size: str) -> list[str]:
    v = (["-c:v", "libx264", "-preset", "veryfast", "-tune", "zerolatency", "-profile:v", "baseline"]
         if codec == "h264" else
         ["-c:v", "libx265", "-preset", "ultrafast", "-tune", "zerolatency", "-x265-params", "keyint=30:bframes=0:log-level=error"])
    return ["ffmpeg", "-hide_banner", "-loglevel", "error", "-re",
            "-f", "lavfi", "-i", f"testsrc2=size={size}:rate=15",
            "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=48000",
            *v, "-g", "30", "-bf", "0", "-pix_fmt", "yuv420p",
            "-c:a", "libopus", "-b:a", "32k",
            "-f", "rtsp", "-rtsp_transport", "tcp",
            f"rtsp://{SIM_USER}:{SIM_PASS.replace('@', '%40').replace('#', '%23')}@127.0.0.1:{SIM_RTSP}/{path}"]


async def spawn(name: str, cmd: list[str], env: dict | None = None, cwd: Path | None = None):
    log = open(LOGS / f"{name}.log", "ab")
    p = await asyncio.create_subprocess_exec(*cmd, stdout=log, stderr=log, env={**os.environ, **(env or {})},
                                             cwd=str(cwd) if cwd else None, start_new_session=True)
    print(f"  started {name:<12} pid {p.pid}  (log: {LOGS / (name + '.log')})")
    return p


async def wait_port(port: int, what: str, timeout: float = 20) -> None:
    for _ in range(int(timeout * 10)):
        try:
            _, w = await asyncio.open_connection("127.0.0.1", port)
            w.close()
            return
        except OSError:
            await asyncio.sleep(0.1)
    raise SystemExit(f"{what} did not open port {port}; see {LOGS}")


async def seed_redis(nvr_id: int, tz: str) -> None:
    sys.path.insert(0, str(ROOT / "data-plane"))
    from redis.asyncio import Redis
    from app.core.secrets import CredentialStore
    from app.tunnels.registry import NvrRecord, TunnelRegistry

    r = Redis.from_url(f"redis://127.0.0.1:{REDIS_PORT}/0")
    creds = CredentialStore(r, os.environ["VMS_CREDENTIAL_KEY"])
    ref = await creds.put(SIM_USER, SIM_PASS)
    await TunnelRegistry(r, creds).save_nvr(NvrRecord(
        nvr_id=nvr_id, brand="hikvision", agent_id=AGENT, lan_host=LAN_HOST, http_port=80, rtsp_port=554,
        onvif_port=80, credential_ref=ref, device_tz=tz,
        channel_codecs={"1": {"main": "h265", "sub": "h264"}}))
    await r.hset(f"vms:tunnel:{AGENT}", mapping={f"{LAN_HOST}:554": f"127.0.0.1:{SIM_RTSP}",
                                                f"{LAN_HOST}:80": "127.0.0.1:1"})
    return r


NO_CAMERA = "--no-camera" in sys.argv   # everything except the ffmpeg video source


async def main() -> None:
    for tool, hint in (() if NO_CAMERA else (("ffmpeg", "sudo apt install ffmpeg"),)):
        if not any((Path(d) / tool).exists() for d in os.environ["PATH"].split(":")):
            raise SystemExit(f"{tool} not found ({hint})")
    con = sqlite3.connect(DB)
    row = con.execute("select nvrs.id, sites.timezone from nvrs join sites on sites.id = nvrs.site_id "
                      "where nvrs.name = 'Lab NVR simulator' and nvrs.deleted_at is null").fetchone()
    if not row:
        raise SystemExit("Run: cd control-plane && php artisan db:seed --class=LabSeeder")
    nvr_id, tz = row
    LOGS.mkdir(parents=True, exist_ok=True)
    (STATE / "nvrsim.yml").write_text(NVRSIM_YML)
    (STATE / "cloud.yml").write_text(cloud_yml())
    if not (STATE / "agent-ca.key").exists():
        await (await asyncio.create_subprocess_exec(str(PY), str(ROOT / "data-plane/scripts/gen_agent_ca.py"),
                                                    str(STATE), stdout=asyncio.subprocess.DEVNULL)).wait()
    secret = laravel_env("DATA_PLANE_SECRET") or SECRET
    from cryptography.fernet import Fernet
    os.environ["VMS_CREDENTIAL_KEY"] = Fernet.generate_key().decode()

    procs = []
    print("Live lab:")
    procs.append(await spawn("redis", [str(PY), "-c",
        f"from fakeredis import TcpFakeServer; s=TcpFakeServer(('127.0.0.1',{REDIS_PORT}), server_type='redis'); s.serve_forever()"]))
    await wait_port(REDIS_PORT, "redis")
    procs.append(await spawn("nvr-sim", [str(MTX), str(STATE / "nvrsim.yml")]))
    await wait_port(SIM_RTSP, "NVR simulator")
    if not NO_CAMERA:
        procs.append(await spawn("cam-sub-h264", ffmpeg_cmd("Streaming/Channels/102", "h264", "640x360")))
        procs.append(await spawn("cam-main-h265", ffmpeg_cmd("Streaming/Channels/101", "h265", "1280x720")))
    procs.append(await spawn("mediamtx", [str(MTX), str(STATE / "cloud.yml")]))
    await wait_port(9997, "cloud MediaMTX")
    procs.append(await spawn("data-plane", [str(PY), "-m", "uvicorn", "app.main:app", "--port", "8001"], env={
        "VMS_HMAC_SECRET": secret,
        "VMS_REDIS_URL": f"redis://127.0.0.1:{REDIS_PORT}/0",
        "VMS_MEDIAMTX_API": "http://127.0.0.1:9997",
        "VMS_MEDIAMTX_PUBLIC_WEBRTC": "http://localhost:8889",
        "VMS_MEDIAMTX_PUBLIC_HLS": "http://localhost:8888",
        "VMS_CA_CERT_PATH": str(STATE / "agent-ca.crt"),
        "VMS_CA_KEY_PATH": str(STATE / "agent-ca.key"),
        "VMS_FRPS_PUBLIC_HOST": "localhost",
        "VMS_FRPS_PUBLIC_PORT": str(FRPS_PORT),
        "VMS_FRPS_INTERNAL_HOST": "127.0.0.1",
        "VMS_FRPS_TOKEN": FRPS_TOKEN,
        "VMS_TUNNEL_PORT_START": str(TUNNEL_PORTS[0]),
        "VMS_TUNNEL_PORT_END": str(TUNNEL_PORTS[1]),
    }, cwd=ROOT / "data-plane"))
    await wait_port(8001, "data plane")
    procs.append(await spawn("agent-gw", [str(PY), str(DEV / "agent_gateway.py")]))
    await wait_port(8443, "agent gateway")
    (STATE / "frps.toml").write_text(frps_toml())       # needs gateway.crt, created by the gateway
    procs.append(await spawn("frps", [str(FRPS), "-c", str(STATE / "frps.toml")]))
    await wait_port(FRPS_PORT, "frps")
    redis = await seed_redis(nvr_id, tz)
    print(f"\nReady. NVR id {nvr_id} -> open http://localhost:8000/sites (site 'Lab'), then Live view.  Ctrl-C to stop.")

    stop = asyncio.Event()
    for s in (signal.SIGINT, signal.SIGTERM):
        asyncio.get_running_loop().add_signal_handler(s, stop.set)
    try:
        while not stop.is_set():
            # Stand-in for the edge agent's heartbeat
            await redis.set(f"vms:agent:{AGENT}:hb", "1", ex=45)
            dead = [p for p in procs if p.returncode is not None]
            if dead:
                print(f"process exited: pid {dead[0].pid} code {dead[0].returncode}; see {LOGS}")
                break
            try:
                await asyncio.wait_for(stop.wait(), 15)
            except TimeoutError:
                pass
    finally:
        for p in procs:
            if p.returncode is None:
                os.killpg(p.pid, signal.SIGTERM)
        await asyncio.gather(*(p.wait() for p in procs), return_exceptions=True)
        print("Live lab stopped.")


if __name__ == "__main__":
    asyncio.run(main())
