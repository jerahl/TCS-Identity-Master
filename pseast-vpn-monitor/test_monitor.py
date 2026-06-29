#!/usr/bin/env python3
"""Unit tests for pseast-vpn-monitor's pure parsers/classifiers.

These exercise the logic without any live commands. Two of the project's
acceptance criteria are pinned here directly: tunnel-down (tun0 absent) and a
DB route leak (egress dev != tun0). Run: python3 -m unittest -v
"""

import json
import os
import unittest

import monitor

FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures")


class WorstTest(unittest.TestCase):
    def test_ordering(self):
        self.assertEqual(monitor.worst(["ok", "ok"]), "ok")
        self.assertEqual(monitor.worst(["ok", "unknown"]), "unknown")
        self.assertEqual(monitor.worst(["unknown", "warn"]), "warn")
        self.assertEqual(monitor.worst(["warn", "down", "ok"]), "down")
        self.assertEqual(monitor.worst([]), "unknown")


class ServiceTest(unittest.TestCase):
    def setUp(self):
        self.clock = monitor.Clock("America/Chicago")

    def test_active_running_is_ok_with_uptime(self):
        props = monitor.parse_systemctl_show(
            "ActiveState=active\nSubState=running\nNRestarts=2\n"
            "ExecMainStartTimestamp=Fri 2026-06-26 02:31:14 CDT\nExecMainPID=42\n"
        )
        status, detail, data = monitor.classify_service(props, self.clock)
        self.assertEqual(status, "ok")
        self.assertEqual(data["n_restarts"], 2)
        self.assertEqual(data["main_pid"], 42)
        self.assertIsNotNone(data["uptime"])
        self.assertIn("restarts", detail)

    def test_failed_is_down(self):
        props = monitor.parse_systemctl_show("ActiveState=failed\nSubState=failed\n")
        status, _, _ = monitor.classify_service(props, self.clock)
        self.assertEqual(status, "down")

    def test_activating_is_warn(self):
        props = monitor.parse_systemctl_show("ActiveState=activating\nSubState=start\n")
        status, _, _ = monitor.classify_service(props, self.clock)
        self.assertEqual(status, "warn")

    def test_empty_is_unknown(self):
        status, _, _ = monitor.classify_service({}, self.clock)
        self.assertEqual(status, "unknown")


class TimestampTest(unittest.TestCase):
    def test_parse_and_uptime(self):
        clock = monitor.Clock("America/Chicago")
        dt = monitor.parse_start_timestamp("Fri 2026-06-26 02:31:14 CDT", clock)
        self.assertIsNotNone(dt)
        self.assertEqual((dt.year, dt.month, dt.day), (2026, 6, 26))

    def test_unparseable_is_none(self):
        clock = monitor.Clock("America/Chicago")
        self.assertIsNone(monitor.parse_start_timestamp("", clock))
        self.assertIsNone(monitor.parse_start_timestamp("n/a", clock))

    def test_human_uptime(self):
        self.assertEqual(monitor.human_uptime(0), "0m")
        self.assertEqual(monitor.human_uptime(90), "1m")
        self.assertEqual(monitor.human_uptime(3 * 86400 + 4 * 3600 + 12 * 60), "3d 4h 12m")


class TunnelTest(unittest.TestCase):
    def test_present_with_address_is_ok(self):
        with open(os.path.join(FIXTURES, "ip_addr_tun.json")) as fh:
            status, detail, data = monitor.classify_tunnel(fh.read(), "tun0")
        self.assertEqual(status, "ok")
        self.assertEqual(data["addresses"], ["10.123.45.67"])

    def test_absent_is_down(self):
        # Acceptance: tun0 absent -> tunnel down.
        status, detail, data = monitor.classify_tunnel("[]", "tun0")
        self.assertEqual(status, "down")
        self.assertFalse(data["present"])

    def test_present_without_ipv4_is_down(self):
        arr = json.dumps([{"ifname": "tun0", "operstate": "DOWN", "addr_info": []}])
        status, _, _ = monitor.classify_tunnel(arr, "tun0")
        self.assertEqual(status, "down")


class RouteTest(unittest.TestCase):
    def test_via_tunnel_is_ok(self):
        arr = json.dumps([{"dst": "172.23.169.131", "dev": "tun0"}])
        status, _, data = monitor.classify_route(arr, "tun0", "172.23.169.131")
        self.assertEqual(status, "ok")
        self.assertEqual(data["dev"], "tun0")

    def test_leak_off_physical_nic_is_warn(self):
        # Acceptance: route leaks off tun0 -> warn (not ok).
        arr = json.dumps([{"dst": "172.23.169.131", "dev": "eth0", "gateway": "10.10.0.1"}])
        status, detail, data = monitor.classify_route(arr, "tun0", "172.23.169.131")
        self.assertEqual(status, "warn")
        self.assertIn("leak", detail)
        self.assertEqual(data["dev"], "eth0")

    def test_no_route_is_unknown(self):
        status, _, _ = monitor.classify_route("[]", "tun0", "1.2.3.4")
        self.assertEqual(status, "unknown")


class ProbeTest(unittest.TestCase):
    def test_classify(self):
        self.assertEqual(monitor.classify_probe("connected")[0], "ok")
        self.assertEqual(monitor.classify_probe("refused")[0], "warn")
        self.assertEqual(monitor.classify_probe("timeout")[0], "down")
        self.assertEqual(monitor.classify_probe("error: nope")[0], "down")


class JournalTest(unittest.TestCase):
    def setUp(self):
        self.clock = monitor.Clock("America/Chicago")

    def test_parse_entries_and_levels(self):
        with open(os.path.join(FIXTURES, "journalctl.jsonl")) as fh:
            entries = monitor.parse_journal(fh.read(), self.clock)
        self.assertEqual(len(entries), 4)
        self.assertEqual(entries[0]["level"], "info")
        self.assertTrue(entries[0]["time_local"])

    def test_logs_ok_vs_warn_vs_unknown(self):
        self.assertEqual(monitor.classify_logs([])[0], "unknown")
        self.assertEqual(monitor.classify_logs([{"priority": 6}])[0], "ok")
        self.assertEqual(monitor.classify_logs([{"priority": 3}])[0], "warn")


class SnapshotTest(unittest.TestCase):
    def test_mock_snapshot_is_ok(self):
        cfg = monitor.load_config(os.path.join(os.path.dirname(FIXTURES), "config.json"))
        snap = monitor.build_snapshot(cfg, mock=True, fixtures_dir=FIXTURES)
        self.assertEqual(snap["overall"], "ok")
        self.assertTrue(snap["mock"])
        self.assertIn("service", snap["signals"])
        self.assertIn("tunnel", snap["signals"])
        # Every signal carries the normalized shape.
        for sig in snap["signals"].values():
            self.assertIn(sig["status"], monitor.STATUS_RANK)
            self.assertTrue(sig["checked_utc"])
            self.assertTrue(sig["checked_local"])


if __name__ == "__main__":
    unittest.main()
