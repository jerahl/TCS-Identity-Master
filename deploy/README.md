# Deploy units

systemd units for the scheduled jobs. Adjust `WorkingDirectory`, `User`, the PHP
path, and the `OnCalendar` time to your install before enabling.

## Adaxes API token (`New-AdaxesApiToken.ps1`)

PowerShell helper to mint the `ADAXES_TOKEN` used by the live AD verification
panel. Run it on a **Windows host that can reach the Adaxes REST API** (it uses
only `Invoke-RestMethod` — no Adaxes module needed), supplying a **read-only**
service account. It runs the 2025.1 auth handshake (`/api/authSessions/create`
→ `/api/auth`) and prints the token.

```powershell
# Print the token
.\New-AdaxesApiToken.ps1 -BaseUrl https://adaxes.tusc.k12.al.us/restApi -Username 'TCS\svc-idm-read'

# …and upsert ADAXES_TOKEN= straight into the app .env
.\New-AdaxesApiToken.ps1 -BaseUrl https://adaxes.tusc.k12.al.us/restApi `
    -Username 'svc-idm-read@tusc.k12.al.us' -UpdateEnvFile \\app-host\idm\.env
```

A REST-issued token only lives as long as the Adaxes REST API auth timeout
(~30 min by default), so this is best for testing. For a permanent setting,
raise that timeout in Adaxes, use `New-AdmAccountToken` on 2026.1+, or run the
app in `ADAXES_USERNAME`/`ADAXES_PASSWORD` mode (it handles tokens per request).
`-SkipCertificateCheck` bypasses TLS for an internal CA (prefer trusting the CA).

## OneSync DB result importer

Pulls per-destination provisioning status + failure messages from OneSync's
MariaDB into `account_sync_status` (`bin/import_onesync_db.php`). Requires
`ONESYNC_DB_*` configured in the app `.env`.

```sh
# Install
sudo cp deploy/idm-onesync-db.service /etc/systemd/system/
sudo cp deploy/idm-onesync-db.timer   /etc/systemd/system/
sudo systemctl daemon-reload

# Run once now to verify
sudo systemctl start idm-onesync-db.service
journalctl -u idm-onesync-db.service -n 100 --no-pager

# Enable the nightly timer
sudo systemctl enable --now idm-onesync-db.timer
systemctl list-timers idm-onesync-db.timer
```

`Type=oneshot` won't start a second copy while one is running. `Persistent=true`
runs a missed job after downtime. Logs go to the journal
(`journalctl -u idm-onesync-db.service`).

## Students passthrough

Pulls active/future student enrollments from PowerSchool over ODBC into the
`student` table that OneSync reads (`bin/import_students.php`). Requires
`PS_ODBC_*` configured in the app `.env` (same connection as the staff import).

```sh
sudo cp deploy/idm-students.service /etc/systemd/system/
sudo cp deploy/idm-students.timer   /etc/systemd/system/
sudo systemctl daemon-reload

# Run once now to verify
sudo systemctl start idm-students.service
journalctl -u idm-students.service -n 100 --no-pager

# Enable the nightly timer (runs before OneSync's nightly sync)
sudo systemctl enable --now idm-students.timer
systemctl list-timers idm-students.timer
```

Schedule it to finish **before** OneSync's run so it pulls the freshest students.

The same service/timer pattern applies to the SFTP feed pull
(`bin/fetch_feeds.php`) — see [`../docs/cron-feed-pull.md`](../docs/cron-feed-pull.md)
for those unit definitions and crontab alternatives.
