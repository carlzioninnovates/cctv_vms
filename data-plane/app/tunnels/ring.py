"""Upgrade 12: consistent hashing so each edge agent sticks to one data-plane node,
and adding a node moves only ~1/N of agents. Stdlib only, unit-tested."""
from __future__ import annotations

import bisect
import hashlib


def _h(key: str) -> int:
    return int.from_bytes(hashlib.sha1(key.encode()).digest()[:8], "big")


class HashRing:
    def __init__(self, nodes: list[str] | None = None, vnodes: int = 160):
        self.vnodes = vnodes
        self._keys: list[int] = []
        self._owners: dict[int, str] = {}
        for n in nodes or []:
            self.add(n)

    def add(self, node: str) -> None:
        for i in range(self.vnodes):
            k = _h(f"{node}#{i}")
            if k in self._owners:
                continue
            bisect.insort(self._keys, k)
            self._owners[k] = node

    def remove(self, node: str) -> None:
        drop = [k for k, n in self._owners.items() if n == node]
        for k in drop:
            del self._owners[k]
        self._keys = [k for k in self._keys if k in self._owners]

    def node_for(self, key: str) -> str:
        if not self._keys:
            raise LookupError("no data-plane nodes registered")
        i = bisect.bisect(self._keys, _h(key)) % len(self._keys)
        return self._owners[self._keys[i]]

    @property
    def nodes(self) -> set[str]:
        return set(self._owners.values())
