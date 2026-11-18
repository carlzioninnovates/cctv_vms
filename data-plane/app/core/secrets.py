"""Upgrade 4: NVR credentials, encrypted at rest. Laravel only ever sees credential_ref."""
from __future__ import annotations

import json
import secrets as pysecrets

from cryptography.fernet import Fernet
from redis.asyncio import Redis


class CredentialStore:
    def __init__(self, redis: Redis, key: str):
        self.redis = redis
        self.f = Fernet(key.encode())

    async def put(self, username: str, password: str) -> str:
        ref = pysecrets.token_urlsafe(24)
        await self.redis.set(f"vms:cred:{ref}", self.f.encrypt(json.dumps([username, password]).encode()))
        return ref

    async def get(self, ref: str) -> tuple[str, str]:
        blob = await self.redis.get(f"vms:cred:{ref}")
        if blob is None:
            raise KeyError(ref)
        u, p = json.loads(self.f.decrypt(blob))
        return u, p

    async def delete(self, ref: str) -> None:
        await self.redis.delete(f"vms:cred:{ref}")
