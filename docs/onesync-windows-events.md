# Collecting OneSync results from Windows Event Viewer

OneSync writes its per-user provisioning results to the **Windows Event Log**, not
to a file or our API directly. To get those success/failure messages onto the
person page, a small collector runs **on the OneSync host**, reads new events, and
forwards them to the sync-status API. The app side is unchanged — it already
accepts these events (see [`onesync-api.md`](onesync-api.md)).

```
OneSync ──logs──▶ Windows Event Viewer ──collect_onesync_events.ps1──▶ POST /api/onesync/sync-status ──▶ person page
```

Script: [`scripts/collect_onesync_events.ps1`](../scripts/collect_onesync_events.ps1).

---

## ⚠️ Confirm the event format first

The script's `Convert-EventToSyncStatus` function maps a Windows event to a
sync-status event. It must extract, per event:

- `uniqueId` — the person UUID OneSync read from `v_onesync_source.uniqueId`
- `destination` — AD / Google / Raptor / PowerSchool
- `status` — Success / Fail / Skipped (falls back to the event **level**:
  Error/Warning → Fail, Information → Success)
- `action`, `message`, `timestamp` — optional

The current patterns are **placeholders**. Open one real OneSync event in Event
Viewer → the event → **Details → XML View**, and confirm:

1. The **Provider/Source name** (set `-ProviderName`, default `OneSync`) and the
   **log** it lands in (`-LogName`, default `Application`, or a custom log).
2. Whether the data is in structured **`<EventData>`** named fields or only in the
   rendered **message text** (then tune the regexes).
3. Which field carries the `uniqueId`, and the destination/status wording.

Paste a sample event XML to the maintainer and the mapping can be finalized
exactly.

---

## Prerequisites

- PowerShell 5.1+ (built into Windows Server) or PowerShell 7.
- Network access from the OneSync host to the app over HTTPS.
- The API enabled: `ONESYNC_API_KEY` set on the app; the same value given to the
  script as `-ApiKey` / `IDM_API_KEY`.
- The account running the task can read the target event log (Administrators or
  the **Event Log Readers** group).

---

## Try it (dry run)

```powershell
$env:IDM_API_BASE = 'https://idm.example.org'
$env:IDM_API_KEY  = '<ONESYNC_API_KEY>'

# Validate connectivity + token
Invoke-RestMethod -Uri "$env:IDM_API_BASE/api/onesync/ping" -Headers @{Authorization="Bearer $env:IDM_API_KEY"}

# Parse + print recent events without posting
.\scripts\collect_onesync_events.ps1 -ProviderName 'OneSync' -LookbackMinutes 1440 -DryRun
```

`-DryRun` prints the mapped events and does **not** advance the high-water mark, so
you can iterate on the mapping safely. Drop `-DryRun` to post for real.

---

## Schedule it (Task Scheduler)

Run every 15 minutes as a service account in **Event Log Readers**:

```powershell
$key = '<ONESYNC_API_KEY>'
$action  = New-ScheduledTaskAction -Execute 'powershell.exe' `
  -Argument '-NoProfile -ExecutionPolicy Bypass -File "C:\idm\collect_onesync_events.ps1" -ApiBase https://idm.example.org -ApiKey ' + $key
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
  -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration ([TimeSpan]::MaxValue)
$principal = New-ScheduledTaskPrincipal -UserId 'DOMAIN\svc_idm' -LogonType Password -RunLevel Limited
Register-ScheduledTask -TaskName 'IDM OneSync event collector' -Action $action -Trigger $trigger -Principal $principal
```

Prefer not to put the key on the command line in production — store it in a
machine/user environment variable (`IDM_API_KEY`) or a DPAPI-protected file and
have the script read it. The high-water state file
(`.onesync_events_state.json`) sits next to the script; keep it writable by the
task account.

---

## How it works

1. Reads the last-run timestamp from `-StatePath` (else uses `-LookbackMinutes`).
2. `Get-WinEvent` for the provider since that time, oldest-first.
3. Maps each via `Convert-EventToSyncStatus`; skips events that aren't
   provisioning results (no uniqueId/destination).
4. POSTs in batches to `/api/onesync/sync-status` (`Authorization: Bearer`).
5. Advances the high-water mark to the newest event **after** a successful post,
   so a failed post is retried next run.

Each event becomes one row per `(person, destination)` in `account_sync_status`
(current state, shown on the person's Provisioning panel) plus a capped history
row — failures surface per-person and on the dashboard. Same guardrails and
storage as every other sync-status path.

---

## Notes

- **Per-user logging:** OneSync logs one event per user-sync; the collector reads
  them all from the provider regardless of which user context produced them. If
  OneSync instead logs under multiple providers/logs, run the script once per
  `-ProviderName`/`-LogName`, or extend the filter.
- **Successes too:** by default it forwards Information events as `Success` so the
  Provisioning panel shows green, not just failures. Pass `-Levels Error,Warning`
  to forward failures only.
- **Time zone:** event timestamps are sent as ISO 8601 from the local clock; the
  app parses them as-is.
