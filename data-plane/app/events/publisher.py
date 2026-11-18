"""Write normalized events to a Redis stream. Laravel reads them in batches (XREADGROUP),
so a burst of 10k motion events never becomes 10k PHP requests."""
from __future__ import annotations

import json

from redis.asyncio import Redis

from .normalizer import NormalizedEvent


class EventPublisher:
    def __init__(self, redis: Redis, stream: str, maxlen: int):
        self.redis, self.stream, self.maxlen = redis, stream, maxlen

    async def publish_many(self, events: list[NormalizedEvent]) -> None:
        if not events:
            return
        async with self.redis.pipeline(transaction=False) as p:
            for e in events:
                p.xadd(self.stream, {"v": "1", "event": json.dumps(e.to_dict())},
                       maxlen=self.maxlen, approximate=True)
            await p.execute()
