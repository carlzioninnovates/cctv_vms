# Live view

```
Browser ──POST /api/channels/{id}/stream──▶ Laravel ──HMAC──▶ data plane ──▶ MediaMTX: add on-demand path
   │  ◀── whep_url?vt=…, hls_url?vt=…, webrtc_ok ──┘                          (pulls RTSP through the tunnel
   │                                                                           only while someone watches)
   ├──WHEP (WebRTC) or HLS──▶ MediaMTX ──POST /api/internal/media/auth──▶ Laravel: token valid for this path?
   └──<img> /media/snapshot/{nvr}/{ch}?exp&sig ──▶ data plane (2 s shared cache) ──▶ NVR via tunnel
```

## Player choice (resources/js/dashboard/player.js)

| Situation | Player |
|---|---|
| Codec H.264 and WebRTC connects | WebRTC (WHEP) — sub-second |
| Codec H.265 (`webrtc_ok: false`) | HLS (hls.js, loaded on demand) — 2–4 s delay |
| WebRTC fails (UDP blocked, no TURN path) | HLS automatically |
| HD is H.265 and the browser can't decode it | Drops to SD automatically |
| Very old iOS without MSE | Native HLS (same session handling, works) |

## Data saving (matters on PH prepaid data)

- Only tiles on screen stream (IntersectionObserver); hidden browser tab = everything stops.
- Opening one camera full-size pauses the grid.
- **Low data** mode = signed JPEG snapshots every few seconds (~0.7 MB/min per camera vs ~4.5 MB/min live SD).
  Auto-selected when the browser reports Save-Data or a 2G/3G connection, or when no camera has a substream.
- MediaMTX `sourceOnDemand`: the NVR→cloud stream runs only while at least one person watches.
- The toolbar shows a rough MB/min for what's on screen.

## Security

- Viewer tokens: HMAC (app key), bound to **one MediaMTX path** and user, 30 min. Checked by MediaMTX on every
  WebRTC session / HLS request via `/api/internal/media/auth`. Publishing is always refused.
- Snapshot URLs: HMAC (data-plane secret) over `nvr:channel:expiry`, 30 min; the camera list refreshes them.
- PTZ: owner/admin/installer/operator only (viewers watch). Every stream start is audit-logged (`live.view`).

## Reverse proxy routes

| Path | Goes to | Public? |
|---|---|---|
| `/api/*`, pages | Laravel | yes |
| `/api/internal/*` | Laravel | **no** — MediaMTX only |
| `/media/*` | data plane | yes (signed URLs) |
| `/internal/*`, `/frps/*` | data plane | **no** |
| `/{path}/whep`, HLS | MediaMTX :8889 / :8888 | yes (token-checked) |

## How HLS auth actually works (MediaMTX v1.21, verified)

1. First playlist request carries `?vt=<token>` → MediaMTX calls `/api/internal/media/auth` → approved.
2. MediaMTX then ties the session to an `hlsSession` cookie — or, when the client doesn't keep cookies
   (cross-origin hls.js), to a `?session=<id>` it writes into every child playlist/segment URL.
3. Child requests are **not** re-checked with the token; a guessed session id gets 401.
   So the player must request URLs exactly as the playlist gives them (hls.js does).
   Note: MediaMTX sends no `Access-Control-Allow-Credentials`, so cookies never work cross-origin — the
   `?session=` fallback is what the dashboard uses.

## Local lab (real video, no NVR needed)

```bash
data-plane/.venv/bin/python infra/dev/live_lab.py     # needs ffmpeg + MediaMTX in infra/dev/bin
php control-plane/artisan db:seed --class=LabSeeder    # once
```
ffmpeg → "NVR simulator" (MediaMTX with Hikvision paths and a login) → real data plane → real MediaMTX
→ Laravel auth hook → browser. Open the **Lab** site → Live view. Logs: `infra/dev/state/logs/`.

## Verified with the lab (real MediaMTX v1.21.3, real ffmpeg streams)

- WebRTC: H.264 + Opus plays in the browser, 640×360, real time (video clock advanced 2.01 s per 2 s).
- Token gate: WHEP without token / forged token → 401; valid token → session created.
- Tunnel + credentials: correct Hikvision path pulled; NVR password with `@` and `#` accepted.
- On-demand: viewer left 22:33:24 → NVR pull stopped 22:33:39 (the 15 s window).
- HLS H.265: playlist advertises `hvc1` 1280×720; segments fetched via session URLs; ffprobe = hevc 1280×720.
- Repeat views no longer log MediaMTX errors (update-then-create).

## Not yet verified

- H.265 decoding in a browser: this Chrome 152 (Linux) has no HEVC decoder, so HD on H.265 cameras drops
  to SD there. Safari and Chrome on Windows/macOS with hardware HEVC should play it — check on real devices.
- Real NVRs, TURN on Globe/Smart mobile data, many simultaneous viewers.
