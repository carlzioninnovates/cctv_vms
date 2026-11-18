"""Agent state on disk: /etc/zion-agent (override with VMS_AGENT_CONF)."""
from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass
from pathlib import Path


def conf_dir() -> Path:
    return Path(os.environ.get("VMS_AGENT_CONF", "/etc/zion-agent"))


@dataclass
class AgentConfig:
    agent_uid: str
    api_url: str          # Laravel public API (enrollment)
    agent_url: str        # data-plane agent endpoint behind the mTLS proxy
    frps: dict

    @classmethod
    def load(cls, d: Path | None = None) -> AgentConfig:
        return cls(**json.loads(((d or conf_dir()) / "agent.json").read_text()))

    def save(self, d: Path | None = None) -> None:
        p = (d or conf_dir()) / "agent.json"
        p.write_text(json.dumps(asdict(self), indent=2))
        os.chmod(p, 0o600)
