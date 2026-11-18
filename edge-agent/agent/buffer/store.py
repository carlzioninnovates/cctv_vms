"""Upgrade 2: store-and-forward. Events are written to SQLite first, then uploaded.
If the uplink is down (brownout, ISP drop), nothing is lost; on reconnect the backlog
drains oldest-first with buffered=True. Stdlib only."""
from __future__ import annotations

import json
import sqlite3
import time
from pathlib import Path


class EventBuffer:
    def __init__(self, path: str | Path, max_rows: int = 200_000):
        self.db = sqlite3.connect(str(path), isolation_level=None, check_same_thread=False)
        self.db.execute("PRAGMA journal_mode=WAL")      # survives power cuts better
        self.db.execute("PRAGMA synchronous=NORMAL")
        self.db.execute(
            "CREATE TABLE IF NOT EXISTS events ("
            " id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT UNIQUE, body TEXT NOT NULL,"
            " created REAL NOT NULL, attempts INTEGER NOT NULL DEFAULT 0)"
        )
        self.max_rows = max_rows

    def put(self, event: dict) -> None:
        self.db.execute("INSERT OR IGNORE INTO events(event_id, body, created) VALUES (?,?,?)",
                        (event["event_id"], json.dumps(event), time.time()))
        # Cap disk use on small SD cards: drop the oldest motion first, keep alarms.
        n = self.db.execute("SELECT COUNT(*) FROM events").fetchone()[0]
        if n > self.max_rows:
            self.db.execute(
                "DELETE FROM events WHERE id IN (SELECT id FROM events "
                " WHERE json_extract(body,'$.raw_type') IN ('VMD','VideoMotion') ORDER BY id LIMIT ?)",
                (n - self.max_rows,))

    def peek(self, limit: int = 500) -> list[tuple[int, dict]]:
        rows = self.db.execute("SELECT id, body, created FROM events ORDER BY id LIMIT ?", (limit,)).fetchall()
        now = time.time()
        out = []
        for rid, body, created in rows:
            ev = json.loads(body)
            ev["buffered"] = now - created > 10   # held longer than a normal send cycle
            out.append((rid, ev))
        return out

    def ack(self, ids: list[int]) -> None:
        if ids:
            self.db.execute(f"DELETE FROM events WHERE id IN ({','.join('?' * len(ids))})", ids)

    def fail(self, ids: list[int]) -> None:
        if ids:
            self.db.execute(f"UPDATE events SET attempts = attempts + 1 WHERE id IN ({','.join('?' * len(ids))})", ids)

    def __len__(self) -> int:
        return self.db.execute("SELECT COUNT(*) FROM events").fetchone()[0]
