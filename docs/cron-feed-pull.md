# Cron: nightly feed pull + import

`bin/fetch_feeds.php` pulls the configured feed CSVs from the district SFTP server
into the local `FEED_*_DIR` folders and imports them (NextGen, interns, subs,
contractors), and imports **PowerSchool directly from its Oracle DB over ODBC**
(no SFTP). Run it on a schedule so the golden record stays current.

```sh
php bin/fetch_feeds.php              # SFTP feeds + ODBC PowerSchool import
php bin/fetch_feeds.php --source=powerschool   # only the ODBC PowerSchool import
php bin/fetch_feeds.php --source=intern        # only one SFTP source
php bin/fetch_feeds.php --dry-run    # list what would be downloaded/imported, change nothing
php bin/fetch_feeds.php --no-import  # download only (also skips the PowerSchool import)
```

An SFTP source is active when its `SFTP_<SOURCE>_DIR` is set; already-fetched files
are skipped unless the remote file is newer (by mtime) or the local copy is
missing, so re-runs are cheap and safe. PowerSchool is active when `PS_ODBC_DSN`
is set (and the `pdo_odbc` extension + an Oracle ODBC driver are installed).

---

## Prerequisites

- `.env` configured (DB roles, `SFTP_*`, `FEED_*_DIR`, and `PS_ODBC_*` for the
  PowerSchool Oracle connection). See `.env.example`.
- For PowerSchool: the `pdo_odbc` PHP extension and an Oracle ODBC driver
  installed on the host; the connecting user has SELECT on the PS tables.
- SFTP key auth set up: `php bin/sftp_setup_key.php --host=… --user=…` (recommended
  over storing `SFTP_PASS`).
- Reference data seeded: `php bin/seed.php` (schools, aliases, ethnicity map).
- The feed dirs exist and are writable by the user the cron runs as.

Verify it works by hand first:

```sh
php /var/www/idm/bin/fetch_feeds.php --dry-run
php /var/www/idm/bin/fetch_feeds.php
```

---

## Option A — systemd timer (recommended on Debian 12)

Two units: a `service` that runs the job and a `timer` that schedules it. Timers
give you logs in the journal, no `MAILTO`/PATH surprises, and easy `status`.

`/etc/systemd/system/idm-feeds.service`:

```ini
[Unit]
Description=TCS Identity — pull feeds (SFTP + PowerSchool ODBC) and import
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/idm
ExecStart=/usr/bin/php /var/www/idm/bin/fetch_feeds.php
# Don't overlap with a long-running previous run:
TimeoutStartSec=1800
Nice=10
```

`/etc/systemd/system/idm-feeds.timer`:

```ini
[Unit]
Description=Run TCS Identity feed pull nightly

[Timer]
# 02:30 every day, local time; jitter avoids a thundering herd.
OnCalendar=*-*-* 02:30:00
RandomizedDelaySec=300
Persistent=true

[Install]
WantedBy=timers.target
```

Enable and test:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now idm-feeds.timer
systemctl list-timers idm-feeds.timer        # next run time
sudo systemctl start idm-feeds.service       # run once now
journalctl -u idm-feeds.service -n 100 --no-pager   # see output
```

`Persistent=true` runs a missed job after downtime. `WorkingDirectory` matters so
the app finds `.env`.

---

## Option B — classic crontab

Run as the web user so files land with the right ownership:

```sh
sudo crontab -u www-data -e
```

```cron
# m h dom mon dow   command
30 2 * * *  cd /var/www/idm && /usr/bin/php bin/fetch_feeds.php >> /var/log/idm/feeds.log 2>&1
```

Create the log dir once: `sudo install -d -o www-data -g www-data /var/log/idm`.
`cd` into the app dir so `.env` resolves. Cron's minimal `PATH` is why `php` is
given as an absolute path.

---

## What a run does

1. Connects to SFTP (verifying the host fingerprint), lists each source dir.
2. Downloads new/updated files into `FEED_<SOURCE>_DIR`, recording each in
   `feed_fetch_log`.
3. Imports: NextGen (and intern/sub/contractor) per file; **PowerSchool queries
   active staff (TEACHERS + USERS extensions) directly from Oracle over ODBC and
   imports once** (no SFTP).
4. Updates `import_batch` (shown as "Last feed run" on the dashboard).

It does **not** apply OneSync write-back — usernames/passwords come in via the
OneSync API (`docs/onesync-api.md`) or the username write-back importer. If you
ingest the usernames CSV instead of the API, add that importer to the schedule
too:

```cron
45 2 * * *  cd /var/www/idm && /usr/bin/php bin/import_writeback.php >> /var/log/idm/feeds.log 2>&1
```

Per-destination status + failure messages are pulled **directly from OneSync's
MariaDB** — OneSync does not push its export log back. Schedule the DB result
importer after OneSync's nightly run:

```cron
15 3 * * *  cd /var/www/idm && /usr/bin/php bin/import_onesync_db.php >> /var/log/idm/feeds.log 2>&1
```

Requires `ONESYNC_DB_*` configured (read-only SELECT on OneSync's DB) and
`ONESYNC_DB_SOURCE_ID` = OneSync's source id for our IDM feed. Ready-made
systemd units for this importer are in [`../deploy/`](../deploy/) — see
[`../deploy/README.md`](../deploy/README.md).

---

## Monitoring & troubleshooting

- **Dashboard**: the "Last feed run" tile shows the most recent batch + status.
- **Logs**: `journalctl -u idm-feeds.service` (systemd) or `/var/log/idm/feeds.log`
  (cron). Each run prints per-source download/import counts.
- **Nothing downloads** though the dir is empty: fixed — the fetcher re-pulls when
  the local copy is missing. If still empty, check `SFTP_<SOURCE>_DIR`/pattern with
  `php bin/sftp_ls.php --dir=<remote>`.
- **"Cannot list SFTP directory"**: path/case wrong (Serv-U is case-sensitive) —
  use `bin/sftp_ls.php` to find the exact path.
- **Force a clean re-pull** (re-download everything): clear the fetch log —
  `php bin/reset_people.php --yes --include-feed-log` (also clears people; dev only)
  — or delete the relevant `feed_fetch_log` rows.
- **Don't overlap runs**: the systemd `oneshot` won't start a second copy while one
  is running; for cron, keep the interval comfortably longer than a run.

Run the importers/fetch as the **same user** (`www-data`) every time so feed files
and logs keep consistent ownership.
