# Sample output

The JSON shape returned by `monitor.py --mock` (and `GET /api/status`). Each
signal is normalized to `status` (`ok`/`warn`/`down`/`unknown`), a human
`detail`, UTC + America/Chicago timestamps, and a `data` payload. The overall
status is the worst of the parts.

```json
{
  "overall": "ok",
  "generated_utc": "2026-06-29T18:31:20+00:00",
  "generated_local": "2026-06-29T13:31:20-05:00",
  "timezone": "America/Chicago",
  "mock": true,
  "signals": {
    "service": {
      "signal": "service",
      "status": "ok",
      "detail": "active/running, up 3d 11h 0m, 1 restarts",
      "checked_utc": "2026-06-29T18:31:20+00:00",
      "checked_local": "2026-06-29T13:31:20-05:00",
      "data": {
        "active_state": "active",
        "sub_state": "running",
        "n_restarts": 1,
        "main_pid": 2417,
        "started_local": "2026-06-26T02:31:14-05:00",
        "uptime": "3d 11h 0m"
      }
    },
    "logs": {
      "signal": "logs",
      "status": "ok",
      "detail": "4 recent line(s), no errors",
      "checked_utc": "2026-06-29T18:31:20+00:00",
      "checked_local": "2026-06-29T13:31:20-05:00",
      "data": {
        "entries": [
          {
            "time_utc": "2026-06-29T02:31:14+00:00",
            "time_local": "2026-06-28T21:31:14-05:00",
            "level": "info",
            "priority": 6,
            "message": "Connected to HTTPS on pseastvpn.powerschool.com with ESP"
          },
          {
            "time_utc": "2026-06-29T02:31:14+00:00",
            "time_local": "2026-06-28T21:31:14-05:00",
            "level": "info",
            "priority": 6,
            "message": "Configured as 10.123.45.67, with SSL disconnect and DTLS connect"
          },
          {
            "time_utc": "2026-06-29T02:31:15+00:00",
            "time_local": "2026-06-28T21:31:15-05:00",
            "level": "info",
            "priority": 6,
            "message": "vpnc-script: route-guard kept 10.10.0.0/24 on physical NIC"
          },
          {
            "time_utc": "2026-06-29T02:42:00+00:00",
            "time_local": "2026-06-28T21:42:00-05:00",
            "level": "notice",
            "priority": 5,
            "message": "Established DTLS connection (using GnuTLS); ciphersuite (DTLS1.2)"
          }
        ]
      }
    },
    "tunnel": {
      "signal": "tunnel",
      "status": "ok",
      "detail": "tun0 up \u2014 10.123.45.67",
      "checked_utc": "2026-06-29T18:31:20+00:00",
      "checked_local": "2026-06-29T13:31:20-05:00",
      "data": {
        "present": true,
        "operstate": "UNKNOWN",
        "addresses": [
          "10.123.45.67"
        ]
      }
    },
    "db_route": {
      "signal": "db_route",
      "status": "ok",
      "detail": "172.23.169.131 routes via tun0",
      "checked_utc": "2026-06-29T18:31:20+00:00",
      "checked_local": "2026-06-29T13:31:20-05:00",
      "data": {
        "dest": "172.23.169.131",
        "dev": "tun0",
        "gateway": null
      }
    },
    "db_reachability": {
      "signal": "db_reachability",
      "status": "ok",
      "detail": "172.23.169.131:3306 \u2014 TCP connect succeeded",
      "checked_utc": "2026-06-29T18:31:20+00:00",
      "checked_local": "2026-06-29T13:31:20-05:00",
      "data": {
        "host": "172.23.169.131",
        "port": 3306,
        "result": "connected"
      }
    },
    "portal": {
      "signal": "portal",
      "status": "ok",
      "detail": "pseastvpn.powerschool.com:443 \u2014 TCP connect succeeded",
      "checked_utc": "2026-06-29T18:31:20+00:00",
      "checked_local": "2026-06-29T13:31:20-05:00",
      "data": {
        "host": "pseastvpn.powerschool.com",
        "port": 443,
        "result": "connected"
      }
    }
  }
}
```

## A degraded example

When `tun0` is absent the tunnel reports `down`; if the DB route egresses the
physical NIC instead of `tun0` the `db_route` signal reports `warn` (leak), and
the overall status follows the worst signal.
