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

## Google Workspace sync

Reconciles the golden record directly to Google Workspace, bypassing OneSync
(`bin/sync_google.php`). Requires the `GOOGLE_*` service-account settings in the
app `.env`.

```sh
sudo cp deploy/idm-google-sync.service /etc/systemd/system/
sudo cp deploy/idm-google-sync.timer   /etc/systemd/system/
sudo systemctl daemon-reload

# Dry-run once to preview what it would change (writes nothing)
sudo -u www-data php /var/www/idm/bin/sync_google.php --dry-run

# Run once now for real to verify
sudo systemctl start idm-google-sync.service
journalctl -u idm-google-sync.service -n 100 --no-pager

# Enable the nightly timer
sudo systemctl enable --now idm-google-sync.timer
systemctl list-timers idm-google-sync.timer
```

**Enable the `.timer`, not the `.service`.** Like every job here, the `.service`
is a `Type=oneshot` unit triggered by its timer, so it has no `[Install]`
section — `systemctl enable idm-google-sync.service` will fail with *"unit files
have no installation config"*. That is expected: enabling the **timer** is what
schedules it; you only `start` the service to run it on demand. Schedule it to
run **after** the nightly feed imports so it reconciles the freshest golden
record.

**"Run Google sync now" button (optional).** A full sync does a live Google
lookup per person, so it can't run in the web request — doing so ties up a
PHP-FPM worker for minutes and exhausts `pm.max_children` (nginx 504). The button
on the *Import & feeds* page instead asks systemd to start the oneshot unit in the
background, exactly like the AD sync. Turn it on with `GOOGLE_RUN_ENABLED=true` in
the app `.env` and grant the web user a NOPASSWD rule for that one command:

```sh
sudo visudo -cf deploy/idm-google-run.sudoers   # syntax check
sudo install -m 0440 -o root -g root deploy/idm-google-run.sudoers \
     /etc/sudoers.d/idm-google-run
```

The result appears on the Services page; a dry-run preview stays a CLI operation
(`sudo -u www-data php /var/www/idm/bin/sync_google.php --dry-run`).

## Adaxes AD reconciler

Reconciles the golden record directly to Active Directory through the Adaxes REST
API — create / edit / disable / groups (`bin/adaxes_sync.php`). Requires the
`ADAXES_*` settings in the app `.env` (base URL + a token, or username/password).

```sh
sudo cp deploy/idm-adaxes-sync.service /etc/systemd/system/
sudo cp deploy/idm-adaxes-sync.timer   /etc/systemd/system/
sudo systemctl daemon-reload

# Dry-run once to preview every change (writes nothing, needs no write credential)
sudo -u www-data php /var/www/idm/bin/adaxes_sync.php --dry-run

# Run once now to verify
sudo systemctl start idm-adaxes-sync.service
journalctl -u idm-adaxes-sync.service -n 100 --no-pager

# Enable the nightly timer
sudo systemctl enable --now idm-adaxes-sync.timer
systemctl list-timers idm-adaxes-sync.timer
```

**Enable the `.timer`, not the `.service`** (see the note under *Google Workspace
sync* — the oneshot service has no `[Install]` section by design).

**Writes are off until you turn them on.** Until `ADAXES_WRITE_ENABLED=true` (and
a write credential is set) even a real run only *reports* what it would do and
changes nothing, so it's safe to schedule early — dry-run it first, then enable
writes deliberately per the rollout runbook in
[`../docs/adaxes-provisioning-design.md`](../docs/adaxes-provisioning-design.md).
Schedule it to run **after** the nightly feed imports so it reconciles the
freshest golden record. Each real run is recorded on the admin Services page.

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

## PHP-FPM pool tuning (`idm-php-fpm.conf`)

Debian's stock pool ships `pm.max_children = 5`. Because a PHP worker is held for
the entire duration of its request, five slow requests wedge the whole site and
nginx returns 504:

```
WARNING: [pool www] server reached pm.max_children setting (5), consider raising it
```

The two long jobs that caused this — the Google and Adaxes syncs (a live remote
lookup **per person**, minutes long) — now run in the **background** via systemd
(the "Run … now" buttons only *start* the oneshot and return), so they no longer
occupy a web worker. `deploy/idm-php-fpm.conf` then gives the remaining in-request
work real headroom and makes a wedged request self-terminate (`request_terminate_timeout`)
instead of piling up. It's a **dedicated pool** (own socket) so it never clobbers
the stock `www` pool.

```sh
# Install the pool and point nginx at its socket
sudo install -m 0644 deploy/idm-php-fpm.conf /etc/php/${PHP_VERSION}/fpm/pool.d/idm.conf
sudo mkdir -p /var/log/php-fpm && sudo chown www-data:www-data /var/log/php-fpm
#   in the nginx site:  fastcgi_pass unix:/run/php/idm.sock;
sudo php-fpm${PHP_VERSION} -t                       # validate config
sudo systemctl reload php${PHP_VERSION}-fpm && sudo systemctl reload nginx
```

Size `pm.max_children` to your RAM (`≈ RAM-for-PHP ÷ ~60 MB per worker`; the file
has the math) and keep `request_terminate_timeout` above the app's remote timeouts
(`ADAXES_TIMEOUT`, `GAM_TIMEOUT`, DB) but below nginx's `fastcgi_read_timeout`, so
PHP logs the slow request before nginx cuts it off.

> **Note on the "Run … now" buttons and hardening.** The Google / Adaxes / VPN
> buttons shell out (`sudo -n systemctl start …`) via `proc_open`. `scripts/harden-debian12.sh`
> **disables `proc_open` in php-fpm**, so on a fully hardened host those buttons
> won't work — the nightly timers still run the syncs, and you start one on demand
> with `sudo systemctl start idm-google-sync.service`. Leave `proc_open` enabled
> (don't apply that part of the hardening) only if you want the in-app buttons.
