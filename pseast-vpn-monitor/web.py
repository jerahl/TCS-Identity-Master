#!/usr/bin/env python3
"""Phase 2 web layer for pseast-vpn-monitor.

A tiny http.server app (stdlib only) exposing:
    GET /            the single-page dashboard (index.html)
    GET /api/status  the Phase-1 JSON snapshot, served from a short cache that
                     honors poll_interval_seconds so rapid polls don't re-shell out
    GET /api/history the Phase-3 uptime/last-flap view + recent states
    GET /healthz     liveness of the monitor process itself

Each fresh snapshot is recorded to the Phase-3 history store (if enabled) and, on
a transition into an alertable state, runs the Phase-4 hooks (if enabled).

Like the rest of the project this is strictly read-only — it just re-runs the
Phase-1 collectors. Binds to listen_addr (localhost by default); see the README
for putting it behind a reverse proxy or binding to the management IP only.
"""

from __future__ import annotations

import json
import os
import threading
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import alerts
import history
import monitor

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
INDEX_HTML = os.path.join(SCRIPT_DIR, "index.html")


class SnapshotCache:
    """Serves the most recent snapshot, refreshing at most once per poll interval.

    Collecting on every request would re-shell out to systemctl/ip/journalctl for
    each browser tab; this coalesces concurrent requests behind one refresh. On
    each fresh collection it records history and evaluates alert hooks.
    """

    def __init__(self, cfg, mock, fixtures_dir, store=None):
        self._cfg = cfg
        self._mock = mock
        self._fixtures = fixtures_dir
        self._store = store
        self._ttl = max(1, int(cfg.get("poll_interval_seconds", 30)))
        self._lock = threading.Lock()
        self._snapshot = None
        self._fetched_at = 0.0

    def get(self) -> dict:
        with self._lock:
            age = time.monotonic() - self._fetched_at
            if self._snapshot is None or age >= self._ttl:
                self._snapshot = monitor.build_snapshot(
                    self._cfg, mock=self._mock, fixtures_dir=self._fixtures
                )
                self._fetched_at = time.monotonic()
                self._persist(self._snapshot)
            return self._snapshot

    def _persist(self, snapshot):
        """Record history + fire alert hooks. Never let this break serving."""
        if self._store is None:
            return
        try:
            prev = self._store.record(snapshot)
            alerts.maybe_alert(self._cfg, prev, snapshot, self._store)
        except Exception as exc:  # monitoring must keep serving even if this fails
            print(f"[history/alerts] {exc}", flush=True)


def make_handler(cache: SnapshotCache, store=None, recent_limit: int = 20):
    class Handler(BaseHTTPRequestHandler):
        server_version = "pseast-vpn-monitor/1.0"
        protocol_version = "HTTP/1.1"

        def _send(self, code: int, body: bytes, content_type: str):
            self.send_response(code)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)

        def _send_json(self, code: int, obj):
            self._send(code, json.dumps(obj).encode("utf-8"), "application/json; charset=utf-8")

        def do_GET(self):  # noqa: N802 (stdlib naming)
            path = self.path.split("?", 1)[0].rstrip("/") or "/"
            if path == "/":
                self._serve_index()
            elif path == "/api/status":
                self._serve_status()
            elif path == "/api/history":
                self._serve_history()
            elif path == "/healthz":
                self._send_json(200, {
                    "status": "ok",
                    "time_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                })
            else:
                self._send_json(404, {"error": "not found", "path": path})

        do_HEAD = do_GET

        def _serve_index(self):
            try:
                with open(INDEX_HTML, "rb") as fh:
                    body = fh.read()
            except FileNotFoundError:
                self._send(500, b"index.html missing", "text/plain; charset=utf-8")
                return
            self._send(200, body, "text/html; charset=utf-8")

        def _serve_status(self):
            try:
                self._send_json(200, cache.get())
            except Exception as exc:  # never take the server down over one bad poll
                self._send_json(500, {"error": "snapshot failed", "detail": str(exc)})

        def _serve_history(self):
            if store is None or not getattr(store, "record_history", False):
                self._send_json(200, {"enabled": False})
                return
            try:
                self._send_json(200, {
                    "enabled": True,
                    "summary": store.summary(),
                    "recent": store.recent(recent_limit),
                })
            except Exception as exc:
                self._send_json(500, {"error": "history failed", "detail": str(exc)})

        def log_message(self, fmt, *args):
            # Quiet, single-line access log to stderr.
            super().log_message(fmt, *args)

    return Handler


def serve(cfg: dict, mock: bool = False, fixtures_dir: str = monitor.DEFAULT_FIXTURES) -> int:
    """Run the dashboard server until interrupted. Returns a process exit code."""
    addr = cfg.get("listen_addr", "127.0.0.1")
    port = int(cfg.get("listen_port", 8787))
    store = history.from_config(cfg)
    cache = SnapshotCache(cfg, mock, fixtures_dir, store=store)
    recent = int(cfg.get("history_recent", 20))

    httpd = ThreadingHTTPServer((addr, port), make_handler(cache, store, recent))
    where = f"http://{addr}:{port}/"
    feats = []
    if store is not None and store.record_history:
        feats.append("history")
    if (cfg.get("alerts") or {}).get("enabled"):
        feats.append("alerts")
    extra = (" · " + "+".join(feats)) if feats else ""
    print(f"pseast-vpn-monitor serving {where}  (mock={mock}, poll={cache._ttl}s{extra})")
    print("read-only — Ctrl-C to stop")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nshutting down")
    finally:
        httpd.server_close()
    return 0
