<#
.SYNOPSIS
  Forward OneSync sync results from the Windows Event Log to the TCS Identity
  Master sync-status API.

.DESCRIPTION
  OneSync writes its per-user provisioning results to Windows Event Viewer. This
  script (run on the OneSync host as a Scheduled Task) reads new events since the
  last run, maps each to a sync-status event, and POSTs them in a batch to
  POST /api/onesync/sync-status (see docs/onesync-api.md). The app side needs no
  change — it already accepts these events.

  Idempotent: a high-water timestamp is stored in -StatePath so each run only
  forwards events newer than the last. Safe to run every few minutes.

.PARAMETER ApiBase   Base URL, e.g. https://idm.example.org (env: IDM_API_BASE)
.PARAMETER ApiKey    ONESYNC_API_KEY value (env: IDM_API_KEY)
.PARAMETER LogName   Event log to read (default Application)
.PARAMETER ProviderName  Event source/provider OneSync logs under (CONFIRM)
.PARAMETER DryRun    Parse + print, do not POST

.EXAMPLE
  ./collect_onesync_events.ps1 -ApiBase https://idm.example.org -ApiKey $key -DryRun
#>
[CmdletBinding()]
param(
    [string]   $ApiBase       = $env:IDM_API_BASE,
    [string]   $ApiKey        = $env:IDM_API_KEY,
    [string]   $LogName       = 'Application',
    [string]   $ProviderName  = 'OneSync',
    [string[]] $Levels        = @('Error', 'Warning', 'Information'),
    [string]   $StatePath     = "$PSScriptRoot\.onesync_events_state.json",
    [int]      $LookbackMinutes = 1440,
    [int]      $BatchSize      = 200,
    [switch]   $DryRun
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Level (int) -> a default status when the message doesn't state one.
# 1=Critical 2=Error 3=Warning 4=Information.
function Get-StatusFromLevel([int]$level) {
    if ($level -le 3) { return 'Fail' }   # Critical/Error/Warning
    return 'Success'
}

# ---------------------------------------------------------------------------
# CONFIRM THIS MAPPING with a real OneSync event (Event Viewer > the event >
# Details > XML view). Adjust the regexes / EventData field names to match.
#
# Must return a hashtable with at least uniqueId + destination, or $null to skip.
#   uniqueId    - the person UUID OneSync read from v_onesync_source.uniqueId
#   destination - AD / Google / Raptor / PowerSchool (free text)
#   action      - Add/Edit/Disable/Enable/NoChange (optional)
#   status      - Success/Fail/Skipped (optional; falls back to the event level)
#   message     - failure detail (optional)
#   timestamp   - ISO 8601 (we set it from the event time)
# ---------------------------------------------------------------------------
function Convert-EventToSyncStatus {
    param($Event)

    $msg = [string]$Event.Message
    if (-not $msg) { return $null }

    # Try structured EventData first (if OneSync logs named fields), else regex
    # the message text. These patterns are placeholders — tune to the real format.
    $uniqueId = $null; $destination = $null; $action = $null; $status = $null

    if ($msg -match '(?im)uniqueId[:=]\s*([0-9a-f\-]{8,})')        { $uniqueId    = $Matches[1] }
    elseif ($msg -match '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})') { $uniqueId = $Matches[1] }
    if ($msg -match '(?im)destination[:=]\s*([^\r\n,;]+)')          { $destination = $Matches[1].Trim() }
    if ($msg -match '(?im)action[:=]\s*([A-Za-z]+)')               { $action      = $Matches[1] }
    if ($msg -match '(?im)\b(success|succeeded|fail(?:ed)?|skipped|nochange)\b') { $status = $Matches[1] }

    if (-not $uniqueId -or -not $destination) { return $null }  # not a provisioning event

    $evt = @{
        uniqueId    = $uniqueId
        destination = $destination
        timestamp   = $Event.TimeCreated.ToString('o')
    }
    if ($action)  { $evt.action  = $action }
    $evt.status   = if ($status) { $status } else { Get-StatusFromLevel ([int]$Event.Level) }
    if ($Event.Level -le 3) { $evt.message = ($msg -split "`n")[0].Trim() }  # first line of error
    return $evt
}

# --- read state (high-water mark) ------------------------------------------
$since = (Get-Date).AddMinutes(-1 * $LookbackMinutes)
if (Test-Path $StatePath) {
    try {
        $state = Get-Content $StatePath -Raw | ConvertFrom-Json
        if ($state.lastTime) { $since = [datetime]$state.lastTime }
    } catch { Write-Warning "Could not read state ($StatePath); using lookback window." }
}

if (-not $ApiBase -or -not $ApiKey) { throw 'Set -ApiBase and -ApiKey (or IDM_API_BASE / IDM_API_KEY).' }

# --- read events -----------------------------------------------------------
$filter = @{ LogName = $LogName; ProviderName = $ProviderName; StartTime = $since }
$events = @()
try {
    $events = Get-WinEvent -FilterHashtable $filter -ErrorAction Stop | Sort-Object TimeCreated
} catch [Exception] {
    if ($_.Exception.Message -match 'No events were found') { Write-Host 'No new OneSync events.'; return }
    throw
}

$mapped = @()
$newest = $since
foreach ($e in $events) {
    if ($Levels -and ($e.LevelDisplayName) -and ($Levels -notcontains $e.LevelDisplayName)) { continue }
    $m = Convert-EventToSyncStatus -Event $e
    if ($m) { $mapped += $m }
    if ($e.TimeCreated -gt $newest) { $newest = $e.TimeCreated }
}

Write-Host ("Read {0} event(s); {1} mapped to sync-status." -f $events.Count, $mapped.Count)
if ($mapped.Count -eq 0) {
    if (-not $DryRun) { @{ lastTime = $newest.ToString('o') } | ConvertTo-Json | Set-Content $StatePath }
    return
}

if ($DryRun) {
    $mapped | ConvertTo-Json -Depth 4 | Write-Host
    Write-Host '(dry run — nothing posted, state not advanced)'
    return
}

# --- POST in batches -------------------------------------------------------
$uri = ($ApiBase.TrimEnd('/')) + '/api/onesync/sync-status'
$headers = @{ Authorization = "Bearer $ApiKey" }
for ($i = 0; $i -lt $mapped.Count; $i += $BatchSize) {
    $batch = $mapped[$i..([Math]::Min($i + $BatchSize - 1, $mapped.Count - 1))]
    $body = (ConvertTo-Json @($batch) -Depth 4)
    $resp = Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -ContentType 'application/json' -Body $body
    Write-Host ("Posted {0} event(s): ok={1}" -f $batch.Count, $resp.ok)
}

# advance high-water mark only after a successful post
@{ lastTime = $newest.ToString('o') } | ConvertTo-Json | Set-Content $StatePath
Write-Host ("State advanced to {0}" -f $newest.ToString('o'))
