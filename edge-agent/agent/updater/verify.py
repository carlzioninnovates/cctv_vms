"""Upgrade 14: only install agent updates that match the published sha256 AND carry a valid
ed25519 signature from our release key (public key baked into the agent image)."""
from __future__ import annotations

import hashlib
from pathlib import Path

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey


def verify_release(artifact: Path, expected_sha256: str, signature: bytes, pubkey_raw: bytes) -> bool:
    h = hashlib.sha256()
    with artifact.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    digest = h.hexdigest()
    if digest != expected_sha256.lower():
        return False
    try:
        Ed25519PublicKey.from_public_bytes(pubkey_raw).verify(signature, digest.encode())
    except InvalidSignature:
        return False
    return True
