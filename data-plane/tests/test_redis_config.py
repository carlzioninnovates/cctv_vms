import inspect

from app.api.agent import routes
from app.core.redis import MAX_BLOCK_S, make_redis


def test_socket_timeout_outlasts_longest_block():
    # Regression: redis-py 8 defaults to a 5 s read timeout, which broke agent long-polls.
    r = make_redis("redis://127.0.0.1:6390/0")
    assert r.connection_pool.connection_kwargs["socket_timeout"] > MAX_BLOCK_S
    # The agent long-poll cap must stay within MAX_BLOCK_S
    le = inspect.signature(routes.next_command).parameters["wait"].default.metadata
    assert any(getattr(m, "le", None) == MAX_BLOCK_S for m in le)
