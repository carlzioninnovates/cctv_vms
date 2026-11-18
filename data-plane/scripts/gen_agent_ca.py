"""One-time: create the agent CA. Keep the key in a secret manager / offline backup.
    python scripts/gen_agent_ca.py /etc/vms"""
import os
import sys
from pathlib import Path

from cryptography.hazmat.primitives import serialization

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app.core.ca import AgentCA

out = Path(sys.argv[1] if len(sys.argv) > 1 else ".")
out.mkdir(parents=True, exist_ok=True)
ca = AgentCA.generate()
(out / "agent-ca.crt").write_text(ca.ca_pem)
key = out / "agent-ca.key"
key.write_bytes(ca.key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                     serialization.NoEncryption()))
os.chmod(key, 0o600)
print(f"wrote {out}/agent-ca.crt and {key}")
