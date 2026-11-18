"""Lab stand-in for the production mTLS proxy in front of /agent/* and /ingest/*.

Same contract as production (Caddy etc.): require a client cert signed by the agent CA,
forward the request to the data plane with X-Client-Cert-Fingerprint = SHA-256 of the cert.
Its own server cert is signed by the lab agent CA, so agents run with VMS_PIN_CA=1 in the lab.

    data-plane/.venv/bin/python infra/dev/agent_gateway.py      (listens on https://localhost:8443)
"""
from __future__ import annotations

import datetime as dt
import hashlib
import ssl
import sys
from pathlib import Path

import httpx
from aiohttp import web
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

STATE = Path(__file__).resolve().parent / "state"
DATA_PLANE = "http://127.0.0.1:8001"
PORT = 8443


def server_cert() -> tuple[Path, Path]:
    crt, key = STATE / "gateway.crt", STATE / "gateway.key"
    if crt.exists():
        return crt, key
    ca_cert = x509.load_pem_x509_certificate((STATE / "agent-ca.crt").read_bytes())
    ca_key = serialization.load_pem_private_key((STATE / "agent-ca.key").read_bytes(), None)
    k = ec.generate_private_key(ec.SECP256R1())
    now = dt.datetime.now(dt.UTC)
    cert = (x509.CertificateBuilder()
            .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "localhost")]))
            .issuer_name(ca_cert.subject).public_key(k.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - dt.timedelta(minutes=5)).not_valid_after(now + dt.timedelta(days=365))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName("localhost")]), critical=False)
            .sign(ca_key, hashes.SHA256()))
    crt.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    key.write_bytes(k.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    key.chmod(0o600)
    return crt, key


async def forward(request: web.Request) -> web.Response:
    if not request.path.startswith(("/agent/", "/ingest/")):
        return web.Response(status=404)
    der = request.transport.get_extra_info("ssl_object").getpeercert(binary_form=True)
    headers = {k: v for k, v in request.headers.items()
               if k.lower() not in ("host", "content-length", "x-client-cert-fingerprint", "transfer-encoding")}
    headers["X-Client-Cert-Fingerprint"] = hashlib.sha256(der).hexdigest()
    client: httpx.AsyncClient = request.app["client"]
    r = await client.request(request.method, DATA_PLANE + request.path_qs, content=await request.read(), headers=headers)
    return web.Response(status=r.status_code, body=r.content,
                        headers={k: v for k, v in r.headers.items() if k.lower() == "content-type"})


async def make_app() -> web.Application:
    app = web.Application()
    app["client"] = httpx.AsyncClient(timeout=70)   # > agent long-poll (25 s)
    app.router.add_route("*", "/{tail:.*}", forward)
    return app


if __name__ == "__main__":
    crt, key = server_cert()
    ctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    ctx.load_cert_chain(crt, key)
    ctx.load_verify_locations(STATE / "agent-ca.crt")
    ctx.verify_mode = ssl.CERT_REQUIRED          # no agent cert, no connection
    print(f"agent gateway on https://localhost:{PORT} -> {DATA_PLANE}", flush=True)
    web.run_app(make_app(), host="127.0.0.1", port=PORT, ssl_context=ctx, print=None)
    sys.exit(0)
