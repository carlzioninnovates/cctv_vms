# Device onboarding

How an installer goes from "new site" to live video. Every step works behind CGNAT:
the agent only ever connects out.

```
Installer (dashboard)          Laravel                     Data plane (Python)            Edge agent (on site)
─────────────────────          ───────                     ───────────────────            ────────────────────
1 Add agent to site ─────────▶ create edge_agents row
  ◀── code ABCD-EFGH ──────── (hash stored, 24h expiry)
                                                                                         2 vms-agent enroll ABCD-EFGH
                               POST /api/agent/enroll ◀──────────────────────────────── (key made locally, CSR sent)
                               check code (one-time) ───▶ sign CSR (CN = agent_uid)
                               ◀── cert, CA, frps ──────────
                               ───────────── cert + tunnel settings ─────────────────▶ save /etc/vms-agent, start
                                                           ◀── heartbeat (mTLS) ─────── every 15 s
                               agents:sync-status ◀─────── vms:agent-status: online
3 Scan network ──────────────▶ POST discover ────────────▶ command "discover" ◀─ long-poll ─ WS-Discovery on LAN
  ◀── devices (+ already added)◀──────────────────────────◀──────────────── result ───────
4 Add NVR (IP, brand, user/pw)▶ store creds ──────────────▶ encrypt in Redis → credential_ref
                               create nvr (onboarding)
                               queue OnboardNvr ──────────▶ expose: allocate frps ports ─▶ check LAN ports,
                                                           ◀────────────────────────────── rewrite frpc.toml, reload
                                                  probe ──▶ vendor API / ONVIF via tunnel
                               save model, firmware,     ◀── capabilities
                               channels, advisories
5 Poll GET /api/nvrs/{id} ────▶ status: active | failed + error.code + message
```

## API (installer-facing, Sanctum token)

| Method | Path | What |
|---|---|---|
| POST | `/api/sites/{site}/agents` | New agent → `enrollment_code`, `install_command` |
| POST | `/api/agents/{agent}/enrollment-code` | New code (revokes the old cert if already enrolled: use when replacing hardware) |
| GET | `/api/agents/{agent}` | Status, version, last heartbeat |
| POST | `/api/agents/{agent}/discover` | Scan LAN (rate-limited) |
| POST | `/api/sites/{site}/nvrs` | Add NVR → 202, then poll |
| GET | `/api/nvrs/{nvr}` | Status, error, warnings, channels, firmware advisories |
| POST | `/api/nvrs/{nvr}/retry` | Retry (blocked after `bad_credentials`) |
| PUT | `/api/nvrs/{nvr}/credentials` | New username/password → re-onboard |
| POST | `/api/agent/enroll` | Called by the agent itself (public, throttled) |

## Failure codes the installer sees

| `error.code` | Meaning | What the installer does |
|---|---|---|
| `agent_offline` | Site agent not connected | Check agent power/internet |
| `tunnel_failed` | Data plane couldn't open the tunnel | Retry; if repeated, check frps |
| `lan_unreachable` | Agent can't reach `ip:http_port` | Wrong IP, different VLAN, NVR off |
| `bad_credentials` | NVR rejected login | Change credentials. **Retry is blocked** so we don't lock the account |
| `probe_failed` | NVR answered but not as the chosen brand | Check brand and HTTP port |
| `internal_error` | Unexpected | Retry, then support |

Closed RTSP port is a **warning**, not a failure: the NVR is added, live view waits for RTSP.

## Security properties (each one is covered by a test)

- Enrollment code: one-time, 24 h, only its SHA-256 is stored, endpoint throttled (10/min per IP).
- Agent private key is generated on the device and never sent.
- Certificate subject is forced to the agent's UID; a CSR can't claim another identity.
- Re-enrolling invalidates the previous certificate.
- frps plugin rejects revoked agents and any proxy on a port not allocated to that agent
  (stops one customer's agent from hijacking another customer's NVR traffic).
- Revocation also cuts **open** tunnels: the plugin re-checks every frps heartbeat (`Ping`, 15 s)
  and every new tunnel connection (`NewWorkConn`). Measured in the lab: no video 7–8 s after revoke.
- Every plugin refusal is logged (`vms.security` logger: op, agent, port, client address, reason).
- After re-enrollment ("Replace agent", new device, or reinstall) the data plane re-sends the agent's
  full tunnel list on its first heartbeat, and the agent rebuilds `frpc.toml` with its new secret on
  start. Measured in the lab: tunnels back 2–3 s after the agent comes online, no NVR re-adding.
- NVR passwords exist only encrypted in the data plane; Laravel stores `credential_ref`.
- Agents can only complete their own commands.
- `lan_host` is validated on both sides; the agent re-validates before writing frpc.toml.

## Deployment notes

- **mTLS proxy** in front of `/agent/*` and `/ingest/*` must verify client certs against
  `agent-ca.crt` and forward the **SHA-256** fingerprint as `X-Client-Cert-Fingerprint`.
  Caddy: `{http.request.tls.client.fingerprint}`. nginx's `$ssl_client_fingerprint` is SHA-1 — won't match.
- `/frps/*` and `/internal/*` must not be routed by the public proxy.
- Create the CA once: `python data-plane/scripts/gen_agent_ca.py /etc/vms` and back up the key.

## Tunnel end-to-end test (real frps/frpc)

With the live lab running (`infra/dev/live_lab.py`, which now includes frps v0.71.1 and the mTLS
agent gateway) and the dashboard on :8000:

```bash
infra/dev/tunnel_e2e.sh
```

It installs the agent with the real installer (sandbox), opens a real tunnel through Laravel →
data plane → agent → frpc → frps, pulls video through MediaMTX with a viewer token, revokes the
agent and times how fast the open tunnel dies, then replaces the agent and checks tunnels come
back by themselves. Attack checks run by hand against the same frps (wrong secret, another
customer's port, unallocated port, no client certificate) were all refused.
