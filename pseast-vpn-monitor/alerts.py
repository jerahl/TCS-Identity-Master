#!/usr/bin/env python3
"""Phase 4 — alert hooks for pseast-vpn-monitor.

On a status transition INTO an alertable state (default down/warn), fire a
configurable command and/or webhook. **Disabled by default.** The monitor only
*reports* — it never acts on the tunnel; whatever the configured hook does is the
operator's responsibility, and this module sends it a JSON description of the
transition and nothing else.

Config (all under the "alerts" object):
    enabled              false by default
    on_status            states whose ONSET fires an alert (default ["down","warn"])
    command              argv list (preferred) or a string (shlex-split); the JSON
                         payload is passed both on stdin and as a final argument
    webhook_url          optional; receives an HTTP POST of the JSON payload
    min_interval_seconds debounce per target state (default 300)

Standard library only (subprocess + urllib for the webhook — no `requests`).
Never raises into the caller; every failure is captured in the result.
"""

from __future__ import annotations

import json
import shlex
import subprocess
import urllib.request
from datetime import datetime, timezone


def is_transition(prev_overall, new_overall, on_status) -> bool:
    """True when this snapshot enters an alertable state it wasn't in before.

    First observation (prev_overall is None) counts as a transition, so a monitor
    that starts up already down/warn alerts once.
    """
    if new_overall not in on_status:
        return False
    return prev_overall != new_overall


def build_payload(cfg: dict, prev_overall, snapshot: dict) -> dict:
    """A JSON-serializable description of the transition for the hook."""
    signals = {
        k: {"status": v.get("status"), "detail": v.get("detail")}
        for k, v in snapshot.get("signals", {}).items()
    }
    return {
        "event": "vpn_status_transition",
        "from": prev_overall,
        "to": snapshot.get("overall"),
        "generated_utc": snapshot.get("generated_utc"),
        "generated_local": snapshot.get("generated_local"),
        "timezone": snapshot.get("timezone"),
        "unit": cfg.get("unit_name"),
        "db_host": cfg.get("db_host"),
        "signals": signals,
    }


def _run_command(command, payload: dict, timeout: int) -> dict:
    args = list(command) if isinstance(command, (list, tuple)) else shlex.split(str(command))
    if not args:
        return {"channel": "command", "ok": False, "detail": "empty command"}
    body = json.dumps(payload)
    try:
        proc = subprocess.run(
            args + [body], input=body, text=True,
            capture_output=True, timeout=timeout,
        )
        ok = proc.returncode == 0
        detail = (proc.stderr or proc.stdout or f"exit {proc.returncode}").strip()
        return {"channel": "command", "ok": ok, "detail": detail[:300]}
    except FileNotFoundError:
        return {"channel": "command", "ok": False, "detail": f"not found: {args[0]}"}
    except subprocess.TimeoutExpired:
        return {"channel": "command", "ok": False, "detail": f"timed out after {timeout}s"}
    except OSError as exc:
        return {"channel": "command", "ok": False, "detail": str(exc)[:300]}


def _post_webhook(url: str, payload: dict, timeout: int) -> dict:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url, data=data, method="POST",
        headers={"Content-Type": "application/json", "User-Agent": "pseast-vpn-monitor"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:  # noqa: S310 (operator-set URL)
            code = getattr(resp, "status", resp.getcode())
        return {"channel": "webhook", "ok": 200 <= code < 300, "detail": f"HTTP {code}"}
    except Exception as exc:  # urllib raises many types; an alert must never crash the poll
        return {"channel": "webhook", "ok": False, "detail": str(exc)[:300]}


def maybe_alert(cfg: dict, prev: dict | None, snapshot: dict, store=None,
                now_epoch: int | None = None) -> dict:
    """Evaluate the transition and dispatch hooks. Returns a result summary.

    `store` (a HistoryStore) is used for per-state debouncing and to log fired
    alerts; it may be None (then debouncing is skipped).
    """
    acfg = cfg.get("alerts") or {}
    if not acfg.get("enabled"):
        return {"fired": False, "reason": "disabled"}

    on_status = acfg.get("on_status") or ["down", "warn"]
    new_overall = snapshot.get("overall")
    prev_overall = prev.get("overall") if prev else None
    if not is_transition(prev_overall, new_overall, on_status):
        return {"fired": False, "reason": "no transition", "from": prev_overall, "to": new_overall}

    now = now_epoch if now_epoch is not None else int(datetime.now(timezone.utc).timestamp())
    interval = int(acfg.get("min_interval_seconds", 300))
    if store is not None and interval > 0:
        last = store.last_alert_at(new_overall)
        if last is not None and (now - last) < interval:
            return {"fired": False, "reason": "debounced", "to": new_overall,
                    "seconds_since_last": now - last}

    timeout = int(cfg.get("probe_timeout_seconds", 3)) + 7
    payload = build_payload(cfg, prev_overall, snapshot)
    results = []
    if acfg.get("command"):
        results.append(_run_command(acfg["command"], payload, timeout))
    if acfg.get("webhook_url"):
        results.append(_post_webhook(acfg["webhook_url"], payload, timeout))
    if not results:
        results.append({"channel": "none", "ok": True, "detail": "no command/webhook configured"})

    if store is not None:
        store.log_alert(new_overall, now, results)

    return {
        "fired": True,
        "transition": f"{prev_overall}->{new_overall}",
        "results": results,
    }
