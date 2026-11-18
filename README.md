# Zi-on VMS — Multi-brand Cloud Video Management System

Cloud VMS for Hikvision, Dahua and Uniview NVRs, built for Philippine networks
(CGNAT, unstable uplinks, brownouts).

| Folder | What it is | Language |
|---|---|---|
| `control-plane/` | Users, tenants/resellers, billing, device records, alert index, dashboards | Laravel 11 |
| `data-plane/` | Tunnels, NVR polling, ONVIF/vendor commands, event ingest, MediaMTX control | Python 3.11+ (async only) |
| `edge-agent/` | On-site gateway: outbound tunnel, discovery, event relay, offline buffer | Python |
| `media-engine/` | MediaMTX (RTSP in → WebRTC/HLS out) and coturn (TURN) config | config |
| `contracts/` | OpenAPI + JSON schemas shared by PHP and Python. Change these first. | YAML/JSON |
| `infra/` | docker-compose, frps, reverse proxy, monitoring | config |
| `docs/` | Architecture, compatibility matrix, decision records | Markdown |

## Hard rules

1. Laravel never talks to hardware. It calls `data-plane` over the internal API (HMAC-signed).
2. Python code is async only. No `requests`, no `time.sleep`, no blocking sockets.
3. NVR passwords live only in the data plane's encrypted store. Laravel holds a `credential_ref`.
4. Every NVR is **probed** on registration. Features are enabled from probed capabilities, never from brand alone.
   See [docs/compatibility.md](docs/compatibility.md). Onboarding flow: [docs/onboarding.md](docs/onboarding.md).

## Quick start

```bash
cp infra/.env.example infra/.env
docker compose -f infra/docker-compose.yml up -d postgres redis mediamtx
cd control-plane && php artisan migrate
cd ../data-plane && python3 -m venv .venv && .venv/bin/pip install -e '.[dev]' && .venv/bin/pytest
edge-agent/build_release.sh   # builds the site-agent release served at /install-agent.sh
```

Site agent install (on the site device): `curl -fsSL https://<your-server>/install-agent.sh | sudo bash -s -- <CODE>`.
Local video lab with a simulated NVR: see [docs/live-view.md](docs/live-view.md).
