# Architecture

```
 Browser / mobile app
   │  HTTPS (Laravel API)            WebRTC (WHEP) / HLS
   ▼                                   ▲
 control-plane (Laravel) ──HMAC REST──▶ data-plane (FastAPI) ──control API──▶ MediaMTX
   ▲         reads events in batches      │  ▲                                  │
   └──────────── Redis Streams ◀──────────┘  │ tunnels (frps / WebSocket)       │ pulls RTSP on demand
                                             │                                  │
                                    edge-agent (on site, dials OUT) ────────────┘
                                             │ LAN
                                    Hikvision / Dahua / Uniview NVR
```

## Where each upgrade lives

| # | Upgrade | Code / schema |
|---|---|---|
| 1 | Offline cause (NVR / internet / power) | `data-plane/app/tunnels/offline.py`, table `offline_incidents` |
| 2 | Store-and-forward + LTE failover | `edge-agent/agent/buffer/`, `edge_agents.uplink_*` columns |
| 3 | Recording / HDD health | `NvrDriver.health()`, table `device_health_snapshots` |
| 4 | Credential security, mTLS, firmware CVEs | `data-plane/app/core/secrets.py`, `edge_agents.cert_fingerprint`, table `firmware_advisories` |
| 5 | Data Privacy Act (RA 10173) | tables `retention_policies`, `audit_logs`, `privacy_notices` |
| 6 | AI false-alarm filter (snapshot only) | `data-plane/app/ai/verifier.py`, `events.ai_*` columns |
| 7 | Low-data mode | `channels.default_view_mode`, snapshot endpoint, MediaMTX `sourceOnDemand` |
| 8 | Hybrid recording (clips to S3) | `events.clip_path`, `storage_plans` on plans |
| 9 | Viber / Telegram / Messenger / SMS / FCM | table `notification_channels` |
| 10 | Reseller tier (platform → reseller → client) | `organizations.type` + `parent_id` |
| 11 | GCash / Maya via PayMongo / Xendit | tables `plans`, `subscriptions`, `payments` |
| 12 | Tunnel sharding | `data-plane/app/tunnels/ring.py`, `edge_agents.assigned_node` |
| 13 | Generic ONVIF driver | `data-plane/app/drivers/onvif_generic.py` |
| 14 | Edge agent OTA | table `agent_releases`, `edge-agent/agent/updater/` |
| 15 | Observability | `infra/observability/`, `/metrics` on data plane |
| — | Device onboarding | [onboarding.md](onboarding.md) |
| — | Live view | [live-view.md](live-view.md) |
| 16 | Time / NTP | all timestamps UTC; `sites.timezone` default `Asia/Manila`; probe records clock drift |

## Event path

1. NVR raises an event → edge agent's subscription (ISAPI alertStream / Dahua attach / ONVIF PullPoint).
2. Agent forwards to `POST /ingest/events` (batched; buffered to SQLite if uplink is down).
3. Data plane normalizes (`events/normalizer.py`) → optional AI check → `XADD vms:events`.
4. Laravel queue worker reads the stream in batches → `events` table → notifications.
