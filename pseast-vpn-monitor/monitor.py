#!/usr/bin/env python3
"""pseast-vpn-monitor — read-only monitor for the OpenConnect F5 BIG-IP VPN tunnel.

Answers, at a glance: is the systemd service up, is tun0 actually carrying
traffic, and can the database be reached *through* the tunnel? It only observes —
it never starts/stops/reconfigures the service, tunnel, or routes, and never
handles VPN credentials (see the project brief, "Hard guardrails").

Two entry points:
    monitor.py [--mock]            print one JSON snapshot and exit
    monitor.py --serve [--mock]    run the web dashboard (see web.py-less http.server)

Every collector is observational: `systemctl show`, `journalctl -o json`,
`ip -j addr/route`, and a passive TCP connect (open socket, close — no data sent).
Any command a collector can't run degrades to status "unknown" — it never crashes
and never retries with sudo.

Standard library only.
"""

from __future__ import annotations

import argparse
import json
import os
import socket
import subprocess
import sys
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DEFAULT_CONFIG = os.path.join(SCRIPT_DIR, "config.json")
DEFAULT_FIXTURES = os.path.join(SCRIPT_DIR, "fixtures")

# Status vocabulary, ordered worst-last. The overall snapshot status is the
# worst of its parts; "unknown" ranks above "ok" (something we couldn't see) but
# below an observed problem.
STATUS_RANK = {"ok": 0, "unknown": 1, "warn": 2, "down": 3}

# journald numeric PRIORITY -> human level.
SYSLOG_LEVELS = {
    0: "emerg", 1: "alert", 2: "crit", 3: "err",
    4: "warning", 5: "notice", 6: "info", 7: "debug",
}


# --------------------------------------------------------------------------- #
# Config + small shared helpers
# --------------------------------------------------------------------------- #
def load_config(path: str) -> dict:
    """Load the JSON config. Everything environment-specific lives here."""
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def worst(statuses) -> str:
    """Return the worst status among the given ones (empty -> 'unknown')."""
    statuses = list(statuses)
    if not statuses:
        return "unknown"
    return max(statuses, key=lambda s: STATUS_RANK.get(s, 1))


class Clock:
    """Produces UTC + local (configured tz) ISO timestamps for a snapshot.

    A single Clock instance is shared across one snapshot so every signal in it
    carries a consistent 'checked at' pair.
    """

    def __init__(self, tz_name: str):
        self.tz_name = tz_name
        try:
            self._tz = ZoneInfo(tz_name)
        except Exception:
            self._tz = timezone.utc
            self.tz_name = "UTC"

    def now(self):
        utc = datetime.now(timezone.utc)
        return utc, utc.astimezone(self._tz)

    def stamps(self) -> tuple[str, str]:
        utc, local = self.now()
        return utc.isoformat(timespec="seconds"), local.isoformat(timespec="seconds")

    def to_local(self, dt_utc: datetime) -> datetime:
        return dt_utc.astimezone(self._tz)


def _signal(name: str, status: str, detail: str, clock: Clock, data=None) -> dict:
    """Normalize one collector result."""
    utc, local = clock.stamps()
    return {
        "signal": name,
        "status": status,
        "detail": detail,
        "checked_utc": utc,
        "checked_local": local,
        "data": data or {},
    }


class Runner:
    """Runs a command (live) or returns the contents of a fixture (mock).

    In mock mode no live command is ever executed: the collector is fed seeded
    output from fixtures/, so the whole snapshot can be produced with no running
    tunnel. Returns (ok, text, error) — ok is False when the command is missing,
    not permitted, timed out, or exited non-zero.
    """

    def __init__(self, mock: bool, fixtures_dir: str, timeout: int = 10):
        self.mock = mock
        self.fixtures_dir = fixtures_dir
        self.timeout = timeout

    def fixture(self, name: str) -> str:
        with open(os.path.join(self.fixtures_dir, name), "r", encoding="utf-8") as fh:
            return fh.read()

    def run(self, args: list[str], fixture_name: str) -> tuple[bool, str, str]:
        if self.mock:
            try:
                return True, self.fixture(fixture_name), ""
            except FileNotFoundError:
                return False, "", f"missing fixture {fixture_name}"
        try:
            proc = subprocess.run(
                args, capture_output=True, text=True, timeout=self.timeout
            )
        except FileNotFoundError:
            return False, "", f"command not found: {args[0]}"
        except PermissionError:
            return False, "", f"not permitted: {' '.join(args)}"
        except subprocess.TimeoutExpired:
            return False, "", f"timed out after {self.timeout}s"
        except OSError as exc:
            return False, "", str(exc)
        if proc.returncode != 0:
            return False, proc.stdout, (proc.stderr or f"exit {proc.returncode}").strip()
        return True, proc.stdout, proc.stderr


# --------------------------------------------------------------------------- #
# Pure parsers (no I/O) — unit-testable with crafted input
# --------------------------------------------------------------------------- #
def parse_systemctl_show(text: str) -> dict:
    """Parse `systemctl show` key=value lines into a dict."""
    props: dict[str, str] = {}
    for line in text.splitlines():
        if "=" in line:
            key, _, val = line.partition("=")
            props[key.strip()] = val.strip()
    return props


def parse_start_timestamp(raw: str, clock: Clock):
    """Parse systemd's ExecMainStartTimestamp (e.g. 'Mon 2025-06-29 14:00:00 CDT').

    systemd prints server-local time with a weekday and tz abbreviation. We pull
    out the YYYY-MM-DD and HH:MM:SS tokens and attach the configured tz (the
    server tz is assumed to match it). Returns an aware datetime, or None if the
    timestamp is empty/unparseable.
    """
    if not raw:
        return None
    date_tok = time_tok = None
    for tok in raw.split():
        if len(tok) == 10 and tok[4] == "-" and tok[7] == "-":
            date_tok = tok
        elif len(tok) == 8 and tok[2] == ":" and tok[5] == ":":
            time_tok = tok
    if not (date_tok and time_tok):
        return None
    try:
        naive = datetime.strptime(f"{date_tok} {time_tok}", "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return None
    return naive.replace(tzinfo=clock._tz if clock.tz_name != "UTC" else timezone.utc)


def human_uptime(delta_seconds: float) -> str:
    """Render a duration as e.g. '3d 4h 12m' (always at least '0m')."""
    secs = int(max(0, delta_seconds))
    days, secs = divmod(secs, 86400)
    hours, secs = divmod(secs, 3600)
    mins, _ = divmod(secs, 60)
    parts = []
    if days:
        parts.append(f"{days}d")
    if hours:
        parts.append(f"{hours}h")
    parts.append(f"{mins}m")
    return " ".join(parts)


def classify_service(props: dict, clock: Clock) -> tuple[str, str, dict]:
    """Map a systemd unit's state to (status, detail, data)."""
    active = props.get("ActiveState", "")
    sub = props.get("SubState", "")
    nrestarts = props.get("NRestarts", "0")
    pid = props.get("ExecMainPID", "")
    start_raw = props.get("ExecMainStartTimestamp", "")

    started = parse_start_timestamp(start_raw, clock)
    uptime = None
    if started is not None:
        utc, _ = clock.now()
        uptime = human_uptime((utc - started).total_seconds())

    if active == "active" and sub == "running":
        status = "ok"
    elif active in ("activating", "reloading", "deactivating"):
        status = "warn"
    elif active in ("failed", "inactive"):
        status = "down"
    else:
        status = "unknown"

    detail = f"{active or '?'}/{sub or '?'}"
    if uptime:
        detail += f", up {uptime}"
    detail += f", {nrestarts} restarts"

    data = {
        "active_state": active,
        "sub_state": sub,
        "n_restarts": _int_or_none(nrestarts),
        "main_pid": _int_or_none(pid),
        "started_local": clock.to_local(started).isoformat(timespec="seconds") if started else None,
        "uptime": uptime,
    }
    return status, detail, data


def parse_journal(text: str, clock: Clock) -> list[dict]:
    """Parse `journalctl -o json` (one JSON object per line) into log entries."""
    entries = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        prio = _int_or_none(obj.get("PRIORITY"))
        ts_us = _int_or_none(obj.get("__REALTIME_TIMESTAMP"))
        when_utc = when_local = None
        if ts_us is not None:
            dt = datetime.fromtimestamp(ts_us / 1_000_000, tz=timezone.utc)
            when_utc = dt.isoformat(timespec="seconds")
            when_local = clock.to_local(dt).isoformat(timespec="seconds")
        msg = obj.get("MESSAGE", "")
        if isinstance(msg, list):  # journald can encode binary messages as byte arrays
            msg = "".join(chr(b) for b in msg if isinstance(b, int))
        entries.append({
            "time_utc": when_utc,
            "time_local": when_local,
            "level": SYSLOG_LEVELS.get(prio, "info") if prio is not None else "info",
            "priority": prio,
            "message": str(msg),
        })
    return entries


def classify_logs(entries: list[dict]) -> tuple[str, str]:
    """Logs are informational; flag warn if any recent entry is error-or-worse."""
    if not entries:
        return "unknown", "no log entries"
    errs = [e for e in entries if (e.get("priority") is not None and e["priority"] <= 3)]
    if errs:
        return "warn", f"{len(errs)} error-level line(s) in the last {len(entries)}"
    return "ok", f"{len(entries)} recent line(s), no errors"


def classify_tunnel(ip_addr_json: str, tun_device: str) -> tuple[str, str, dict]:
    """From `ip -j addr show <dev>`, report up/down and the assigned address.

    Absence of the interface (empty array / no inet) means the tunnel is down.
    """
    try:
        arr = json.loads(ip_addr_json) if ip_addr_json.strip() else []
    except json.JSONDecodeError:
        return "unknown", "could not parse interface data", {}
    if not arr:
        return "down", f"{tun_device} not present (tunnel down)", {"present": False}

    iface = arr[0]
    operstate = iface.get("operstate", "UNKNOWN")
    addrs = [a.get("local") for a in iface.get("addr_info", []) if a.get("family") == "inet"]
    data = {"present": True, "operstate": operstate, "addresses": addrs}
    if not addrs:
        return "down", f"{tun_device} present but has no IPv4 address", data
    # tun interfaces commonly report operstate UNKNOWN even when carrying traffic.
    if operstate in ("UP", "UNKNOWN"):
        return "ok", f"{tun_device} up — {addrs[0]}", data
    return "warn", f"{tun_device} operstate {operstate} — {addrs[0]}", data


def classify_route(ip_route_json: str, tun_device: str, dest_ip: str) -> tuple[str, str, dict]:
    """From `ip -j route get <ip>`, confirm egress dev is the tunnel (not a leak)."""
    try:
        arr = json.loads(ip_route_json) if ip_route_json.strip() else []
    except json.JSONDecodeError:
        return "unknown", "could not parse route data", {}
    if not arr:
        return "unknown", f"no route returned for {dest_ip}", {}
    route = arr[0]
    dev = route.get("dev", "")
    data = {"dest": dest_ip, "dev": dev, "gateway": route.get("gateway")}
    if dev == tun_device:
        return "ok", f"{dest_ip} routes via {dev}", data
    return "warn", f"{dest_ip} egresses {dev or '?'}, not {tun_device} (route leak)", data


def classify_probe(result: str) -> tuple[str, str]:
    """Map a passive TCP probe result token to (status, detail)."""
    return {
        "connected": ("ok", "TCP connect succeeded"),
        "refused": ("warn", "connection refused (host reachable, port closed)"),
        "timeout": ("down", "connection timed out (unreachable)"),
    }.get(result, ("down", f"unreachable ({result})"))


def _int_or_none(val):
    try:
        return int(val)
    except (TypeError, ValueError):
        return None


# --------------------------------------------------------------------------- #
# Collectors — wire a Runner/parser to a normalized signal
# --------------------------------------------------------------------------- #
def collect_service(cfg, runner, clock) -> dict:
    """Service active state, restarts, PID and derived uptime via `systemctl show`."""
    unit = cfg["unit_name"]
    ok, out, err = runner.run(
        ["systemctl", "show", unit,
         "--property=ActiveState,SubState,NRestarts,ExecMainStartTimestamp,ExecMainPID"],
        "systemctl_show.txt",
    )
    if not ok:
        return _signal("service", "unknown", f"cannot read service state: {err}", clock)
    status, detail, data = classify_service(parse_systemctl_show(out), clock)
    return _signal("service", status, detail, clock, data)


def collect_logs(cfg, runner, clock) -> dict:
    """Recent journal lines for the unit (level + message + time)."""
    unit = cfg["unit_name"]
    n = int(cfg.get("log_lines", 50))
    ok, out, err = runner.run(
        ["journalctl", "-u", unit, "-n", str(n), "--no-pager", "-o", "json"],
        "journalctl.jsonl",
    )
    if not ok:
        return _signal("logs", "unknown", f"cannot read journal: {err}", clock)
    entries = parse_journal(out, clock)
    status, detail = classify_logs(entries)
    return _signal("logs", status, detail, clock, {"entries": entries})


def collect_tunnel(cfg, runner, clock) -> dict:
    """Presence + assigned address of the tunnel interface."""
    dev = cfg["tun_device"]
    ok, out, err = runner.run(["ip", "-j", "addr", "show", dev], "ip_addr_tun.json")
    if not ok:
        # `ip addr show <missing>` exits non-zero — treat as tunnel down, not unknown,
        # unless the tool itself is missing/not permitted.
        if err.startswith("command not found") or err.startswith("not permitted"):
            return _signal("tunnel", "unknown", f"cannot read interface: {err}", clock)
        return _signal("tunnel", "down", f"{dev} not present (tunnel down)", clock,
                       {"present": False})
    status, detail, data = classify_tunnel(out, dev)
    return _signal("tunnel", status, detail, clock, data)


def _resolve_host(host: str) -> tuple[str | None, str]:
    """Resolve a hostname to an IPv4 address (or pass through a literal IP)."""
    try:
        return socket.gethostbyname(host), ""
    except OSError as exc:
        return None, str(exc)


def collect_db_route(cfg, runner, clock) -> dict:
    """Confirm traffic to the DB host egresses the tunnel, not the physical NIC."""
    dev = cfg["tun_device"]
    host = cfg["db_host"]
    if runner.mock:
        ok, out, err = runner.run(["ip", "-j", "route", "get", host], "ip_route_get.json")
        if not ok:
            return _signal("db_route", "unknown", f"cannot read route: {err}", clock)
        status, detail, data = classify_route(out, dev, host)
        return _signal("db_route", status, detail, clock, data)

    dest_ip, rerr = _resolve_host(host)
    if dest_ip is None:
        return _signal("db_route", "unknown", f"cannot resolve {host}: {rerr}", clock)
    ok, out, err = runner.run(["ip", "-j", "route", "get", dest_ip], "ip_route_get.json")
    if not ok:
        return _signal("db_route", "unknown", f"cannot read route: {err}", clock)
    status, detail, data = classify_route(out, dev, dest_ip)
    return _signal("db_route", status, detail, clock, data)


def _tcp_probe(host: str, port: int, timeout: float) -> str:
    """Passive TCP connect: open a socket, close it immediately. No data sent."""
    try:
        conn = socket.create_connection((host, port), timeout=timeout)
        conn.close()
        return "connected"
    except ConnectionRefusedError:
        return "refused"
    except (socket.timeout, TimeoutError):
        return "timeout"
    except OSError as exc:
        return f"error: {exc}"


def collect_db_reachability(cfg, runner, clock) -> dict:
    """Passive TCP reachability of the DB through the tunnel (connect + close)."""
    host, port = cfg["db_host"], int(cfg["db_port"])
    timeout = float(cfg.get("probe_timeout_seconds", 3))
    if runner.mock:
        ok, out, err = runner.run([], "db_probe.txt")
        result = out.strip() if ok else "timeout"
    else:
        result = _tcp_probe(host, port, timeout)
    status, detail = classify_probe(result)
    return _signal("db_reachability", status, f"{host}:{port} — {detail}", clock,
                   {"host": host, "port": port, "result": result})


def collect_portal(cfg, runner, clock) -> dict | None:
    """Optional liveness of the F5 portal:443 (TCP connect only, no HTTP/auth)."""
    host = cfg.get("portal_host")
    if not host:
        return None
    timeout = float(cfg.get("probe_timeout_seconds", 3))
    if runner.mock:
        ok, out, err = runner.run([], "portal_probe.txt")
        result = out.strip() if ok else "timeout"
    else:
        result = _tcp_probe(host, 443, timeout)
    status, detail = classify_probe(result)
    # Portal is informational; never let it drag the overall status to "down".
    if status == "down":
        status = "warn"
    return _signal("portal", status, f"{host}:443 — {detail}", clock,
                   {"host": host, "port": 443, "result": result})


# --------------------------------------------------------------------------- #
# Snapshot assembly
# --------------------------------------------------------------------------- #
def build_snapshot(cfg: dict, mock: bool = False, fixtures_dir: str = DEFAULT_FIXTURES) -> dict:
    """Assemble all signals into one snapshot; overall status = worst of parts."""
    clock = Clock(cfg.get("timezone", "America/Chicago"))
    runner = Runner(mock, fixtures_dir, timeout=int(cfg.get("probe_timeout_seconds", 3)) + 7)

    signals = {
        "service": collect_service(cfg, runner, clock),
        "logs": collect_logs(cfg, runner, clock),
        "tunnel": collect_tunnel(cfg, runner, clock),
        "db_route": collect_db_route(cfg, runner, clock),
        "db_reachability": collect_db_reachability(cfg, runner, clock),
    }
    portal = collect_portal(cfg, runner, clock)
    if portal is not None:
        signals["portal"] = portal

    utc, local = clock.stamps()
    return {
        "overall": worst(s["status"] for s in signals.values()),
        "generated_utc": utc,
        "generated_local": local,
        "timezone": clock.tz_name,
        "mock": mock,
        "signals": signals,
    }


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #
def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Read-only OpenConnect VPN monitor.")
    parser.add_argument("--config", default=DEFAULT_CONFIG, help="path to config.json")
    parser.add_argument("--mock", action="store_true",
                        help="use seeded fixtures instead of live commands")
    parser.add_argument("--fixtures", default=DEFAULT_FIXTURES, help="fixtures dir for --mock")
    parser.add_argument("--serve", action="store_true", help="run the web dashboard")
    args = parser.parse_args(argv)

    try:
        cfg = load_config(args.config)
    except FileNotFoundError:
        print(f"config not found: {args.config}", file=sys.stderr)
        return 2
    except json.JSONDecodeError as exc:
        print(f"invalid config JSON: {exc}", file=sys.stderr)
        return 2

    if args.serve:
        # Imported lazily so the Phase-1 CLI has no web dependency at all.
        from web import serve
        return serve(cfg, mock=args.mock, fixtures_dir=args.fixtures)

    snapshot = build_snapshot(cfg, mock=args.mock, fixtures_dir=args.fixtures)
    print(json.dumps(snapshot, indent=2))
    # Exit non-zero when something is actually wrong, so cron/CI can notice.
    return 0 if snapshot["overall"] in ("ok", "unknown") else 1


if __name__ == "__main__":
    raise SystemExit(main())
