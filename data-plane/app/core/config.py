from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="VMS_", env_file=".env")

    node_id: str = "dp-1"
    hmac_secret: str                     # same as Laravel DATA_PLANE_SECRET
    credential_key: str                  # Fernet key: python -c "from cryptography.fernet import Fernet;print(Fernet.generate_key().decode())"
    redis_url: str = "redis://localhost:6379/0"
    mediamtx_api: str = "http://mediamtx:9997"
    mediamtx_public_webrtc: str = "https://media.example.ph"
    mediamtx_public_hls: str = "https://media.example.ph:8888"
    device_timeout_s: float = 10.0
    max_device_connections: int = 2000
    events_stream: str = "vms:events"
    events_stream_maxlen: int = 1_000_000
    ai_verify_enabled: bool = False
    agent_status_stream: str = "vms:agent-status"

    # Agent CA (signs edge-agent client certs for mTLS). Generate once, keep the key offline-backed.
    ca_cert_path: str = "/etc/vms/agent-ca.crt"
    ca_key_path: str = "/etc/vms/agent-ca.key"
    agent_cert_days: int = 365

    # Tunnel server
    frps_public_host: str = "tunnel.example.ph"   # what agents dial
    frps_public_port: int = 7000
    frps_internal_host: str = "172.30.0.10"       # where proxied NVR ports are bound (backend network)
    frps_token: str = ""
    tunnel_port_start: int = 40000
    tunnel_port_end: int = 59999

    heartbeat_timeout_s: int = 45


@lru_cache
def settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
