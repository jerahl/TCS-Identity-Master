#!/usr/bin/env python3
"""Phase 3 — history & retention for pseast-vpn-monitor.

Appends each served snapshot to a small sqlite database and derives an
uptime/last-flap view from it. Still strictly read-only with respect to the VPN:
this only records what the monitor observed.

Two tables in one sqlite file:
  - snapshot_history : one row per recorded snapshot (ts, overall, per-signal
                       statuses), pruned to the retention window on each write.
  - last_state       : a single upserted row holding the most recent overall +
                       per-signal statuses. Used to detect transitions (Phase 4)
                       independently of the rolling history's retention.
  - alert_log        : Phase 4 bookkeeping (when an alert last fired, per state).

Timestamps are stored as both an integer epoch (for duration math) and the
snapshot's ISO strings; the summary renders America/Chicago-aware times.
"""

from __future__ import annotations

import json
import os
import sqlite3
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))


def from_config(cfg: dict):
    """Build a HistoryStore if history OR alerts are enabled, else None.

    Alerts need the last-state row to detect transitions, so the store is active
    whenever either feature is on. `record_history` controls only the rolling
    snapshot_history table (and its /api/history view).
    """
    history_on = bool(cfg.get("history_enabled", True))
    alerts_on = bool((cfg.get("alerts") or {}).get("enabled", False))
    if not (history_on or alerts_on):
        return None
    db_path = cfg.get("history_db") or os.path.join(SCRIPT_DIR, "history.sqlite3")
    if not os.path.isabs(db_path):
        db_path = os.path.join(SCRIPT_DIR, db_path)
    return HistoryStore(
        db_path=db_path,
        retention_days=int(cfg.get("history_retention_days", 7)),
        record_history=history_on,
        tz_name=cfg.get("timezone", "America/Chicago"),
    )


class HistoryStore:
    def __init__(self, db_path: str, retention_days: int = 7,
                 record_history: bool = True, tz_name: str = "America/Chicago"):
        self.db_path = db_path
        self.retention_days = max(1, int(retention_days))
        self.record_history = record_history
        try:
            self._tz = ZoneInfo(tz_name)
        except Exception:
            self._tz = timezone.utc
        self._init_db()

    # -- low level ---------------------------------------------------------- #
    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path, timeout=5)
        conn.row_factory = sqlite3.Row
        return conn

    def _init_db(self) -> None:
        with self._connect() as c:
            c.execute("""CREATE TABLE IF NOT EXISTS snapshot_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts_epoch INTEGER NOT NULL,
                generated_utc TEXT NOT NULL,
                generated_local TEXT,
                overall TEXT NOT NULL,
                signals_json TEXT NOT NULL)""")
            c.execute("CREATE INDEX IF NOT EXISTS ix_hist_ts ON snapshot_history(ts_epoch)")
            c.execute("""CREATE TABLE IF NOT EXISTS last_state (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                ts_epoch INTEGER, generated_utc TEXT, overall TEXT, signals_json TEXT)""")
            c.execute("""CREATE TABLE IF NOT EXISTS alert_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts_epoch INTEGER NOT NULL, state TEXT NOT NULL,
                channel TEXT, ok INTEGER, detail TEXT)""")

    @staticmethod
    def _epoch(generated_utc: str) -> int:
        try:
            return int(datetime.fromisoformat(generated_utc).timestamp())
        except (ValueError, TypeError):
            return int(datetime.now(timezone.utc).timestamp())

    def _local_iso(self, epoch: int) -> str:
        return datetime.fromtimestamp(epoch, tz=timezone.utc).astimezone(self._tz).isoformat(timespec="seconds")

    # -- write -------------------------------------------------------------- #
    def record(self, snapshot: dict) -> dict | None:
        """Record one snapshot. Returns the PRIOR last_state (or None on first run).

        The prior state is what Phase 4 compares against to detect a transition.
        """
        overall = snapshot.get("overall", "unknown")
        gutc = snapshot.get("generated_utc", "")
        glocal = snapshot.get("generated_local")
        ts = self._epoch(gutc)
        sig_status = {k: v.get("status") for k, v in snapshot.get("signals", {}).items()}
        sig_json = json.dumps(sig_status)

        with self._connect() as c:
            row = c.execute(
                "SELECT ts_epoch, generated_utc, overall, signals_json FROM last_state WHERE id = 1"
            ).fetchone()
            prev = dict(row) if row else None

            if self.record_history:
                c.execute(
                    "INSERT INTO snapshot_history (ts_epoch, generated_utc, generated_local, overall, signals_json)"
                    " VALUES (?, ?, ?, ?, ?)",
                    (ts, gutc, glocal, overall, sig_json),
                )
                c.execute("DELETE FROM snapshot_history WHERE ts_epoch < ?",
                          (ts - self.retention_days * 86400,))

            c.execute(
                "INSERT INTO last_state (id, ts_epoch, generated_utc, overall, signals_json)"
                " VALUES (1, ?, ?, ?, ?)"
                " ON CONFLICT(id) DO UPDATE SET ts_epoch=excluded.ts_epoch,"
                " generated_utc=excluded.generated_utc, overall=excluded.overall,"
                " signals_json=excluded.signals_json",
                (ts, gutc, overall, sig_json),
            )
        return prev

    # -- Phase 4 helpers ---------------------------------------------------- #
    def last_alert_at(self, state: str) -> int | None:
        with self._connect() as c:
            row = c.execute("SELECT MAX(ts_epoch) AS m FROM alert_log WHERE state = ?", (state,)).fetchone()
        return int(row["m"]) if row and row["m"] is not None else None

    def log_alert(self, state: str, ts_epoch: int, results: list[dict]) -> None:
        with self._connect() as c:
            for r in (results or [{"channel": "none", "ok": True, "detail": ""}]):
                c.execute(
                    "INSERT INTO alert_log (ts_epoch, state, channel, ok, detail) VALUES (?, ?, ?, ?, ?)",
                    (ts_epoch, state, r.get("channel"), 1 if r.get("ok") else 0,
                     str(r.get("detail", ""))[:300]),
                )

    # -- read --------------------------------------------------------------- #
    def summary(self, now_epoch: int | None = None) -> dict:
        """Uptime / last-flap view derived from the retained history (time-weighted)."""
        with self._connect() as c:
            rows = c.execute(
                "SELECT ts_epoch, overall FROM snapshot_history ORDER BY ts_epoch ASC"
            ).fetchall()
        if not rows:
            return {"samples": 0, "current": None, "uptime_pct": None, "flaps": 0}

        now = now_epoch if now_epoch is not None else int(datetime.now(timezone.utc).timestamp())
        total = ok = 0.0
        flaps = 0
        for i, r in enumerate(rows):
            end = rows[i + 1]["ts_epoch"] if i + 1 < len(rows) else now
            dur = max(0, end - r["ts_epoch"])
            total += dur
            if r["overall"] == "ok":
                ok += dur
            if i > 0 and rows[i]["overall"] != rows[i - 1]["overall"]:
                flaps += 1

        current = rows[-1]["overall"]
        since = rows[-1]["ts_epoch"]
        for i in range(len(rows) - 1, -1, -1):
            if rows[i]["overall"] == current:
                since = rows[i]["ts_epoch"]
            else:
                break

        return {
            "samples": len(rows),
            "window_start_epoch": rows[0]["ts_epoch"],
            "window_start_local": self._local_iso(rows[0]["ts_epoch"]),
            "current": current,
            "current_since_epoch": since,
            "current_since_local": self._local_iso(since),
            "current_for_seconds": max(0, now - since),
            "flaps": flaps,
            "uptime_pct": round(100 * ok / total, 2) if total > 0 else None,
        }

    def recent(self, limit: int = 20) -> list[dict]:
        """The most recent N recorded states (newest first), for display."""
        with self._connect() as c:
            rows = c.execute(
                "SELECT generated_local, overall FROM snapshot_history ORDER BY ts_epoch DESC LIMIT ?",
                (int(limit),),
            ).fetchall()
        return [{"time_local": r["generated_local"], "overall": r["overall"]} for r in rows]
