"""Writes frpc.toml from the cloud's proxy list and keeps frpc running."""
from __future__ import annotations

import asyncio
import ipaddress
import json
import logging
import os
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, StrictUndefined

log = logging.getLogger(__name__)
_env = Environment(loader=FileSystemLoader(Path(__file__).parent), undefined=StrictUndefined, autoescape=False)


def _safe_proxy(p: dict) -> dict:
    """Cloud input goes into a config file: validate strictly so nothing can inject TOML."""
    host = str(p["lan_host"])
    try:
        ipaddress.ip_address(host)
    except ValueError:
        if not all(c.isalnum() or c in ".-" for c in host) or len(host) > 253:
            raise ValueError(f"bad lan_host {host!r}") from None
    lan_port, remote_port = int(p["lan_port"]), int(p["remote_port"])
    if not (0 < lan_port < 65536 and 0 < remote_port < 65536):
        raise ValueError("bad port")
    kind = p.get("kind", "http")
    if kind not in ("http", "rtsp", "onvif"):
        raise ValueError("bad kind")
    return {"lan_host": host, "lan_port": lan_port, "remote_port": remote_port, "kind": kind}


def render(agent_uid: str, frps: dict, proxies: list[dict], conf_dir: str) -> str:
    return _env.get_template("frpc.toml.tmpl").render(
        agent_uid=agent_uid, frps=frps, conf_dir=conf_dir,
        proxies=[_safe_proxy(p) for p in proxies])


class FrpcSupervisor:
    def __init__(self, conf_dir: str, binary: str | None = None):
        self.conf_dir = Path(conf_dir)
        self.path = self.conf_dir / "frpc.toml"
        self.proxies_path = self.conf_dir / "proxies.json"
        self.binary = binary or os.environ.get("VMS_FRPC_BIN", "frpc")
        self.proc: asyncio.subprocess.Process | None = None

    async def apply(self, agent_uid: str, frps: dict, proxies: list[dict]) -> None:
        text = render(agent_uid, frps, proxies, str(self.conf_dir))
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(text)
        os.chmod(tmp, 0o600)            # contains the tunnel secret
        tmp.replace(self.path)          # atomic: frpc never reads a half-written file
        self.proxies_path.write_text(json.dumps(proxies))
        if self.proc is None or self.proc.returncode is not None:
            await self.start()
        else:
            p = await asyncio.create_subprocess_exec(self.binary, "reload", "-c", str(self.path))
            if await p.wait() != 0:
                log.warning("frpc reload failed; restarting")
                self.proc.terminate()
                await self.proc.wait()
                await self.start()

    async def resume(self, agent_uid: str, frps: dict) -> None:
        """On agent start: rebuild frpc.toml from the saved tunnel list with the CURRENT secret
        (it changes on re-enrollment), so tunnels come back without waiting for the cloud."""
        if self.proxies_path.exists():
            try:
                await self.apply(agent_uid, frps, json.loads(self.proxies_path.read_text()))
                return
            except (ValueError, KeyError) as e:
                log.warning("saved tunnel list unusable (%s); waiting for the cloud to resend it", e)
        elif self.path.exists():
            await self.start()

    async def start(self) -> None:
        self.proc = await asyncio.create_subprocess_exec(self.binary, "-c", str(self.path))

    async def stop(self) -> None:
        if self.proc is not None and self.proc.returncode is None:
            self.proc.terminate()
            try:
                await asyncio.wait_for(self.proc.wait(), 5)
            except TimeoutError:
                self.proc.kill()
                await self.proc.wait()

    async def watch(self) -> None:
        """Restart frpc if it dies."""
        while True:
            if self.proc is not None and self.proc.returncode is not None and self.path.exists():
                log.warning("frpc exited (%s); restarting", self.proc.returncode)
                await self.start()
            await asyncio.sleep(5)
