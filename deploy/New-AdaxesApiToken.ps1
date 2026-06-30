#Requires -Version 5.1
<#
.SYNOPSIS
    Mint an Adaxes REST API security token for TCS Identity Master (ADAXES_TOKEN).

.DESCRIPTION
    Runs the Adaxes REST API legacy authentication handshake — create an
    authentication session, then exchange it for a security token — which is the
    supported flow on Adaxes 2025.1. Prints the token to paste into the app's
    `ADAXES_TOKEN` setting (and can upsert it into a .env file for you).

    Uses only Invoke-RestMethod; the Adaxes PowerShell module is NOT required.
    Run it on any host that can reach the Adaxes REST API over HTTPS, supplying a
    READ-ONLY service account (grant it only "Read" on the user OUs you verify —
    never a write/admin account).

    TOKEN LIFETIME: a token obtained this way lives only as long as the Adaxes
    REST API authentication timeout (default ~30 minutes), so it is best for
    testing, not a permanent setting. For a long-lived service token:
      * raise the REST API auth timeout in Adaxes
        (Configuration > Web Interface / REST API > authentication timeout), or
      * on Adaxes 2026.1+ use the New-AdmAccountToken cmdlet with a lifetime, or
      * skip the static token entirely and run the app in username/password mode
        (set ADAXES_USERNAME/ADAXES_PASSWORD) — it mints and tears down a token
        per verification automatically.

.PARAMETER BaseUrl
    REST API root (no trailing slash), e.g. https://adaxes.tusc.k12.al.us/restApi

.PARAMETER Username
    Service account, e.g. 'TCS\svc-idm-read' or 'svc-idm-read@tusc.k12.al.us'.

.PARAMETER Password
    The account password as a SecureString. Omit to be prompted securely.

.PARAMETER UpdateEnvFile
    Optional path to a .env file; the script upserts the ADAXES_TOKEN= line.

.PARAMETER SkipCertificateCheck
    Bypass TLS validation (for an internal/self-signed CA). Discouraged —
    prefer trusting the CA on this host.

.EXAMPLE
    .\New-AdaxesApiToken.ps1 -BaseUrl https://adaxes.tusc.k12.al.us/restApi -Username 'TCS\svc-idm-read'

.EXAMPLE
    .\New-AdaxesApiToken.ps1 -BaseUrl https://adaxes.tusc.k12.al.us/restApi `
        -Username 'svc-idm-read@tusc.k12.al.us' -UpdateEnvFile C:\idm\.env
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$BaseUrl,
    [Parameter(Mandatory)][string]$Username,
    [System.Security.SecureString]$Password,
    [string]$UpdateEnvFile,
    [switch]$SkipCertificateCheck
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

if (-not $Password) {
    $Password = Read-Host -AsSecureString "Password for $Username"
}
# Decode the SecureString just long enough to build the JSON body.
$plainPassword = [System.Net.NetworkCredential]::new('', $Password).Password

# Optional TLS bypass for an internal/self-signed CA (discouraged).
$common = @{}
if ($SkipCertificateCheck) {
    if ($PSVersionTable.PSVersion.Major -ge 6) {
        $common['SkipCertificateCheck'] = $true
    } else {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    }
}

try {
    # 1) Create an authentication session.
    $sessionBody = @{ username = $Username; password = $plainPassword } | ConvertTo-Json
    $session = Invoke-RestMethod @common -Method POST -Uri "$BaseUrl/api/authSessions/create" `
        -Body $sessionBody -ContentType 'application/json'
    if (-not $session.sessionId) {
        throw "No sessionId returned from $BaseUrl/api/authSessions/create — check the base URL and credentials."
    }

    # 2) Exchange the session for a security token.
    $tokenBody = @{ sessionId = $session.sessionId } | ConvertTo-Json
    $ticket = Invoke-RestMethod @common -Method POST -Uri "$BaseUrl/api/auth" `
        -Body $tokenBody -ContentType 'application/json'
    if (-not $ticket.token) {
        throw "No token returned from $BaseUrl/api/auth."
    }
}
catch {
    Write-Error "Failed to obtain an Adaxes token: $($_.Exception.Message)"
    exit 1
}
finally {
    # Don't leave the plaintext password sitting in memory.
    $plainPassword = $null
}

$token = $ticket.token

Write-Host ""
Write-Host "Adaxes API token created." -ForegroundColor Green
if ($ticket.expiresAtUtc) {
    Write-Host "Expires (UTC): $($ticket.expiresAtUtc)  (lifetime = the REST API auth timeout)" -ForegroundColor Yellow
}
Write-Host ""
Write-Host "Paste this into the app's .env (treat it as a secret):"
Write-Host "ADAXES_TOKEN=$token"
Write-Host ""

if ($UpdateEnvFile) {
    $newLine = "ADAXES_TOKEN=$token"
    $lines = if (Test-Path -LiteralPath $UpdateEnvFile) { @(Get-Content -LiteralPath $UpdateEnvFile) } else { @() }

    $found = $false
    $out = foreach ($line in $lines) {
        if ($line -match '^\s*ADAXES_TOKEN=') { $found = $true; $newLine } else { $line }
    }
    if (-not $found) { $out += $newLine }

    # Avoid -replace so a '$' in the token isn't treated as a backreference.
    Set-Content -LiteralPath $UpdateEnvFile -Value $out -Encoding UTF8
    Write-Host "Upserted ADAXES_TOKEN in $UpdateEnvFile" -ForegroundColor Green
    Write-Host ""
}

Write-Host "Reminder: this token expires (see above). For a permanent setting, raise the" -ForegroundColor DarkGray
Write-Host "Adaxes REST API auth timeout, use New-AdmAccountToken on 2026.1+, or run the app" -ForegroundColor DarkGray
Write-Host "in ADAXES_USERNAME/ADAXES_PASSWORD mode (it handles tokens automatically)." -ForegroundColor DarkGray
