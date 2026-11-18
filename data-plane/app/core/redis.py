"""One place to build the Redis client.

The data plane blocks on Redis on purpose: agents long-poll (BLPOP up to 55 s) and
Laravel-initiated commands wait for the agent's reply (up to 30 s). redis-py 8 defaults to a
5 s socket read timeout, which would cut every one of those off — so set it explicitly.
"""
from redis.asyncio import Redis

MAX_BLOCK_S = 55          # longest BLPOP we issue (agent long-poll cap in api/agent/routes.py)
SOCKET_TIMEOUT_S = MAX_BLOCK_S + 15


def make_redis(url: str) -> Redis:
    return Redis.from_url(url, socket_timeout=SOCKET_TIMEOUT_S, socket_connect_timeout=5,
                          health_check_interval=30, retry_on_timeout=False)
