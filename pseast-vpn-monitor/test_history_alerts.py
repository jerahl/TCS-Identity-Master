#!/usr/bin/env python3
"""Unit tests for Phase 3 (history) and Phase 4 (alerts).

No live commands, no network: history uses a temp sqlite db, and alerts are
exercised with `true`/`false` and the disabled/no-transition/debounce paths.
Run: python3 -m unittest -v
"""

import os
import tempfile
import unittest
from datetime import datetime, timezone

import alerts
import history


def _epoch(iso):
    return int(datetime.fromisoformat(iso).timestamp())


def snap(overall, utc_iso, signals=None):
    return {
        "overall": overall,
        "generated_utc": utc_iso,
        "generated_local": utc_iso,
        "timezone": "UTC",
        "signals": signals or {"tunnel": {"status": overall, "detail": overall}},
    }


class HistoryRecordTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db = os.path.join(self.tmp.name, "h.sqlite3")

    def tearDown(self):
        self.tmp.cleanup()

    def test_record_returns_prior_state(self):
        store = history.HistoryStore(self.db, retention_days=7, tz_name="UTC")
        self.assertIsNone(store.record(snap("ok", "2026-06-29T00:00:00+00:00")))
        prev = store.record(snap("down", "2026-06-29T00:01:00+00:00"))
        self.assertIsNotNone(prev)
        self.assertEqual(prev["overall"], "ok")

    def test_history_disabled_keeps_last_state_only(self):
        store = history.HistoryStore(self.db, record_history=False, tz_name="UTC")
        store.record(snap("ok", "2026-06-29T00:00:00+00:00"))
        prev = store.record(snap("warn", "2026-06-29T00:05:00+00:00"))
        self.assertEqual(prev["overall"], "ok")          # transitions still work
        self.assertEqual(store.summary()["samples"], 0)  # but nothing retained

    def test_retention_prunes_old_rows(self):
        store = history.HistoryStore(self.db, retention_days=1, tz_name="UTC")
        store.record(snap("ok", "2026-06-20T00:00:00+00:00"))   # 9 days before
        store.record(snap("ok", "2026-06-29T00:00:00+00:00"))   # prunes the old one
        self.assertEqual(store.summary()["samples"], 1)


class HistorySummaryTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = history.HistoryStore(
            os.path.join(self.tmp.name, "h.sqlite3"), retention_days=30, tz_name="UTC")

    def tearDown(self):
        self.tmp.cleanup()

    def test_time_weighted_uptime_and_flaps(self):
        self.store.record(snap("ok", "2026-06-29T00:00:00+00:00"))
        self.store.record(snap("down", "2026-06-29T01:00:00+00:00"))
        self.store.record(snap("down", "2026-06-29T02:00:00+00:00"))
        now = _epoch("2026-06-29T03:00:00+00:00")
        s = self.store.summary(now_epoch=now)
        # ok for 1h of a 3h window -> 33.33%
        self.assertEqual(s["uptime_pct"], 33.33)
        self.assertEqual(s["flaps"], 1)
        self.assertEqual(s["current"], "down")
        self.assertEqual(s["current_for_seconds"], 7200)  # down since 01:00
        self.assertEqual(s["samples"], 3)


class TransitionTest(unittest.TestCase):
    def test_is_transition(self):
        on = ["down", "warn"]
        self.assertTrue(alerts.is_transition("ok", "down", on))    # ok -> down
        self.assertTrue(alerts.is_transition(None, "warn", on))    # first observation, bad
        self.assertFalse(alerts.is_transition("down", "down", on))  # unchanged
        self.assertFalse(alerts.is_transition("warn", "ok", on))    # recovered
        self.assertFalse(alerts.is_transition("ok", "unknown", on))  # not alertable


class AlertDispatchTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = history.HistoryStore(
            os.path.join(self.tmp.name, "h.sqlite3"), tz_name="UTC")

    def tearDown(self):
        self.tmp.cleanup()

    def _cfg(self, **over):
        a = {"enabled": True, "on_status": ["down", "warn"],
             "command": ["true"], "min_interval_seconds": 300}
        a.update(over)
        return {"alerts": a, "unit_name": "u", "db_host": "h", "probe_timeout_seconds": 1}

    def test_disabled_does_not_fire(self):
        cfg = self._cfg(enabled=False)
        r = alerts.maybe_alert(cfg, {"overall": "ok"}, snap("down", "2026-06-29T00:00:00+00:00"), self.store)
        self.assertFalse(r["fired"])
        self.assertEqual(r["reason"], "disabled")

    def test_no_transition_does_not_fire(self):
        cfg = self._cfg()
        r = alerts.maybe_alert(cfg, {"overall": "down"}, snap("down", "2026-06-29T00:00:00+00:00"), self.store)
        self.assertFalse(r["fired"])

    def test_transition_fires_command(self):
        cfg = self._cfg(command=["true"])
        r = alerts.maybe_alert(cfg, {"overall": "ok"}, snap("down", "2026-06-29T00:00:00+00:00"),
                               self.store, now_epoch=1000)
        self.assertTrue(r["fired"])
        self.assertEqual(r["results"][0]["channel"], "command")
        self.assertTrue(r["results"][0]["ok"])

    def test_failed_command_still_fires_but_marks_not_ok(self):
        cfg = self._cfg(command=["false"])
        r = alerts.maybe_alert(cfg, {"overall": "ok"}, snap("warn", "2026-06-29T00:00:00+00:00"),
                               self.store, now_epoch=2000)
        self.assertTrue(r["fired"])
        self.assertFalse(r["results"][0]["ok"])

    def test_debounce(self):
        cfg = self._cfg(command=["true"], min_interval_seconds=300)
        first = alerts.maybe_alert(cfg, {"overall": "ok"}, snap("down", "2026-06-29T00:00:00+00:00"),
                                   self.store, now_epoch=1000)
        self.assertTrue(first["fired"])
        # Same state again 60s later -> within the 300s window -> debounced.
        second = alerts.maybe_alert(cfg, {"overall": "ok"}, snap("down", "2026-06-29T00:01:00+00:00"),
                                    self.store, now_epoch=1060)
        self.assertFalse(second["fired"])
        self.assertEqual(second["reason"], "debounced")


if __name__ == "__main__":
    unittest.main()
