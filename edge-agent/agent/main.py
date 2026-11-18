"""Edge agent. Usage:
    zion-agent enroll ABCD-EFGH --api https://app.example.ph   (once, on site; the installer does this)
    zion-agent run                                              (the service)

Loops (all async, all outbound-only):
- heartbeat: uptime, power (UPS), uplink, backlog       -> data plane presence
- commands:  long-poll for discover / expose / check_tcp -> agent/commands.py
- upload:    drain the SQLite event buffer in batches    -> /ingest/events
- frpc:      keep the tunnel process alive
"""
from __future__ import annotations

import asyncio
import logging
import os
import signal
import ssl
import sys
import time
from pathlib import Path

import httpx

from agent import __version__
from agent.buffer.store import EventBuffer
from agent.commands import CommandHandler, command_loop
from agent.config import AgentConfig, conf_dir
from agent.health.power import read_ups
from agent.tunnel.frpc import FrpcSupervisor

log = logging.getLogger("vms-agent")
BOOT = time.monotonic()


async def heartbeat_loop(client: httpx.AsyncClient, url: str, buf: EventBuffer) -> None:
    while True:
        try:
            await client.post(f"{url}/agent/heartbeat", json={
                "uptime_s": int(time.monotonic() - BOOT),
                "power": await read_ups(),
                "buffered_events": len(buf),
                "uplink": os.environ.get("VMS_UPLINK", "wired"),
                "version": __version__,
            })
        except httpx.HTTPError as e:
            log.warning("heartbeat failed: %s", e)
        await asyncio.sleep(15)


async def upload_loop(client: httpx.AsyncClient, url: str, agent_uid: str, buf: EventBuffer) -> None:
    backoff = 1
    while True:
        batch = buf.peek(500)
        if not batch:
            await asyncio.sleep(1)
            continue
        ids = [i for i, _ in batch]
        try:
            r = await client.post(f"{url}/ingest/events", json={"agent_id": agent_uid, "events": [e for _, e in batch]})
            r.raise_for_status()
            buf.ack(ids)
            backoff = 1
        except httpx.HTTPError as e:
            buf.fail(ids)
            log.warning("upload failed (%s), retry in %ss, %s buffered", e, backoff, len(buf))
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, 60)


def tls_context(conf: Path, pin_ca: bool = False) -> ssl.SSLContext:
    """mTLS to the cloud: present the agent cert; trust public CAs (or only the agent CA in labs).
    httpx 0.28 silently ignores the old cert=/verify=<path> arguments, so build the context here."""
    if pin_ca:
        ctx = ssl.create_default_context(cafile=str(conf / "ca.crt"))
    else:
        import certifi
        ctx = ssl.create_default_context(cafile=certifi.where())
    ctx.load_cert_chain(str(conf / "agent.crt"), str(conf / "agent.key"))
    return ctx


def make_client(conf: Path) -> httpx.AsyncClient:
    return httpx.AsyncClient(verify=tls_context(conf, bool(os.environ.get("VMS_PIN_CA"))), timeout=15)


async def run() -> None:
    logging.basicConfig(level=logging.INFO)
    d = conf_dir()
    cfg = AgentConfig.load(d)
    buf = EventBuffer(os.environ.get("VMS_BUFFER_PATH", str(d / "events.db")))
    frpc = FrpcSupervisor(str(d))
    await frpc.resume(cfg.agent_uid, cfg.frps)    # tunnels back after reboot / brownout / re-enroll
    handler = CommandHandler(cfg.agent_uid, cfg.frps, frpc)
    loop = asyncio.get_running_loop()
    main = asyncio.current_task()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, main.cancel)    # systemd stop / Ctrl-C -> clean shutdown
    try:
        async with make_client(d) as client:
            await asyncio.gather(
                heartbeat_loop(client, cfg.agent_url, buf),
                command_loop(client, cfg.agent_url, handler),
                upload_loop(client, cfg.agent_url, cfg.agent_uid, buf),
                frpc.watch(),
            )
    except asyncio.CancelledError:
        log.info("shutting down")
    finally:
        await frpc.stop()        # never leave an orphaned tunnel process behind


def cli() -> None:
    args = sys.argv[1:]
    if args[:1] == ["--version"]:
        print(__version__)
    elif args[:1] == ["enroll"]:
        from agent.enroll import cli as enroll_cli
        enroll_cli(args[1:])
    elif args[:1] in (["run"], []):
        asyncio.run(run())
    else:
        raise SystemExit("usage: zion-agent [run | enroll CODE --api URL | --version]")


if __name__ == "__main__":
    cli()
