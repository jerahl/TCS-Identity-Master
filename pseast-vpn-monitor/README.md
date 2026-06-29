# pseast-vpn-monitor

A small, **read-only** web monitor for the OpenConnect F5 BIG-IP VPN tunnel
(`openconnect-pseast.service`) and the route to the database behind it, on a
Debian 12 server. It answers, at a glance:

- Is the systemd service up (and how long, how many restarts)?
- Is `tun0` present and carrying an APM-assigned address?
- Does traffic to the DB host egress the **tunnel** (not leak out the physical NIC)?
- Can the DB port be reached through it (passive TCP connect)?

It **only observes**. It never starts, stops, restarts, or reconfigures the
service, tunnel, or routes, and never reads VPN credentials or touches the F5
portal with auth. See [Guardrails](#guardrails).

Standard library only (Python 3.11 on Debian 12) — no Flask/requests, no build
step, no CDN.

---

## Quick start

```sh
cd pseast-vpn-monitor
cp config.example.json config.json     # then edit db_host etc.

# Phase 1 — print one JSON snapshot:
python3 monitor.py --mock               # seeded fixtures, no live commands
python3 monitor.py                      # live (reads systemctl/ip/journalctl)

# Phase 2 — run the dashboard:
python3 monitor.py --serve --mock       # http://127.0.0.1:8787/  (mock data)
python3 monitor.py --serve              # live
```

Open `http://127.0.0.1:8787/`. The page polls `/api/status` on the configured
interval, colors a card per signal, and shows a banner if a poll fails.

`--mock` produces the entire snapshot from `fixtures/` with **no live commands and
no running tunnel** — build and validate against it before pointing at the live
system. See [`SAMPLE_OUTPUT.md`](SAMPLE_OUTPUT.md) for the JSON shape.

---

## Configuration

One file, `config.json` (copy from `config.example.json`). No site values are
hardcoded in the code.

| Key | Meaning |
|---|---|
| `unit_name` | systemd unit to inspect (`openconnect-pseast.service`) |
| `tun_device` | tunnel interface name (`tun0`) |
| `db_host` / `db_port` | MariaDB reached through the tunnel (route + TCP probe target) |
| `portal_host` | F5 portal host for an optional `:443` liveness probe (omit/empty to skip) |
| `mgmt_subnet` | management subnet — **display/context only**, the monitor never edits routes |
| `poll_interval_seconds` | dashboard poll cadence + server-side snapshot cache TTL |
| `log_lines` | how many recent journal lines to show |
| `listen_addr` / `listen_port` | where the web server binds (default `127.0.0.1:8787`) |
| `timezone` | IANA tz for displayed local timestamps (`America/Chicago`) |
| `probe_timeout_seconds` | TCP connect timeout for the passive probes |
| `history_enabled` | record each served snapshot to sqlite for the uptime view (default `true`) |
| `history_db` | sqlite path (relative paths resolve next to the script; default `history.sqlite3`) |
| `history_retention_days` | prune history older than this on each write (default `7`) |
| `history_recent` | how many recent states `/api/history` returns (default `20`) |
| `alerts` | alert-hook settings — see [Alerts](#alerts-phase-4) (disabled by default) |

---

## Endpoints

| Route | Returns |
|---|---|
| `GET /` | the single-page dashboard |
| `GET /api/status` | the JSON snapshot (re-collected at most once per `poll_interval_seconds`) |
| `GET /api/history` | uptime % / last-flap / recent states (`{"enabled": false}` when history is off) |
| `GET /healthz` | liveness of the monitor process itself |

`monitor.py` exits non-zero when the overall status is `warn`/`down`, so it can
double as a cron/CI check (`unknown` and `ok` exit 0). `monitor.py --history`
prints the uptime/last-flap summary.

---

## History (Phase 3)

When `history_enabled` is set, each snapshot the server collects is appended to a
small sqlite database (`history_db`) and pruned to `history_retention_days`. From
that, `/api/history` (and the dashboard's top strip) derives a **time-weighted
uptime %**, the **current state and how long it's held**, a **flap count**, and a
recent-states sparkline. Timestamps are America/Chicago-aware. The DB is created
on first run and is gitignored — nothing to provision.

---

## Alerts (Phase 4)

Off by default. When `alerts.enabled` is true, a transition of the **overall**
status *into* one of `alerts.on_status` (default `down`/`warn`) fires a configured
command and/or webhook. The monitor only **reports** — it never acts on the
tunnel; whatever the hook does is the operator's responsibility, and it receives a
JSON description of the transition (on stdin and as a final argument for the
command; as an HTTP POST body for the webhook).

```jsonc
"alerts": {
  "enabled": true,
  "on_status": ["down", "warn"],     // transitions INTO these fire
  "command": ["/usr/local/bin/notify-oncall"],  // argv list (preferred) or a string
  "webhook_url": "https://chat.example.org/hooks/xxxx",
  "min_interval_seconds": 300        // debounce per target state
}
```

Repeated alerts for the same state are debounced for `min_interval_seconds`. A
hook that fails (non-zero exit, timeout, HTTP error) is recorded but never crashes
the poll. Keep the hook itself read-only/notify-only.

---

## Run as a service

Run as an **unprivileged** account (not root). Example unit
(`/etc/systemd/system/pseast-vpn-monitor.service`):

```ini
[Unit]
Description=PSEast VPN monitor (read-only)
After=network-online.target

[Service]
Type=exec
User=vpnmon
Group=vpnmon
SupplementaryGroups=systemd-journal adm
WorkingDirectory=/opt/pseast-vpn-monitor
ExecStart=/usr/bin/python3 /opt/pseast-vpn-monitor/monitor.py --serve --config /opt/pseast-vpn-monitor/config.json
Restart=on-failure
# Hardening — this process only reads:
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadOnlyPaths=/opt/pseast-vpn-monitor

[Install]
WantedBy=multi-user.target
```

```sh
sudo useradd --system --no-create-home --shell /usr/sbin/nologin vpnmon
sudo usermod -aG systemd-journal,adm vpnmon     # journal read access (see below)
sudo systemctl daemon-reload
sudo systemctl enable --now pseast-vpn-monitor.service
```

---

## Privileges

Everything the monitor runs is read-only and needs **no root and no sudo**:

- `systemctl show …` and `ip -j addr/route …` are unprivileged reads.
- Reading a **system** unit's journal (`journalctl -u …`) requires the runtime
  user to be in the **`systemd-journal`** group (and typically **`adm`**). Add the
  service account to those groups; do **not** run the monitor as root.
- If any command isn't permitted (or the binary is missing), that collector
  degrades to status **`unknown`** with a clear message — it never crashes and
  never retries with sudo.

The DB/portal checks are **passive TCP connects**: open a socket, close it
immediately. No bytes are sent, no auth, no query.

---

## Exposure

Binds to `127.0.0.1` by default. To reach it from elsewhere, either:

- bind to the management IP only (`listen_addr` = that NIC's address), and/or
- put it behind a reverse proxy (nginx/Apache) that terminates TLS and adds auth.

There is no authentication in the app itself — it serves read-only status, so
keep it off untrusted networks.

---

## Tests

```sh
python3 -m unittest -v
```

Pure parsers/classifiers are unit-tested with crafted input, including the two
acceptance scenarios — **tunnel down** (`tun0` absent) and a **route leak**
(DB egress dev ≠ `tun0`) — plus a full `--mock` snapshot assembly. Phase 3/4 tests
cover the history store (record/retention/time-weighted uptime) and alert
dispatch (transition detection, command success/failure, and debounce) against a
temp sqlite db with no network. 28 tests total.

---

## Guardrails

- **Read-only.** No `systemctl start/stop/restart`, no `ip route add/del`, no
  writes to the unit, scripts, or routes. There is no code path that can mutate
  the service, tunnel, routes, or config.
- **No credentials.** Never reads `pseast.pwd` or any secret; never authenticates
  to the portal.
- Any future control capability (start/stop) is **out of scope** and, if ever
  added, must be a separate opt-in phase behind explicit auth and a
  narrowly-scoped sudoers/polkit rule.

---

## Status / roadmap

- **Phase 1 — collectors + JSON snapshot (mock + live).** ✅ Done (`monitor.py`).
- **Phase 2 — web layer + dashboard.** ✅ Done (`web.py`, `index.html`).
- **Phase 3 — history & retention (sqlite).** ✅ Done (`history.py`, `/api/history`).
- **Phase 4 — alert hooks on status transitions (read-only).** ✅ Done (`alerts.py`).

## Files

```
monitor.py              collectors, parsers, snapshot assembly, CLI
web.py                  http.server dashboard + /api/status + /api/history + /healthz
history.py              Phase 3 — sqlite history store + uptime/flap summary
alerts.py               Phase 4 — command/webhook hooks on status transitions
index.html              single-page dashboard (vanilla JS/CSS) + history strip
config.json             live config (gitignore in real deploys if it holds site values)
config.example.json     documented template
fixtures/               seeded data for --mock
test_monitor.py         Phase 1/2 unit tests
test_history_alerts.py  Phase 3/4 unit tests
SAMPLE_OUTPUT.md        the JSON shape
```

Run all tests with `python3 -m unittest` (28 tests).
