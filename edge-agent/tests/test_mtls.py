"""Real mutual-TLS handshake with the agent's HTTP client (regression: httpx 0.28 ignored cert=)."""
import asyncio
import datetime as dt
import ssl

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

from agent.main import make_client


def _cert(subject, issuer_name, key, signer, *, ca=False, san=None, client=False):
    now = dt.datetime.now(dt.UTC)
    b = (x509.CertificateBuilder().subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, subject)]))
         .issuer_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, issuer_name)]))
         .public_key(key.public_key()).serial_number(x509.random_serial_number())
         .not_valid_before(now - dt.timedelta(minutes=1)).not_valid_after(now + dt.timedelta(days=1))
         .add_extension(x509.BasicConstraints(ca=ca, path_length=None), critical=True))
    if san:
        b = b.add_extension(x509.SubjectAlternativeName([x509.DNSName(san)]), critical=False)
    if client:
        b = b.add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.CLIENT_AUTH]), critical=False)
    return b.sign(signer, hashes.SHA256())


def _pem(obj):
    if isinstance(obj, x509.Certificate):
        return obj.public_bytes(serialization.Encoding.PEM)
    return obj.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())


async def test_agent_client_presents_cert_and_pins_ca(tmp_path, monkeypatch):
    ca_key, srv_key, agent_key = (ec.generate_private_key(ec.SECP256R1()) for _ in range(3))
    ca = _cert("Test Agent CA", "Test Agent CA", ca_key, ca_key, ca=True)
    srv = _cert("localhost", "Test Agent CA", srv_key, ca_key, san="localhost")
    agent = _cert("agent-uid", "Test Agent CA", agent_key, ca_key, client=True)
    for name, obj in {"ca.crt": ca, "agent.crt": agent, "agent.key": agent_key,
                      "srv.crt": srv, "srv.key": srv_key}.items():
        (tmp_path / name).write_bytes(_pem(obj))

    seen = {}

    async def handle(reader, writer):
        der = writer.get_extra_info("ssl_object").getpeercert(binary_form=True)
        seen["client_cert"] = x509.load_der_x509_certificate(der).subject.rfc4514_string()
        await reader.readuntil(b"\r\n\r\n")
        writer.write(b"HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n")
        await writer.drain()
        writer.close()

    sctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    sctx.load_cert_chain(tmp_path / "srv.crt", tmp_path / "srv.key")
    sctx.load_verify_locations(tmp_path / "ca.crt")
    sctx.verify_mode = ssl.CERT_REQUIRED                      # like the production gateway
    server = await asyncio.start_server(handle, "127.0.0.1", 0, ssl=sctx)
    port = server.sockets[0].getsockname()[1]

    monkeypatch.setenv("VMS_PIN_CA", "1")
    async with make_client(tmp_path) as client:
        r = await client.post(f"https://localhost:{port}/agent/heartbeat", json={"uptime_s": 1})
    server.close()
    await server.wait_closed()
    assert r.status_code == 204
    assert seen["client_cert"] == "CN=agent-uid"
