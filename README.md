# TCS Identity Master

The single source of truth for staff/faculty identity at Tuscaloosa City Schools.
One **golden record** per human, with a crosswalk of every system ID they carry,
exposed as one read-only view (`v_onesync_source`) that OneSync consumes — so
OneSync provisions exactly **one user per person** instead of one per source.

See `docs/` for the full design:
- `docs/schema.sql` — the data model (source of `db/migrations/0001_init.sql`)
- `docs/Identity-DB-and-Dashboard-Design.md` — architecture
- `docs/claude-design-prompt.md` — dashboard UI spec
- `docs/claude-code-project-prompt.md` — the build plan / milestones

> **Status:** Milestone 7 (final) — SAML SSO + server-side RBAC, admin Users
> screen, manual Add person, security headers + HTTPS enforcement, and
> login/logout auditing. All seven milestones are in place; the app is
> feature-complete per the build plan.

## Stack

PHP 8.2+ (developed on 8.4), MySQL 8+, plain PDO with prepared statements. No
heavy framework. Server-rendered pages (added in later milestones).

## Layout

```
public/        web root (front controller + assets — later milestones)
src/           app code (Config, Db, bootstrap; services/controllers later)
bin/           CLI tools — migrate.php, seed.php (importers later)
db/migrations/ ordered *.sql; 0001_init.sql == docs/schema.sql
db/seeds/      reference CSVs (school, school_code_alias, ethnicity_map)
docs/          specs
tests/         PHPUnit (matcher suite lands in Milestone 3)
```

## Quick start (Debian 12 dev server)

One script provisions a fresh Debian 12 (Bookworm) box end-to-end — installs
PHP 8.2 + extensions, Composer, and MariaDB 10.11 (Debian's native build);
generates `.env` with random per-role passwords; creates the database + the four
least-privilege users with the documented GRANTs; runs `composer install`, the
migrations, and the seeds; and (optionally) configures an nginx + php-fpm site
for `public/`. It's idempotent — safe to re-run.

```sh
sudo bash scripts/setup-dev-debian12.sh
# skip the web server, or change the DB name:
sudo INSTALL_WEBSERVER=0 DB_NAME=tcs_identity bash scripts/setup-dev-debian12.sh
```

MariaDB's `root` uses unix_socket auth on Debian, so run `sudo mariadb` for an
admin shell — no root password to manage. To wire things up by hand instead,
follow the manual steps below.

## Manual setup

1. **Configure.** Copy `.env.example` to `.env` and fill in real values. `.env`
   is gitignored — never commit it. Secrets come only from the environment.
   ```sh
   cp .env.example .env
   ```
2. **Install dev deps (optional).** The CLI tools run without Composer thanks to
   a fallback autoloader, but PHPUnit needs the install:
   ```sh
   composer install
   ```
3. **Create DB users.** Run the GRANTs below as a MySQL admin (once).
4. **Migrate** (creates the database if missing, applies `db/migrations/*.sql`):
   ```sh
   php bin/migrate.php            # apply pending
   php bin/migrate.php --status   # show applied/pending
   php bin/migrate.php --dry-run  # preview, change nothing
   ```
5. **Seed reference data:**
   ```sh
   php bin/seed.php               # upsert school / aliases / ethnicity map
   php bin/seed.php --dry-run     # preview
   ```

> ⚠️ The seed CSVs in `db/seeds/` are **placeholders** (plausible sample rows).
> Replace `school.csv`, `school_code_alias.csv`, and `ethnicity_map.csv` with the
> district's real school map and ALSDE ethnicity codes, then re-run `seed.php`
> (idempotent upserts on the natural keys).

6. **Run the app.** With the nginx site from the setup script, browse to the
   server. For a quick local run without a web server:
   ```sh
   php -S 127.0.0.1:8000 -t public      # then open http://127.0.0.1:8000/people
   ```
7. **(Optional) Load demo data** so the People list/detail render before the
   real importers exist (Milestone 3). Dev/non-production only:
   ```sh
   php bin/seed_demo.php                 # sample faculty/staff from the mockup
   php bin/seed_demo.php --dry-run       # preview
   ```
   Idempotent; refuses to run when `APP_ENV=production` (use `--force` to override).

## Ingestion & matching (Milestone 3)

Load a NextGen export or PowerSchool extract into `import_batch` + `staging_record`,
normalize it (school code → `school_id`, ethnicity → ALSDE code; unmapped values
are logged as warnings on the staged row), then match each row to a person and
apply the result.

```sh
php bin/import_nextgen.php --file=db/seeds/feeds/nextgen_sample.csv --dry-run
php bin/import_nextgen.php --file=/var/idm/feeds/nextgen/staff.csv
php bin/import_powerschool.php --file=db/seeds/feeds/powerschool_sample.csv
```

With no `--file`, the importer uses the newest `*.csv` in the configured feed
directory (`FEED_NEXTGEN_DIR` / `FEED_POWERSCHOOL_DIR`). `--dry-run` reads and
matches but writes nothing.

**Pull from SFTP.** The app can fetch feed CSVs straight from the district SFTP
server (phpseclib — no PECL needed), then import them. Configure `SFTP_HOST` /
`SFTP_USER`, a key (`SFTP_PRIVATE_KEY_FILE`) or `SFTP_PASS`, the host
`SFTP_FINGERPRINT` (verified), and a remote dir per source (`SFTP_<SOURCE>_DIR`);
files land in `FEED_<SOURCE>_DIR`. Already-fetched files are tracked in
`feed_fetch_log` (requires migrations `0005`+`0006`). The district overwrites each
CSV in place, so dedupe is by **remote modification time**: a file is re-downloaded
and re-imported when its mtime is newer than the last fetch (not just when the name
is new). If the server reports no mtime, it falls back to fetch-once by name.

```sh
php bin/fetch_feeds.php                 # fetch + import all configured sources
php bin/fetch_feeds.php --source=intern --dry-run
php bin/fetch_feeds.php --no-import     # download only
```

Run it from cron before the nightly OneSync run; editors can also trigger it
from the **Pull from SFTP** button on `/import`.

**Bootstrap key auth from your password (one time).** Instead of storing the
SFTP password, run this once — it generates an Ed25519 key, installs the public
half on the server (using your password), records the host fingerprint, and
switches `.env` to key auth:

```sh
php bin/sftp_setup_key.php --host=sftp.example.org --user=tcs_feeds
# (password prompted, hidden; or pass it via the SFTP_SETUP_PASSWORD env var)
```

After it verifies key-only login it clears `SFTP_PASS`. If the server's home
isn't writable over SFTP, it prints the public key so you can add it manually.

**Discover remote paths.** If a fetch reports "Cannot list SFTP directory",
the path/case is likely off (Serv-U and others use case-sensitive virtual
paths). List what the account actually sees:

```sh
php bin/sftp_ls.php                  # home + '/'
php bin/sftp_ls.php --dir=/Nextgen
```

It prints the resolved home and a `[dir]/[file]` listing (with the server's real
error if a path is wrong), so you can set `SFTP_<SOURCE>_DIR` to the exact path.

**Import source categories.** Each feed is a first-class source (`src/Import/ImportSource.php`)
that drives the person type and crosswalk provenance:

| Source | CLI | person_type | crosswalk system |
|--------|-----|-------------|------------------|
| NextGen (HR) | `bin/import_nextgen.php` | from feed | `nextgen` |
| PowerSchool (TEACHERS export) | `bin/import_powerschool.php` | from feed | `powerschool` |
| Intern | `bin/import_intern.php` | `intern` | `intern_csv` |
| Long-term substitute | `bin/import_sub.php` | `sub` | `sub` |
| Contract employee | `bin/import_contractor.php` | `contractor` | `contractor` |

```sh
php bin/import_intern.php      --file=db/seeds/feeds/intern_sample.csv --dry-run
php bin/import_sub.php         --file=db/seeds/feeds/sub_sample.csv
php bin/import_contractor.php  --file=db/seeds/feeds/contractor_sample.csv
```

All five are also available in the web **upload** dropdown on `/import`. Each
category has its own header map (`ColumnMap`) and feed directory (`FEED_*_DIR`);
the intern/sub/contractor feeds resolve school codes against the PowerSchool
SchoolID alias group. (Requires migration `0003`.)

**Matcher tiers** (strongest key first; first hit wins):

| Tier | Key | Result |
|------|-----|--------|
| 1 | existing `person_source_id` (system, source_key) | **auto** (exact) |
| 2 | `employee_id` | **auto** |
| 3 | full name + DOB | score; **auto** if ≥ `MATCH_AUTO_THRESHOLD` (default 90) and unambiguous, else **review** |
| 4 | full name only (no corroborating DOB) | **review** — *never* auto-linked |
| — | no candidate | **new** pending person |

A name candidate requires **both first and last name to match exactly** — a
shared last name or a first-initial match is *not* a candidate (so a district
full of Smiths and Joneses doesn't flood the queue). A different first name with
the same last name becomes a **new** person, not a review row.

Auto-matches attach the incoming source id to the crosswalk, refresh HR fields,
and upsert the assignment (one primary). Review rows create `match_candidate`
entries for the queue (Milestone 4). The importers are **idempotent** — a re-run
re-matches previously created rows via their now-existing source id (tier 1), so
no duplicates appear. Importers never set username/email — that's OneSync's job.

**Skipping non-person accounts.** PowerSchool exports include system accounts
(Admin, Lookup) that should never become people. The importer skips any row whose
first *or* last name is in `IMPORT_EXCLUDE_NAMES` (comma-separated, default
`admin,lookup`); skipped rows are recorded with a reason but create no person.

**Column maps.** `src/Import/ColumnMap.php` maps each feed's CSV headers to the
logical fields; sample files in `db/seeds/feeds/` show the expected format. Adjust
the maps to match the district's real export headers.

**PowerSchool is three files.** PowerSchool exports as USERS + TEACHERS +
SCHOOLSTAFF, joined on the user DCID (`PowerSchoolBundle::combine`):
- **USERS** — one row per user (`USERS.dcid`): demographics, `HomeSchoolId`,
  `Title`, `staff_classification`, `TeacherNumber`, hire/exit.
- **TEACHERS** — one row per (user, school): `TEACHERS.ID` is the per-assignment
  PS id AD mirrors as `T`+ID; `TEACHERS.Users_DCID` = `USERS.dcid`. A user with N
  schools has N rows / N IDs — **all** are linked to the crosswalk.
- **SCHOOLSTAFF** — one row per assignment (`SCHOOLSTAFF.dcid` = `TEACHERS.dcid`);
  `SCHOOLSTAFF.SchoolID` is that assignment's school → one assignment per school,
  primary = `HomeSchoolId`.

Run it on a folder holding all three (auto-detected by header, any filename):

```sh
php bin/import_powerschool.php --dir=/var/idm/feeds/powerschool --dry-run
php bin/import_powerschool.php --dir=/var/idm/feeds/powerschool
# or explicit: --users=… --teachers=… --schoolstaff=…
```

`fetch_feeds.php` (and the **Pull from SFTP** button) pull all three from the
PowerSchool SFTP dir and run the combined import once; a re-pull of any one file
re-imports using the current trio. Each person auto-links to the NextGen record
by `TeacherNumber`, and AD usernames (`bin/import_ad_usernames.php`) link by
`TEACHERS.ID`.

**Reset for a clean import test.** To wipe imported person data (person, source
ids, assignments, staging/batches, sync status, lifecycle/audit) while preserving
reference data (schools, aliases, ethnicity) and app login accounts:

```sh
php bin/reset_people.php                      # show row counts, change nothing
php bin/reset_people.php --yes                # TRUNCATE the person-data tables
php bin/reset_people.php --yes --include-feed-log   # also re-arm SFTP re-download
```

Destructive — requires `--yes`. Runs as the MIGRATE role. Add
`--include-feed-log` to also clear `feed_fetch_log` so the fetcher re-downloads
feeds it already pulled.

## Review queue (Milestone 4)

When the matcher can't safely auto-link (name+DOB below threshold, or name-only),
it files a `match_candidate` for human review. Work the queue at **/review**:

- **Same person — link & reuse account** → attaches the incoming source id(s) to
  the existing person and folds in HR fields/assignment. This is the
  intern→employee link: no duplicate account is created downstream.
- **Different people — create new** → creates a new pending person from the
  staged row and keeps the two separate.

The comparison card highlights each field (match / differs / info) and shows a
loud warning on weak (name-only) matches. Every decision is audited
(`audit_log` + a `lifecycle_event` on the person), and resolving a case clears
its sibling candidates. Forms are CSRF-protected; actions use Post/Redirect/Get.

> Try it: seed demo people, then import the review-demo feed (its rows
> name-match existing people but carry new ids and no DOB, so they land in
> review — they never auto-link):
> ```sh
> php bin/seed_demo.php
> php bin/import_nextgen.php --file=db/seeds/feeds/nextgen_review_demo.csv
> ```
> Then open `/review`: **Confirm** Elena Ruiz (the intern→employee link) and
> **Reject** the second "Marcus Okafor" (a coincidental same-name → new person).
> Note: `nextgen_sample.csv` produces no review cases by design (its rows
> auto-match or are brand new). RBAC (editor/admin only) is enforced in M7.

## Security & access (Milestone 7)

Authentication is **SAML SSO** against the district IdP; access is **role-based,
enforced server-side on every route** (not just hidden in the UI). No app page is
reachable unauthenticated.

**Roles & capabilities**

| Role | Can |
|------|-----|
| `readonly` | View everything (no write route reachable) |
| `editor` | + work the review queue, manual Add person |
| `admin` | + manage users (roles), reference data, override decisions |

**Configure SSO.** Set the `SAML_*` values in `.env` (SP entityId/ACS/SLS, IdP
entityId/SSO URL/x509 cert, and SP key/cert file paths). SP metadata for the IdP
admin is served at `/saml/metadata`. On first login a user is created `readonly`;
emails listed in `ADMIN_EMAILS` are granted `admin` automatically.

**Bootstrap an admin** (or change any role) from a trusted shell:
```sh
php bin/set_role.php --email=you@tuscaloosacityschools.com --role=admin
```

**Dev login.** When SAML isn't configured **and** `APP_ENV` isn't `production`,
the login page offers a dev sign-in that lets you pick a role to exercise RBAC.
It is disabled automatically once SAML is configured or in production.

**Hardening.** HTTPS is enforced in production (with HSTS); every response sends
a strict CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and
`Referrer-Policy`. Sessions are HttpOnly/SameSite (Secure over HTTPS) and
regenerated on login. Every form is CSRF-protected. Logins, logouts, role
changes, and all data mutations are written to `audit_log`.

## Dashboard, reference data & import status (Milestone 6)

- **Home / health** (`/`): KPI cards (pending review, pending activation, missing
  username, unmapped values, **failed syncs**, last feed) that link to the
  filtered views; recent activity; last feed per source; and the failed-sync
  rollup (accounts whose last OneSync sync failed).
- **Reference data** (`/reference`): the school map (codes + AD/Google OUs) and
  ethnicity map, with **unmapped values surfaced** — ethnicity values seen on
  records and school codes seen in feeds that have no mapping (they block clean
  provisioning). Read-only in M6; editing + RBAC in M7.
- **Import / feeds** (`/import`): batch history with a drill-in to each batch's
  staged rows and how each one matched (auto / new / review / skipped). Editors
  can **upload a CSV** here (pick the source system, optional dry-run) to run the
  importer from the browser — same pipeline as the CLI, idempotent, capability-
  gated, CSRF-protected, with a configurable size cap (`UPLOAD_MAX_BYTES`).

Admins can also **pre-provision SSO users** on the Users screen (`/users`):
add an email + display name + role so access is ready before the person's first
login (matched by email). Roles can be changed there at any time.

## OneSync interface (Milestone 5)

OneSync reads exactly one source (`v_onesync_source`, one row per person) and
writes back what it mints. Three tools:

```sh
# What OneSync sees (connects as the READ-ONLY onesync_ro role):
php bin/onesync_preview.php --limit=20

# Username write-back: apply OneSync's usernames file, set + LOCK username/email.
php bin/import_writeback.php --file=db/seeds/feeds/onesync_usernames_sample.csv --dry-run
php bin/import_writeback.php --file=db/seeds/feeds/onesync_usernames_sample.csv

# Account-status write-back: per-destination provisioning state (AD/Google/…).
php bin/import_sync_status.php --file=db/seeds/feeds/onesync_export_log_sample.csv
```

With no `--file`, the write-back importers use `ONESYNC_WRITEBACK_FILE` /
`ONESYNC_EXPORT_LOG`. Both run as the limited write-back role and are idempotent.

**Write-back API (events).** Instead of CSVs, OneSync can execute an API call on
each event. Token-authenticated (no session/CSRF), JSON in/out, reusing the same
importers and guardrails. Set `ONESYNC_API_KEY` to enable (blank = disabled, 503).
OneSync sends it as `Authorization: Bearer <key>` (or `X-API-Key: <key>`).

```sh
# Health check (still requires the token)
curl -H "Authorization: Bearer $KEY" https://idm.example.org/api/onesync/ping

# Username minted -> set + LOCK username/email on the golden record
curl -X POST https://idm.example.org/api/onesync/username \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"<person_uuid>","username":"jdoe","email":"jdoe@tcs.k12.al.us"}'

# Per-destination provisioning result
curl -X POST https://idm.example.org/api/onesync/sync-status \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"<person_uuid>","destination":"Active Directory","action":"Add","status":"Success"}'
```

Both write endpoints accept a single event **or** a JSON array (batch); a batch
returns `{ok, results:[…]}` with HTTP 207 if any event failed. `uniqueId` is the
`v_onesync_source.uniqueId` (person UUID). Same guarantees as the CSV path below.

Full reference: [`docs/onesync-api.md`](docs/onesync-api.md).

*Debugging:* set `ONESYNC_API_DEBUG=true` to log every call (method, IP, which
auth header carried the token + a masked preview, the body, and the response
status/outcome) to `ONESYNC_API_LOG` (default `/var/idm/onesync/api_debug.log`),
one JSON line per request — so you can see exactly why OneSync's calls fail (401
wrong/missing token, 400 bad JSON, 422 unknown uniqueId). Turn it off once working.
`tail -f /var/idm/onesync/api_debug.log` while OneSync runs.

**One-time AD username link.** To adopt the usernames that already exist in AD
(before OneSync was authoritative), import an AD export. Each row matches a person
by the PowerSchool id (`TEACHERS.ID`, our crosswalk key) — falling back to the
NextGen Employee # — then sets + **locks** the username (and email) and records
the AD id (`T`+`TEACHERS.ID`) in the crosswalk (`person_source_id` system `ad`).

It auto-detects the file format from the headers, so you can feed it **either**:
- the **PowerSchool TEACHERS export** (the same `TeachersID.csv` used for the
  PowerSchool import): `TEACHERS.ID`, `TEACHERS.TeacherLoginID` (username),
  `TEACHERS.Email_Addr` (email), `TEACHERS.TeacherNumber` (NextGen #); or
- an **AD directory export**: `uniqueId` (`T`+`TEACHERS.ID`), `sAMAccountName`,
  `mail`, `Employee ID`.

```sh
php bin/import_ad_usernames.php --file=/var/idm/feeds/powerschool/TeachersID.csv --dry-run
php bin/import_ad_usernames.php --file=/var/idm/feeds/powerschool/TeachersID.csv
```

Idempotent and safe: a username already locked to a different value is reported as
`conflict` and left unchanged. Runs as the MIGRATE role (one-time ops). Run the
PowerSchool/NextGen imports first so the people and their PS crosswalk ids exist.

**Direct DB write-back.** OneSync can also pull from `v_onesync_source` and write
back **straight to the DB** (no files): insert usernames into `onesync_writeback`
and upsert per-user success/failure into `account_sync_status`. The exact table +
column map (and the `onesync_writer` GRANT) is in
[`docs/onesync-mapping.md`](docs/onesync-mapping.md). Migration `0004` adds a
trigger that resolves `person_id` from the `uniqueId` OneSync writes, and the app
applies directly-written usernames with:

```sh
php bin/import_writeback.php --pending     # apply onesync_writeback rows (applied=0)
```

- **Username immutability:** once `username_locked`, the importer never
  overwrites with a different value (logged as `conflict`); re-runs are `noop`.
  The app never mints usernames — this only records OneSync's decision.
- **Account status:** upserts one current row per `(person, destination)` into
  `account_sync_status` (shown on the person's Provisioning panel) and appends to
  the capped `account_sync_event` history (`ACCOUNT_SYNC_EVENT_CAP`). Failed
  syncs surface per-person now and on the health dashboard in M6.

> The file formats above are **assumptions** (documented in `ColumnMap`/importer
> defaults) — confirm OneSync's real usernames-file and export-log columns and
> adjust the maps. The sample files are keyed to the demo people's UUIDs.

## Least-privilege DB users

One database, four roles — never shared or reused. Replace passwords and host
masks (`'%'`) to match your deployment. The app **never** connects as the
migrator or the OneSync reader.

> **Ordering matters.** MariaDB (and modern MySQL) refuse a *table-level* GRANT
> for a table that doesn't exist yet. So create the database + users + the
> database-level grants **first**, run `bin/migrate.php`, **then** apply the
> table/view-level grants for the write-back importer and the OneSync reader.
> (The setup script does exactly this automatically.)

**Step 1 — before migrating** (database-level; safe with no tables yet):

```sql
-- 1) Application account — the dashboard/web app.
CREATE USER 'idm_app'@'%' IDENTIFIED BY 'change-me-app';
GRANT SELECT, INSERT, UPDATE ON tcs_identity.* TO 'idm_app'@'%';
-- No DELETE (no hard deletes — status changes + audit instead), no DDL, no GRANT.

-- 2) Migrator / schema owner — used ONLY by bin/migrate.php, from a trusted shell.
CREATE USER 'idm_migrate'@'%' IDENTIFIED BY 'change-me-migrate';
GRANT ALL PRIVILEGES ON tcs_identity.* TO 'idm_migrate'@'%';
GRANT CREATE ON *.* TO 'idm_migrate'@'%';   -- needs CREATE DATABASE on first run

-- 3) + 4) Create the limited users now; grant their objects after migrating.
CREATE USER 'idm_writeback'@'%' IDENTIFIED BY 'change-me-writeback';
CREATE USER 'onesync_ro'@'%'    IDENTIFIED BY 'change-me-onesync';

FLUSH PRIVILEGES;
```

**Step 2 — after `php bin/migrate.php`** (the tables + view now exist):

```sql
-- 3) Write-back importer — limited writer for the OneSync write-back jobs only.
GRANT INSERT, UPDATE, SELECT ON tcs_identity.onesync_writeback           TO 'idm_writeback'@'%';
GRANT INSERT, UPDATE, SELECT ON tcs_identity.account_sync_status         TO 'idm_writeback'@'%';
GRANT INSERT, SELECT, DELETE ON tcs_identity.account_sync_event          TO 'idm_writeback'@'%'; -- append + prune
-- Apply usernames to the golden record (set + lock):
GRANT SELECT, UPDATE ON tcs_identity.person TO 'idm_writeback'@'%';
-- Audit its own writes:
GRANT INSERT ON tcs_identity.audit_log       TO 'idm_writeback'@'%';
GRANT INSERT ON tcs_identity.lifecycle_event TO 'idm_writeback'@'%';

-- 4) OneSync reader — READ-ONLY on the single view, nothing else.
GRANT SELECT ON tcs_identity.v_onesync_source TO 'onesync_ro'@'%';
-- Deliberately NO access to base tables: OneSync sees one row per person, period.

FLUSH PRIVILEGES;
```

## Operations / backups

- **Backups (critical path).** This DB is now authoritative for staff identity.
  Take nightly `mysqldump --single-transaction --routines` (or use MySQL
  Enterprise Backup / a managed snapshot) and keep ≥30 days. Test a restore
  before cutover. Schedule the dump to finish **before** the 12 AM OneSync run.
- **No hard deletes.** Deactivation is a status change; history lives in
  `audit_log` and `lifecycle_event`. Don't `DELETE` from `person` et al.
- **Migrations** are additive and run at most once (tracked in
  `schema_migrations`). MySQL auto-commits DDL, so author each migration to be
  independently safe; never edit an applied migration — add a new one.
- **Retention.** `account_sync_event` is a capped history; prune/rotate it
  (a job lands with the status importer in Milestone 5) to avoid the
  multi-million-row bloat seen in raw OneSync logs.
- **Secrets** live only in `.env` / the process environment, outside the web
  root. Rotate the four DB passwords independently.

## Tests

```sh
composer install   # once, to get PHPUnit
composer test      # or: ./vendor/bin/phpunit
```

Milestone 1 ships smoke tests for the config loader. The thorough **matcher**
suite (exact / employee_id / name+DOB / name-only-never-auto) arrives in
Milestone 3.
