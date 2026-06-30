# Deploy units

systemd units for the scheduled jobs. Adjust `WorkingDirectory`, `User`, the PHP
path, and the `OnCalendar` time to your install before enabling.

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
