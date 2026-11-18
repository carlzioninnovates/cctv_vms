"""Signs edge-agent client certificates (mTLS). The CSR's subject is ignored: the cert
is always issued for the agent_uid Laravel vouched for, so a stolen enrollment code
can't mint a cert for a different agent."""
from __future__ import annotations

import datetime as dt
import hashlib
from dataclasses import dataclass

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, ed25519, rsa
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID


class CsrRejected(ValueError):
    pass


@dataclass
class IssuedCert:
    cert_pem: str
    ca_pem: str
    fingerprint_sha256: str   # hex, lowercase, of the DER cert — what the proxy must forward
    expires_at: str


def fingerprint(cert: x509.Certificate) -> str:
    return hashlib.sha256(cert.public_bytes(serialization.Encoding.DER)).hexdigest()


class AgentCA:
    def __init__(self, cert_pem: bytes, key_pem: bytes):
        self.cert = x509.load_pem_x509_certificate(cert_pem)
        self.key = serialization.load_pem_private_key(key_pem, password=None)

    @classmethod
    def from_files(cls, cert_path: str, key_path: str) -> AgentCA:
        with open(cert_path, "rb") as c, open(key_path, "rb") as k:
            return cls(c.read(), k.read())

    @classmethod
    def generate(cls, name: str = "VMS Agent CA") -> AgentCA:
        """For tests and first-time setup (scripts/gen_agent_ca.py)."""
        key = ec.generate_private_key(ec.SECP256R1())
        subj = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, name)])
        now = dt.datetime.now(dt.UTC)
        cert = (
            x509.CertificateBuilder().subject_name(subj).issuer_name(subj)
            .public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - dt.timedelta(minutes=5)).not_valid_after(now + dt.timedelta(days=3650))
            .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
            .add_extension(x509.KeyUsage(digital_signature=True, key_cert_sign=True, crl_sign=True,
                                         content_commitment=False, key_encipherment=False, data_encipherment=False,
                                         key_agreement=False, encipher_only=False, decipher_only=False), critical=True)
            .sign(key, hashes.SHA256())
        )
        return cls(cert.public_bytes(serialization.Encoding.PEM),
                   key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                     serialization.NoEncryption()))

    @property
    def ca_pem(self) -> str:
        return self.cert.public_bytes(serialization.Encoding.PEM).decode()

    def sign_csr(self, csr_pem: str, agent_uid: str, days: int = 365) -> IssuedCert:
        try:
            csr = x509.load_pem_x509_csr(csr_pem.encode())
        except ValueError as e:
            raise CsrRejected("not a PEM CSR") from e
        if not csr.is_signature_valid:
            raise CsrRejected("CSR signature invalid")
        pub = csr.public_key()
        if isinstance(pub, rsa.RSAPublicKey) and pub.key_size < 2048:
            raise CsrRejected("RSA key too small")
        if not isinstance(pub, rsa.RSAPublicKey | ec.EllipticCurvePublicKey | ed25519.Ed25519PublicKey):
            raise CsrRejected("unsupported key type")

        now = dt.datetime.now(dt.UTC)
        cert = (
            x509.CertificateBuilder()
            .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, agent_uid)]))
            .issuer_name(self.cert.subject)
            .public_key(pub)
            .serial_number(x509.random_serial_number())
            .not_valid_before(now - dt.timedelta(minutes=5))   # agent clocks drift
            .not_valid_after(now + dt.timedelta(days=days))
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.CLIENT_AUTH]), critical=False)
            .add_extension(x509.SubjectAlternativeName([x509.UniformResourceIdentifier(f"urn:vms:agent:{agent_uid}")]),
                           critical=False)
            .sign(self.key, hashes.SHA256())
        )
        return IssuedCert(
            cert_pem=cert.public_bytes(serialization.Encoding.PEM).decode(),
            ca_pem=self.ca_pem,
            fingerprint_sha256=fingerprint(cert),
            expires_at=cert.not_valid_after_utc.isoformat(),
        )
