"""One-time enrollment, run by the installer on site:

    zion-agent enroll ABCD-EFGH --api https://app.example.ph

The private key is generated here and never leaves the device; only the CSR is sent."""
from __future__ import annotations

import argparse
import asyncio
import os
import platform
import sys
from pathlib import Path

import httpx
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

from agent import __version__
from agent.config import AgentConfig, conf_dir


def make_key_and_csr() -> tuple[bytes, str]:
    key = ec.generate_private_key(ec.SECP256R1())
    csr = (x509.CertificateSigningRequestBuilder()
           .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, platform.node() or "vms-agent")]))
           .sign(key, hashes.SHA256()))
    key_pem = key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                serialization.NoEncryption())
    return key_pem, csr.public_bytes(serialization.Encoding.PEM).decode()


def normalize_code(code: str) -> str:
    c = "".join(ch for ch in code.upper() if ch.isalnum())
    if len(c) != 8:
        raise ValueError("enrollment code is 8 characters, like ABCD-EFGH")
    return f"{c[:4]}-{c[4:]}"


def _write(path: Path, data: bytes | str, mode: int) -> None:
    path.write_bytes(data if isinstance(data, bytes) else data.encode())
    os.chmod(path, mode)


async def enroll(code: str, api_url: str, client: httpx.AsyncClient, d: Path | None = None) -> AgentConfig:
    d = d or conf_dir()
    d.mkdir(parents=True, exist_ok=True)
    key_pem, csr_pem = make_key_and_csr()
    r = await client.post(f"{api_url.rstrip('/')}/api/agent/enroll", json={
        "code": normalize_code(code), "csr_pem": csr_pem,
        "hostname": platform.node(), "version": __version__, "arch": platform.machine(),
    })
    if r.status_code == 422 or r.status_code == 404:
        raise SystemExit(f"enrollment rejected: {r.json().get('message', r.text)}")
    r.raise_for_status()
    body = r.json()
    _write(d / "agent.key", key_pem, 0o600)
    _write(d / "agent.crt", body["cert_pem"], 0o644)
    _write(d / "ca.crt", body["ca_pem"], 0o644)
    cfg = AgentConfig(agent_uid=body["agent_uid"], api_url=api_url, agent_url=body["agent_url"], frps=body["frps"])
    cfg.save(d)
    return cfg


def cli(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(prog="zion-agent enroll")
    ap.add_argument("code")
    ap.add_argument("--api", default=os.environ.get("VMS_API_URL", "https://app.example.ph"))
    a = ap.parse_args(argv)

    async def go():
        async with httpx.AsyncClient(timeout=30) as c:
            cfg = await enroll(a.code, a.api, c)
        print(f"enrolled as {cfg.agent_uid}")

    asyncio.run(go())


if __name__ == "__main__":
    cli(sys.argv[1:])
