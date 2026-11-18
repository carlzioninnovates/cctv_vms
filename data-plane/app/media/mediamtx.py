"""MediaMTX control API (v3). Paths are created on demand and pull from the NVR only
while someone is watching (sourceOnDemand) — the main bandwidth saver (upgrade 7)."""
from __future__ import annotations

import hashlib
import hmac

import httpx


class MediaMTX:
    def __init__(self, client: httpx.AsyncClient, api: str, public_webrtc: str, public_hls: str, salt: str):
        self.client, self.api = client, api.rstrip("/")
        self.public_webrtc, self.public_hls = public_webrtc.rstrip("/"), public_hls.rstrip("/")
        self.salt = salt.encode()

    def path_name(self, kind: str, nvr_id: int, channel: int, extra: str = "") -> str:
        # Not guessable; viewing still requires a token checked by MediaMTX authHTTPAddress.
        digest = hmac.new(self.salt, f"{kind}:{nvr_id}:{channel}:{extra}".encode(), hashlib.sha256).hexdigest()[:20]
        return f"{kind}/{nvr_id}/{channel}/{digest}"

    async def ensure_path(self, name: str, source: str, close_after: str = "15s") -> None:
        conf = {
            "source": source,
            "sourceOnDemand": True,
            "sourceOnDemandStartTimeout": "15s",   # slow tunnels / LTE
            "sourceOnDemandCloseAfter": close_after,
            "rtspTransport": "tcp",                 # UDP doesn't survive frp/CGNAT
        }
        # Update first: most stream starts are repeat views of an existing path, and "add" on an
        # existing path makes MediaMTX log an ERR line every time (noise that hides real errors).
        r = await self.client.patch(f"{self.api}/v3/config/paths/patch/{name}", json=conf)
        if r.status_code == 404:
            r = await self.client.post(f"{self.api}/v3/config/paths/add/{name}", json=conf)
            if r.status_code == 400 and "already exists" in r.text:   # created concurrently by another request
                return
        r.raise_for_status()

    async def remove_path(self, name: str) -> None:
        r = await self.client.delete(f"{self.api}/v3/config/paths/delete/{name}")
        if r.status_code != 404:
            r.raise_for_status()

    def urls(self, name: str) -> dict[str, str]:
        return {
            "path": name,
            "whep_url": f"{self.public_webrtc}/{name}/whep",
            "hls_url": f"{self.public_hls}/{name}/index.m3u8",
        }
