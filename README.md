# TCS Identity Master

The single source of truth for staff/faculty identity at Tuscaloosa City Schools.
One **golden record** per human, with a crosswalk of every system ID they carry,
exposed as one read-only view (`v_onesync_source`) that OneSync consumes — so
OneSync provisions exactly **one user per person** instead of one per source.

See `docs/` for the full design:
- `docs/user-guide.md` — **end-user guide** for the web app (front-end users)
- `docs/data-flow.md` — **end-to-end data flow** (imports → internal processes → OneSync loop → destinations); an interactive chart of it is in-app at `/reference/data-flow`
- `docs/schema.sql` — the data model (source of `db/migrations/0001_init.sql`)
- `docs/Identity-DB-and-Dashboard-Design.md` — architecture
- `docs/claude-design-prompt.md` — dashboard UI spec
- `docs/claude-code-project-prompt.md` — the build plan / milestones
- `docs/saml-sso-setup.md` — configure SAML SSO against the district IdP
- `docs/server-hardening.md` — production hardening / HTTPS
- `docs/onesync-api.md`, `docs/onesync-mapping.md` — the OneSync interface
- `docs/mcp-server.md` — the MCP server for Claude (per-user keys, role-gated tools)
- `docs/cron-feed-pull.md` — schedule the nightly SFTP feed pull
- `docs/dev-box-git-main.md` — swap the dev box git checkout back to `main`

> **Status:** All seven build-plan milestones are in place (through Milestone 7 —
> SAML SSO + server-side RBAC, admin Users screen, manual Add person, security
> headers + HTTPS enforcement, and login/logout auditing). Post-milestone
> operational work has continued on top of that base: live Active Directory
> verification via the Adaxes REST API, reading OneSync results straight from its
> database, feed/API diagnostics, a name-case normalizer, and an admin/editor VPN
> service-restart control.

## Stack

PHP 8.2+ (developed on 8.4), MySQL/MariaDB 8+/10.11+, plain PDO with prepared
statements. No heavy framework — a small front controller and server-rendered
PHP templates.

## Layout

```
public/        web root (front controller + assets)
src/           app code — Config, Db, bootstrap; Auth, Controller, Http,
               Import, Matching, Service, Sync, Support, View
bin/           CLI tools — migrate/seed, the importers, and diagnostics
db/migrations/ ordered *.sql; 0001_init.sql == docs/schema.sql
db/seeds/      reference CSVs (school, school_code_alias, ethnicity_map)
docs/          specs and runbooks
tests/         PHPUnit (config, matcher, and service suites)
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

**Pull & import feeds.** The app can fetch feed CSVs straight from the district
SFTP server (phpseclib — no PECL needed) and import them, and it pulls PowerSchool
directly from Oracle over ODBC (see below — no SFTP for PowerSchool). Configure
`SFTP_HOST` /
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
from the **Pull from SFTP** button on `/import`. Cron/systemd setup:
[`docs/cron-feed-pull.md`](docs/cron-feed-pull.md).

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

**Diagnose a skipped feed.** If rows import as zero or get skipped, the CSV's
delimiter or headers likely don't match the source's `ColumnMap`. Inspect a file
— it detects the delimiter, lists the headers, and shows how each lines up with
the expected logical fields:

```sh
php bin/feed_headers.php --system=nextgen --file=/var/idm/feeds/nextgen/staff.csv
```

**Import source categories.** Each feed is a first-class source (`src/Import/ImportSource.php`)
that drives the person type and crosswalk provenance:

| Source | CLI | person_type | crosswalk system |
|--------|-----|-------------|------------------|
| NextGen (HR) | `bin/import_nextgen.php` | from feed | `nextgen` |
| PowerSchool (Oracle/ODBC) | `bin/import_powerschool.php` | from feed | `powerschool` |
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

> **Students are not in this table.** They don't get a golden record — they're a
> separate PowerSchool passthrough straight to OneSync (`bin/import_students.php`,
> migration `0008`). See [Students passthrough](#students-passthrough).

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
the maps to match the district's real export headers. The NextGen map captures the
full ITExtract column set — employee #, name, e-mail, position #, location, CCTR
description, job code/desc, hire/position-start/end dates, ethnicity, gender, and
the contact block (phone, address 1/2, city, state, zip) — all stored on the
golden record (migration `0007`).

**Field mapping & reconciliation (NextGen ↔ PowerSchool).** NextGen is the source
of record that drives provisioning through OneSync (create / update / disable in
PowerSchool, AD, Google, …); PowerSchool is pulled to **verify the two systems
agree** before that sync runs. `src/Import/FieldMap.php` is the single crosswalk
between each NextGen field, its PowerSchool counterpart, and where the value lands
on the golden record. It drives two read-only views:

- **`/reference` → Field mapping** — the documented crosswalk (which NextGen field
  maps to which PowerSchool field).
- **Source field reconciliation** panel on each person's record — that person's
  **NextGen value beside its PowerSchool value**, field by field, with a verdict
  (match / differs / missing / NextGen-only / PowerSchool-only). The two sides come
  from what each system actually staged (the latest NextGen and PowerSchool staging
  rows), not the merged golden record, so a genuine mismatch is visible. Dates,
  name case, and phone punctuation are normalized before comparison. PowerSchool's
  contact/demographic fields are pulled **for comparison only** — NextGen stays the
  source of record, so they are never written to the golden record (DOB and ALSID
  are the exception: PowerSchool is their source and they *are* stored).

Interns and contractors live **only in IDM** (manual records, no NextGen/PowerSchool
feed); their panel shows the current IDM values and notes there is nothing to
reconcile.

**Manual overrides pin a field against imports.** When an operator hand-edits a
golden field — through the person **Edit** form or by picking a value in the
reconciliation panel — that field is flagged in `person_field_override` and shown
with a **📌 manual** badge. Subsequent feed imports **skip pinned fields** (the
dry-run preview reflects this too), so a hand-edit is never silently reverted to
the source value. Only feed-owned fields are pinnable (demographics, employee id,
primary school, `person_type`, and the assignment title); `status`, notes, and
other IDM-owned fields aren't affected because imports never touch them. Click
**unpin** on the field to hand it back to the feeds. Migration `0022`.

> The PowerSchool side of the comparison comes from a field snapshot captured on
> each PowerSchool import. **Records imported before this feature (or by a failed
> pull) have no snapshot** — the panel says so and prompts a re-import rather than
> flagging every field as a mismatch. Run `php bin/import_powerschool.php` once to
> populate it. The demographic columns are pulled in **best-effort** queries
> (`PowerSchoolOdbcReader::extendedQueries()` — one per group: contact, core_fields
> for DOB/gender, and ALSID/`state_staffnumber`), so if your PS schema names one
> differently the core import still succeeds and the other groups still come
> through — only that group is skipped (logged).

**PowerSchool reads directly from Oracle (ODBC).** PowerSchool runs on Oracle;
instead of exporting CSVs to SFTP, `PowerSchoolOdbcReader` queries the tables in
place and `PowerSchoolBundle::combine` joins them into one record per person:
- **TEACHERS** is the anchor — every active row (`WHERE status = 1`), one per
  (teacher, school). `TEACHERS.ID` is the per-assignment PS id AD mirrors as
  `T`+ID; rows are grouped by `Users_DCID`, so a teacher at N schools has N rows /
  N IDs — **all** linked to the crosswalk. The assignment's school is
  `TEACHERS.SchoolID`; the primary is the row where `SchoolID = HomeSchoolId`.
  `TeacherNumber` and `Title` come from here too.
- **USERS** (+ `U_DEF_EXT_USERS`, `S_USR_X`, `S_AL_USR_X`, `UsersCoreFields`) adds
  only what isn't on TEACHERS — middle name, `staff_classification`, hire/exit
  dates, and the demographics NextGen doesn't carry. The **Alabama State ID
  (ALSID)** comes from `S_USR_X.state_staffnumber` and lands on `person.alsde_id`;
  **date of birth + gender** come from the `UsersCoreFields` staff extension
  (`dob`, `gender`); and the contact fields for the comparison come from `USERS`
  (`email_addr`, `home_phone`, `street`, `city`, `state`, `zip`) — all joined by
  `usersdcid`. These are pulled in separate **best-effort** queries (one per group),
  so a column your live PS schema names differently only loses that group, not the
  whole import — adjust the names in `PowerSchoolOdbcReader::extendedQueries()` to
  match.

This mirrors the district's existing pull (`… FROM Teachers WHERE Status = 1`),
widened to all active assignment rows for multi-school support. `Email_Addr` /
`TeacherLoginID` are **not** imported — OneSync owns username/email.

Configure the connection in `.env` (`PS_ODBC_DSN`, `PS_ODBC_USER`, `PS_ODBC_PASS`,
optional `PS_ODBC_SCHEMA`); it needs the `pdo_odbc` PHP extension plus an Oracle
ODBC driver on the host. Grant the connecting user **SELECT only**.

`scripts/setup-powerschool-odbc.sh` does the host setup end-to-end — installs
unixODBC + `pdo_odbc` and the Oracle Instant Client, registers the driver and a
DSN, writes `PS_ODBC_*` to `.env`, and opens a test connection:

```sh
sudo PS_HOST=psprod.example.org PS_SERVICE=PSPRODDB \
     PS_ODBC_USER=PSNavigator PS_ODBC_PASS='…' \
     bash scripts/setup-powerschool-odbc.sh
```

Per PowerSchool's *Oracle ODBC Configuration and Client Installation Guide*, the
database is normally **SID/service `PSPRODDB`** on port **1521**, reached with a
read-only account — **`PSNavigator`** (broad table access) or **`DataMiner`**.
Those accounts see the tables through synonyms (“Only User’s Schema / Include
Synonyms”), so `PS_ODBC_SCHEMA` is usually left blank. If the service form is
rejected, the same name often works as a SID (`PS_SID=…`).

Your hosted instance may use a **district-specific** service name rather than the
generic `PSPRODDB` (e.g. an Alabama district code like `AL018`). The reliable
source is the connection OneSync already uses — copy the host, port, and
service/SID from its Oracle account config (the field OneSync labels “Schema
Name” is the value after `host:port/` in the connect string).

Set `PS_PORT` if the listener isn't on 1521, and `PS_ODBC_SCHEMA` if the PS tables
live under a specific owner (it's written to `.env` and prefixed onto the table
names). By default it downloads the latest Instant Client; point it at a client
already on the host with `INSTANTCLIENT_DIR=…` (e.g. the `instantclient_19_12`
OneSync uses), or supply pre-downloaded Basic+ODBC zips/rpms offline with
`INSTANTCLIENT_ZIP_DIR=…`.

```sh
php bin/import_powerschool.php --dry-run     # query Oracle, change nothing
php bin/import_powerschool.php               # full import
```

`fetch_feeds.php` (and the **Pull & import feeds** button) run this import as part
of the nightly job. Each person auto-links to the NextGen record by
`TeacherNumber`, and AD usernames (`bin/import_ad_usernames.php`) link by
`TEACHERS.ID`.

CSV files remain a manual/offline fallback (auto-detected by header, any filename):

```sh
php bin/import_powerschool.php --dir=/var/idm/feeds/powerschool --dry-run
# or explicit: --users=… --teachers=… --schoolstaff=…
```

The SQL the reader runs mirrors the columns the old CSV export selected; adjust
`src/Import/PowerSchoolOdbcReader.php` (or set `PS_ODBC_SCHEMA`) to match the
district's live PS schema.

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

**Not in NextGen — review to disable.** Below the match queue, `/review` also lists
people who are no longer in NextGen (no active NextGen crosswalk id — manual
contractors/interns/subs, or anyone dropped off the feed) and still enabled, who
**either** have a past exit date **or** dropped from the NextGen feed more than
`NEXTGEN_DROPOUT_FLAG_DAYS` (default 7) days ago — the second trigger catches
leavers NextGen drops without ever setting an end date (both an "Exit date" and an
"Off NextGen since" column show which fired). NextGen drives disable for its own
people but never touches off-feed records, so these are surfaced here. Each row has
a **Disable** button (editors+) that sets the person to `disabled` — audited, with
a `disable` lifecycle event — so OneSync disables (not orphans) the account on its
next read. Nothing is disabled automatically; a human approves each one. The same
list is available on the CLI via `php bin/flag_disable_candidates.php` (exit code 1
when any are flagged, so a cron/monitor can alert).

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
  username, unmapped values, **failed syncs**, **to disable**, last feed) that
  link to the filtered views (the **to disable** card links to the review queue's
  disable panel); recent activity; last feed per source; and the failed-sync
  rollup (accounts whose last OneSync sync failed).

**Staff drop-out tracking.** The NextGen import is a full-roster feed, so a
person whose crosswalk id was active but is **absent from a completed import** is
flagged not-in-NextGen (`person_source_id.is_active = 0`, audited + on the
timeline) — mirroring the student drop-out logic. This is what lets a former
NextGen employee reach the "Not in NextGen — review to disable" panel on the
review queue (below). It never changes `person.status` (disabling stays a human
decision), and a returning employee is re-linked (not duplicated) on their next
feed. A safety valve (`NEXTGEN_DROPOUT_MAX_RATIO`, default 0.2) **blocks** the
step if a suspiciously large share would drop at once — the likely sign of a
truncated feed — and logs it instead of deactivating.
- **Reference data** (`/reference`): the school map (codes + AD/Google OUs),
  ethnicity map, and the **NextGen ↔ PowerSchool field mapping** crosswalk, with
  **unmapped values surfaced** — ethnicity values seen on records and school codes
  seen in feeds that have no mapping (they block clean provisioning). The school
  **OU mapping is editable inline** on the Schools tab (admin only, CSRF-checked,
  audited with a before/after image); Google OUs follow the district convention
  `/tcs/faculty/{school}` and are normalized to leading-slash form on save.
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

# Provisioning results: pulled straight from OneSync's own database (read-only).
php bin/import_onesync_db.php --dry-run
```

With no `--file`, the username importer uses `ONESYNC_WRITEBACK_FILE`. Both run
as the limited write-back role and are idempotent. Per-destination provisioning
state (AD/Google/…) is **pulled** from OneSync's DB by `bin/import_onesync_db.php`
(nightly cron) — OneSync does not push its export log back.

**Write-back API (events).** Instead of CSVs, OneSync can execute an API call
for the two things only it knows about a new user: the username it minted and
the initial password it set. Token-authenticated (no session/CSRF), JSON in/out,
reusing the same importers and guardrails. Set `ONESYNC_API_KEY` to enable
(blank = disabled, 503). OneSync sends it as `Authorization: Bearer <key>`
(or `X-API-Key: <key>`).

```sh
# Health check (still requires the token)
curl -H "Authorization: Bearer $KEY" https://idm.example.org/api/onesync/ping

# Username minted -> set + LOCK username/email on the golden record
curl -X POST https://idm.example.org/api/onesync/username \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"<person_uuid>","username":"jdoe","email":"jdoe@tcs.k12.al.us"}'

# Initial password for a newly created account (stored encrypted)
curl -X POST https://idm.example.org/api/onesync/password \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"<person_uuid>","password":"Falcon-Maple-42"}'
```

Both write endpoints accept a single event **or** a JSON array (batch); a batch
returns `{ok, results:[…]}` with HTTP 207 if any event failed. `uniqueId` is the
`v_onesync_source.ID` (person UUID). Same guarantees as the CSV path below.

Full reference: [`docs/onesync-api.md`](docs/onesync-api.md).

### MCP server for Claude

An MCP (Model Context Protocol) server lets Claude query — and, for the right
roles, act on — the identity system at `POST /mcp`. Unlike the OneSync API's
shared secret, auth here is **per user**: each person mints their own API key
(**Settings ▸ API keys**, or `bin/api_key.php`) and sends it as
`Authorization: Bearer <key>`. A key acts as its owner, and the owner's app role
(`readonly`/`editor`/`admin`) bounds which tools Claude can list and call —
checked live on every request, so revoking or downgrading takes effect at once.

```sh
# Mint a key from the CLI (or use the Settings ▸ API keys page)
php bin/api_key.php create --email=you@tuscaloosacityschools.com --label="Claude Desktop"

# Connect Claude Code
claude mcp add --transport http tcs-identity https://idm.example.org/mcp \
  --header "Authorization: Bearer $KEY"
```

Tools range from read-only (`search_people`, `get_person`, `dashboard_summary`,
`list_failed_syncs`, `list_review_queue`) through editor (`confirm_match`,
`reject_match`) to admin (`list_users`); writes are audited as `mcp:<email>`.
Set `MCP_ENABLED=false` to disable the endpoint.

Full reference: [`docs/mcp-server.md`](docs/mcp-server.md).

### Students passthrough

Students are **not** part of the staff golden-record model — no matching, no
crosswalk, no review queue. They are a straight passthrough: we pull the active
and future enrollments from PowerSchool over the same ODBC connection and stage
them in their own `student` table, which OneSync reads from one read-only view
(`v_onesync_student_source`), exactly like `v_onesync_source` for staff. The web
app only shows the **status** of this sync on the dashboard (last run, row counts,
freshness) — there is no per-student editing.

```sh
php bin/import_students.php --dry-run     # query Oracle, change nothing
php bin/import_students.php               # full passthrough import
```

Source query (`Enroll_Status` 0 = enrolled, 3 = future):

```sql
SELECT State_StudentNumber, SchoolID, Grade_Level, First_Name, Last_Name,
       ID, DCID, EntryCode, ExitCode, ExitDate
FROM Students WHERE Enroll_Status = 0 OR Enroll_Status = 3
```

`DCID` is the upsert key (PowerSchool's stable internal id), so re-runs are
idempotent and each student keeps the same `student_uuid` (the OneSync uniqueId).
A student that was active before but is absent from the latest pull is flagged
`is_active = 0` (exposed as `StatusActive = 0`) so OneSync **disables** rather
than orphans — never a hard delete. Runs as the APP role; schedule it nightly
alongside the staff import (see [`deploy/`](deploy/)).

*Debugging:* set `ONESYNC_API_DEBUG=true` to log every call (method, IP, which
auth header carried the token + a masked preview, the body, and the response
status/outcome) to `ONESYNC_API_LOG` (default `/var/idm/onesync/api_debug.log`),
one JSON line per request — so you can see exactly why OneSync's calls fail (401
wrong/missing token, 400 bad JSON, 422 unknown uniqueId). Turn it off once working.
`tail -f /var/idm/onesync/api_debug.log` while OneSync runs.

If the log stays empty, confirm it's enabled and that the web user can actually
write to it — `bin/api_log_check.php` reports whether debug is on, where it writes,
and writes a test line. Run it as the **same user the web server runs as**, or the
writability result won't match what the API sees:

```sh
sudo -u www-data php bin/api_log_check.php
```

**One-time AD username link.** To adopt the usernames that already exist in AD
(before OneSync was authoritative), import an AD export. Each row matches a person
by the PowerSchool id (`TEACHERS.ID`, our crosswalk key) — falling back to the
NextGen Employee # — then sets + **locks** the username (and email) and records
the AD id (`T`+`TEACHERS.ID`) in the crosswalk (`person_source_id` system `ad`).

It auto-detects the file format from the headers, so you can feed it **any** of:
- the **PowerSchool TEACHERS export** (the same `TeachersID.csv` used for the
  PowerSchool import): `TEACHERS.ID`, `TEACHERS.TeacherLoginID` (username),
  `TEACHERS.Email_Addr` (email), `TEACHERS.TeacherNumber` (NextGen #);
- an **AD directory export**: `uniqueId` (`T`+`TEACHERS.ID`), `sAMAccountName`,
  `mail`, `Employee ID`; or
- an **Adaxes "Employee List" export**: `Logon Name (pre-Windows 2000)`
  (= sAMAccountName), `Logon Name` (UPN), `Email`, `Employee ID`, `Object GUID`,
  plus `Department`/`Parent`/`Name`. This file has no PowerSchool key, so it
  matches each person by **Employee ID, then Email, then username**, sets +
  **locks** the sAMAccountName as the username (refreshing email + UPN), and
  records the **real `objectGUID`** in the crosswalk — which is exactly the key
  the live Adaxes verification looks an account up by.

```sh
php bin/import_ad_usernames.php --file=/var/idm/feeds/powerschool/TeachersID.csv --dry-run
php bin/import_ad_usernames.php --file=/var/idm/feeds/powerschool/TeachersID.csv
php bin/import_ad_usernames.php --file=/var/idm/ad/Employee_List.csv --dry-run
```

Idempotent and safe: a username already locked to a different value is reported as
`conflict` and left unchanged. Runs as the MIGRATE role (one-time ops). Run the
PowerSchool/NextGen imports first so the people and their PS crosswalk ids exist.

**Clear legacy AD ids.** Running both the early link (uniqueId `T#####`) and the
Employee List import (real `objectGUID`) leaves some people with two `ad`
crosswalk rows. The objectGUID is what live verification resolves by, so drop the
legacy ones:

```sh
php bin/cleanup_ad_ids.php --dry-run   # preview
php bin/cleanup_ad_ids.php             # remove "T#####" ids where a GUID exists
php bin/cleanup_ad_ids.php --all       # also remove a legacy id that's the only AD id
```

By default it keeps a person's legacy id when it's their *only* AD id (so they
aren't left unlinked — re-run the Employee List import to give them a GUID);
`--all` removes those too. Each removal is audited and added to the person
timeline.

**Fix name casing.** Feeds sometimes deliver names all-caps or all-lowercase.
Normalize every person's first/last name to conventional "first letter capital"
form (`JAMES SMITH` / `james smith` → `James Smith`):

```sh
php bin/fix_name_case.php --dry-run    # preview what would change
php bin/fix_name_case.php              # apply
```

Only rows whose casing actually changes are written; common exceptions are cased
correctly (`McDonald`, `O'Brien`, `Smith-Jones`, generational suffix `III`). Each
change is audited and added to the person timeline. Idempotent — safe to re-run.

**Reclassify person_type by title (one-time).** Feeds sometimes deliver a
substitute, intern, SRO, or transportation employee typed generically as
`staff`/`faculty`. This backfill reads each person's primary job title and sets
`person_type` to match, so classification (and the Adaxes OU placement / group
policy that keys off it) is correct:

```sh
php bin/fix_person_types_by_title.php --dry-run   # preview what would change
php bin/fix_person_types_by_title.php             # apply
```

Title → type, first match wins (mirrors `AdaxesReconciler`'s title rules):
transportation (`bus …`, `AD_TRANSPORTATION_TITLES`) → `staff`; SRO / "School
Resource Officer" → `contractor`; "Substitute" / "Long-term Substitute" → `sub`;
"Intern" → `intern`. Whole-word matching avoids false hits (`Business`,
`Internal`, `Internship Coordinator`). Only people whose title matches a category
**and** whose current type differs are written; each change is audited and added
to the person timeline. Idempotent — safe to re-run.

**Give Transportation its own building (one-time).** NextGen flags transportation
staff with a distinct location code (8410 at TCS). If that code is only an *alias*
on the Central Office school row, every Central Office employee resolves to the
same `school_id` and the Adaxes sync mis-classifies the whole building as
transportation. This ensures a dedicated Transportation school (`ad_ou=OU=trans`,
no PowerSchool id) and repoints the transportation NextGen code(s) onto it:

```sh
php bin/split_transportation_building.php --dry-run   # preview
php bin/split_transportation_building.php             # apply
```

Reads the code(s) from `AD_TRANSPORTATION_SCHOOL_CODES` and the OU from
`AD_OU_TRANSPORTATION`. Idempotent and audited. Existing transportation employees
move onto the new building at the next NextGen import; Central Office staff stop
being mis-flagged immediately.

**Reclassify OU=Subs members from an Adaxes report (one-time).** When existing AD
substitutes sit in `OU=Subs` but IDM has them typed as something else, an Adaxes
reconciler `--dry-run` shows them as `[WOULD-MOVE] … move OU=Subs,… → …` (IDM
would relocate them out of the Subs OU). Feed that report to this import to fix
the golden record instead: it sets `person_type = sub` and sets each person's
primary assignment title to the account's live AD description (read straight from
the report — the reconciler surfaces the current AD description as the left side
of a `description: X → Y` drift; no drift means the title already matches AD, so
it's left alone).

```sh
php bin/adaxes_sync.php --dry-run > /tmp/adaxes.txt          # generate the report
php bin/reclass_subs_from_report.php --file=/tmp/adaxes.txt --dry-run   # preview
php bin/reclass_subs_from_report.php --file=/tmp/adaxes.txt             # apply
```

Empty/`(unset)` AD descriptions never blank out a title. Audited and idempotent;
after applying, the next sync keeps these accounts in `OU=Subs`.

**Direct DB write-back.** OneSync can also pull from `v_onesync_source` and write
usernames back **straight to the DB** (no files): insert into the
`onesync_writeback` landing table. The exact table + column map (and the
`onesync_writer` GRANT) is in [`docs/onesync-mapping.md`](docs/onesync-mapping.md).
Migration `0004` adds a trigger that resolves `person_id` from the `uniqueId`
OneSync writes, and the app applies directly-written usernames with:

```sh
php bin/import_writeback.php --pending     # apply onesync_writeback rows (applied=0)
```

- **Username immutability:** once `username_locked`, the importer never
  overwrites with a different value (logged as `conflict`); re-runs are `noop`.
  The app never mints usernames — this only records OneSync's decision.
- **Account status:** comes from the OneSync DB pull below — one current row per
  `(person, destination)` in `account_sync_status` (shown on the person's
  Provisioning panel) plus the capped `account_sync_event` history
  (`ACCOUNT_SYNC_EVENT_CAP`). Failed syncs surface per-person and on the health
  dashboard.

> The usernames-file format above is an **assumption** (documented in
> `ColumnMap`/importer defaults) — confirm OneSync's real columns and adjust the
> map. The sample file is keyed to the demo people's UUIDs.

**Pull results from OneSync's own database.** This is **the** provisioning-status
path: the app reads provisioning results **straight from OneSync's MariaDB** (its
`os_users` / `os_export_log` tables) and upserts them into `account_sync_status`
(per-destination state + failure messages) — OneSync never has to push its export
log back. It connects
read-only via `ONESYNC_DB_*` and writes as the limited write-back role, joining
`os_users.userId` to our `person_uuid` across both IDM feeds
(`ONESYNC_DB_SOURCE_ID_STUDENTS` / `ONESYNC_DB_SOURCE_ID_FACULTY`; the legacy
single `ONESYNC_DB_SOURCE_ID` is honored when those are unset).

```sh
php bin/import_onesync_db.php --dry-run   # read OneSync's DB, change nothing
php bin/import_onesync_db.php             # upsert results into account_sync_status
```

Grant the `ONESYNC_DB_*` user **SELECT only** on OneSync's database. To map an
unfamiliar OneSync schema (which tables/columns hold the minted username and the
per-destination success/failure), use the read-only inspector:

```sh
php bin/onesync_db_inspect.php                          # tables + row counts
php bin/onesync_db_inspect.php --table=os_export_log    # columns of one table
php bin/onesync_db_inspect.php --table=os_users --sample=5   # + sample rows
php bin/onesync_db_inspect.php --distinct=os_export_log.actionStatus  # decode enum ints
```

`--sample` prints real data (may include usernames/emails) — run it on a trusted
terminal; the default is columns only.

## Active Directory verification (Adaxes REST API)

The Provisioning panel shows what *OneSync reported* about each AD account. The
**Active Directory (live)** panel on the person detail page shows what AD itself
currently holds, fetched on demand from the [Adaxes REST API](https://www.adaxes.com/sdk/ApiDocumentation.RESTApi/)
and compared field-by-field to the golden record — account enabled/disabled,
`sAMAccountName`, `userPrincipalName`, `mail`, `displayName`, and OU/DN. The
lookup is **read-only** against AD: the app never writes, enables, or modifies
anything in Active Directory. The panel loads **asynchronously** after the rest
of the person page renders, so a slow or unreachable Adaxes never blocks the page.

For a **pending** person, an editor can **Accept AD as golden record** from the
panel — a deliberate `POST` action (never a side effect of viewing) that adopts
the AD `sAMAccountName`, `userPrincipalName`, and `mail` as the golden `username`
(set + locked), `upn`, and `email`, links the `objectGUID` into the crosswalk,
and activates the person. It fills only the values the golden record is still
missing — present values are never overwritten — and is audited like any other
golden-record write. The button appears only when AD holds an identity value the
record lacks; an active record's identity stays OneSync's to own, so the action
is never offered there.

It is off until configured. Set the base URL and a token (or a **read-only**
service account) in `.env` (`ADAXES_*`, documented in `.env.example`):

```sh
ADAXES_BASE_URL=https://adaxes.example.org/restApi  # the REST API root
ADAXES_TOKEN=…                                      # from New-AdmAccountToken (read-only acct)
# — or — let the app run the legacy handshake from a username + password:
#ADAXES_USERNAME=TCS\\svc-idm-read
#ADAXES_PASSWORD=…
ADAXES_CA_FILE=/etc/ssl/certs/internal-ca.pem       # internal CA (keep TLS verification on)
```

**Auth.** The Adaxes REST API does **not** accept HTTP Basic — it uses a security
token sent in the `Adm-Authorization` header ([authentication docs](https://www.adaxes.com/sdk/REST_Authentication/)).
Provide one of:
- **`ADAXES_TOKEN`** — a token generated by the `New-AdmAccountToken` PowerShell
  cmdlet (recommended: fastest, no password stored, no per-request handshake); or
- **`ADAXES_USERNAME` + `ADAXES_PASSWORD`** — the app runs the legacy handshake
  itself (POST `…/api/authSessions/create` → POST `…/api/auth`) to obtain a token
  per verification, then terminates the session + destroys the token afterward so
  nothing lingers. Handshake paths are overridable via `ADAXES_SESSION_PATH` /
  `ADAXES_TOKEN_PATH` (defaults match Adaxes 2025.1).

How a person is matched in AD: the AD `objectGUID` in the crosswalk
(`person_source_id` where `system='ad'`, populated by the Employee List import)
is the stable key and is tried first; if it doesn't resolve, the service POSTs a
search (`{base}/api/directoryObjects/search`) matching **any** of
`sAMAccountName = username`, `mail = email`, or `employeeID = employee_id`
(an OR over `eq` conditions — any one matches; the employee-id attribute is
configurable via `ADAXES_EMPLOYEE_ID_ATTR`). When a match is found, the account is **backfilled into the golden record**
(idempotent + audited): its `objectGUID` is linked into the crosswalk (so the
next lookup resolves directly by GUID), and any **empty** `username` (set +
locked), `email`, or `upn` is filled from the AD account — present values are
never overwritten, and a pending person with a freshly-set username is activated.
A unique clash (username/email already used) leaves the record untouched. With
neither a
resolvable key nor any of those values there is nothing to verify and the panel
says so. The client uses a short timeout and **degrades gracefully** — an
unreachable or misconfigured Adaxes shows a notice, never an error page
(`App\Service\AdaxesService`, unit-tested with an injected HTTP client). Set
`ADAXES_DEBUG=true` (logs to `ADAXES_LOG`) to record each request URL + response
while troubleshooting (logs fall back to the PHP error log if the file path
isn't writable). All REST endpoints live under `{base}/api`; the object lookup is
`GET {base}/api/directoryObjects?directoryObject=<DN|GUID>&properties=…` (the id
is a query parameter, not a path segment). Paths/param are overridable
(`ADAXES_OBJECTS_PATH` / `ADAXES_OBJECT_PARAM` / `ADAXES_SEARCH_PATH`).

## Direct Google Workspace provisioning (bypassing OneSync)

Where the Adaxes panel only *reads* AD, the **Google Workspace (live · direct)**
panel both correlates **and writes** — it lets IDM provision Google accounts
straight from the golden record via the [Admin SDK Directory API](https://developers.google.com/admin-sdk/directory),
**bypassing OneSync** entirely, and (with the batch job) **replace** OneSync's
Google destination. The golden record is the source of truth that *drives* Google
state.

Two surfaces, one engine (`App\Sync\GoogleProvisioner`, so both behave identically):

- **Per person** (person detail page, editor+): **Link** an existing account,
  **Create**, **Push** golden-record changes, **Suspend**, **Restore**. Each is a
  CSRF-checked POST that redirects back with a flash.
- **Batch reconcile** (`bin/sync_google.php` / a nightly systemd timer, or the
  **Sync to Google** button on the Import page): create missing accounts, push
  name drift, suspend disabled/terminated people.

**Correlation (OneSync-style).** Before creating anything, the person is matched
to an existing Google account, strongest key first — (1) the Google id in the
crosswalk (`person_source_id` where `system='google'`), (2) `primaryEmail` =
golden email (then UPN), (3) `externalId` = `employee_id`, (4) name. Tiers 1–3
auto-link; a **name-only** match is a review suggestion that is **never**
auto-linked (mirrors the import Matcher's rule) — an admin must confirm it.

**Semantics.** "Disable" is a **suspend** (reversible via Restore), never a
delete. Creating requires a golden `email` already on file — the app never
invents an address (so email-less `pending` people are reported "not eligible";
minting new addresses is out of scope and still belongs to OneSync). The batch
**never auto-restores** a suspended account, so a manual suspend is never
silently undone. Every write is reflected into `account_sync_status` (the same
table OneSync writes, so the dashboard/person page show it), the crosswalk, and
`audit_log` + `lifecycle_event`.

**Transport backends (`GOOGLE_BACKEND`).** The correlation tiers, write
semantics, guardrails, and UI are identical either way — only the low-level
directory calls swap:

- **`api`** (default) — the built-in Admin SDK client. A service account with
  **domain-wide delegation**: a short-lived RS256 JWT is signed locally with the
  SA key (native `openssl_sign`, no vendored dependency) and exchanged at
  Google's OAuth2 endpoint for an access token that impersonates
  `GOOGLE_ADMIN_SUBJECT`. In the Google Admin console, authorize the SA client
  ID for the `admin.directory.user` scope. Run `php bin/google_auth_check.php`
  to verify the whole chain (key → token → a delegated API call) and print the
  exact client ID + scope to authorize when a step is still outstanding.
- **`gam`** — shell out to [GAM](https://github.com/GAM-team/GAM) (GAM7), the
  CLI most Workspace admins already run (`App\Service\GamClient`). Auth lives
  entirely in GAM's own project/config, so **the app holds no Google key at
  all** — no `GOOGLE_SA_*`, no delegation wiring in this app; you reuse (or set
  up once with `gam create project` + `gam oauth create`) the same GAM the
  district already trusts, and GAM's own logging/quota handling applies.
  Commands are executed argv-style (no shell, so person data can't inject), the
  initial password never appears on a command line (GAM's `password random`),
  and results are parsed from `formatjson` output. Point `GAM_PATH` at the
  binary and (optionally) `GAM_CONFIG_DIR` at a shared config dir (exported as
  `GAMCFGDIR`); the config must be authorized for the user the app/timer runs
  as. Prefer `api` when you don't want a GAM install on the web host; prefer
  `gam` when you'd rather not hand this app a service-account key.

Off until configured; both backends degrade gracefully (never an error page) and
are unit-tested with an injected HTTP client / process runner
(`App\Service\GoogleWorkspaceService`, `App\Service\GamClient`).

```sh
GOOGLE_DIRECT_ENABLED=true
GOOGLE_DOMAIN=tuscaloosacityschools.com
GOOGLE_SYNC_MAX_RATIO=0.2                            # block a run that would mass-suspend

# backend 'api' (default):
GOOGLE_SA_KEY_FILE=/var/idm/google-sa.json          # downloaded SA key (client_email + private_key)
GOOGLE_ADMIN_SUBJECT=idm-admin@tuscaloosacityschools.com

# or backend 'gam' (no SA key handled by the app):
#GOOGLE_BACKEND=gam
#GAM_PATH=/usr/local/bin/gam
#GAM_CONFIG_DIR=/var/idm/gam                         # exported as GAMCFGDIR
```

**Safety.** The batch supports `--dry-run` (plan only) and a **threshold
guardrail**: if a run would suspend more than `GOOGLE_SYNC_MAX_RATIO` of a linked
population of at least `GOOGLE_SYNC_GUARD_MIN`, the whole run is **blocked** and
nothing is written — a bad feed can't mass-suspend accounts. Per-school Google OU
placement comes from `school.google_ou` — district convention
`/tcs/faculty/{school}`, editable on `/reference` (Schools tab, admin only); a
school with no mapping places new accounts in the root OU, so the reference page
flags unmapped rows in amber. Full `GOOGLE_*` reference in
`.env.example`. The batch runs as the `idm_app` role — it reads the golden
record and `person_source_id` and writes the crosswalk, audit, and
`account_sync_status`, the same as the per-person "Sync to Google" buttons.

```sh
php bin/sync_google.php --dry-run   # plan; then drop --dry-run to apply
# schedule: deploy/idm-google-sync.{service,timer} (nightly, after the feed imports)
```

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
-- Direct Google sync (bin/sync_google.php) links the google crosswalk id + reads schools:
GRANT SELECT, INSERT, UPDATE ON tcs_identity.person_source_id TO 'idm_writeback'@'%';
GRANT SELECT ON tcs_identity.school TO 'idm_writeback'@'%';
-- Audit its own writes:
GRANT INSERT ON tcs_identity.audit_log       TO 'idm_writeback'@'%';
GRANT INSERT ON tcs_identity.lifecycle_event TO 'idm_writeback'@'%';

-- 4) OneSync reader — READ-ONLY on the source views, nothing else.
GRANT SELECT ON tcs_identity.v_onesync_source         TO 'onesync_ro'@'%';
GRANT SELECT ON tcs_identity.v_onesync_student_source TO 'onesync_ro'@'%'; -- students passthrough
-- Deliberately NO access to base tables: OneSync sees the views, period.

FLUSH PRIVILEGES;
```

## Services (admin)

The **Services** page (`/admin`, admin-only) is one place to see the health of
every moving part and to run the background jobs on demand:

- **Service status** — live cards for the application database, the OneSync
  source DB (read), the OneSync DB sync freshness, the username/password
  write-back API, the SFTP feed config, the PowerSchool Oracle ODBC connection,
  and the VPN monitor. The OneSync source-DB
  card is a live probe bounded by a short connect timeout (`SERVICE_PING_TIMEOUT`,
  default 3s) so an unreachable host fails fast instead of hanging the page; the
  app-DB card reuses the app's existing connection, and everything else reports
  configuration presence.
- **Jobs & last run** — the most recent **feed imports** (per source, from
  `import_batch`), the **students sync** (`student_import_batch`), and the
  **OneSync DB sync**. The OneSync DB sync had no run record of its own, so each
  run — from cron (`bin/import_onesync_db.php`) or the button — now lands in a
  new `service_run` table (job, origin, status, actor, counts, timing), which
  also drives the **Recent runs** history.
- **Run now** — admins can trigger the feed pull, the students sync, or the
  OneSync DB sync from here. Each runs the same code path as its CLI/cron job,
  synchronously in the request (like the existing feed-pull action), is
  CSRF-protected, records a `service_run` row, and writes an `audit_log` entry
  (entity `config`, action `service-run`). Buttons are hidden when the
  underlying integration isn't configured.

The app DB user needs `INSERT/UPDATE/SELECT` on `service_run` (covered by its
existing "DML on app tables" grant). Run `php bin/migrate.php` to create the
table (migration `0011_service_run.sql`).

## VPN status & restart

The **VPN status** page (`/vpn`) relays the read-only `pseast-vpn-monitor`
snapshot — systemd service, `tun0`, the route + reachability to the PowerSchool
database, portal liveness, recent logs, and uptime history. Point the app at the
monitor with `VPN_MONITOR_URL` (usually `http://127.0.0.1:8787`); the app fetches
it server-side and degrades gracefully if the monitor is down.

**Restart from the UI (editors/admins).** The page is view-only except for one
action: when `VPN_CONTROL_ENABLED=true`, edit/admin roles see a **Restart VPN
service** button that asks systemd to restart the unit on this host. It's gated
server-side on the `edit` capability (not just hidden), CSRF-protected, and every
restart is written to `audit_log` (entity `config`) with the actor and result.
The app runs, with no shell, `sudo -n systemctl restart <VPN_SERVICE_UNIT>`
(default `openconnect-pseast.service`, validated so a stray config value can't add
arguments), so the web user needs a tightly-scoped NOPASSWD sudoers rule:

```sh
sudo visudo -cf deploy/idm-vpn-restart.sudoers            # syntax check
sudo install -m 0440 -o root -g root deploy/idm-vpn-restart.sudoers \
     /etc/sudoers.d/idm-vpn-restart
```

Adjust the user (Debian's `www-data`) and unit name in that file to match your
install and `VPN_SERVICE_UNIT`. Leave `VPN_CONTROL_ENABLED=false` (the default)
to keep the whole VPN feature read-only — the button won't appear and the route
returns a "disabled" notice.

## Security dashboard

The **Security** page (`/security`, admin-only) is a read-only view of the
host's security posture — the runtime state of the controls
`scripts/harden-debian12.sh` configures:

- **Firewall (ufw)** — active/inactive, default inbound policy, and the allow-rule
  list (`ufw status verbose`).
- **fail2ban** — running jails, per-jail failed/banned counts, and a live table of
  **currently banned IPs** across all jails (`fail2ban-client status [<jail>]`).
- **SSH daemon** — the effective `sshd -T` policy: port, `PermitRootLogin`,
  `PasswordAuthentication` (flags password-auth / root-login exposure).
- **Automatic updates** — `unattended-upgrades` active + whether a reboot is
  pending.
- **AppArmor** and **auditd** — service state (`systemctl is-active`).
- **App HTTP hardening** — HTTPS enforcement, HSTS, and CSP from the app itself
  (always shown; needs no host access).

Reading firewall/fail2ban/sshd state needs root. The page never changes
anything; it's **off** unless `SECURITY_STATUS_ENABLED=true`. There are two ways
to feed it, because `harden-debian12.sh` **disables `proc_open` in php-fpm** — so
on a properly hardened host the web app cannot run these commands itself:

**1. Collector + JSON file (recommended; required on a hardened host).** A root
systemd timer runs `bin/security_snapshot.php`, which reads the host state
(directly, as root — no sudo) and writes a world-readable JSON file. The web app
just reads that file — no `proc_open`, no sudo in the web tier, so php-fpm stays
fully locked down. Install the timer and point the app at its output:

```sh
sudo install -m 0644 deploy/idm-security-snapshot.service /etc/systemd/system/
sudo install -m 0644 deploy/idm-security-snapshot.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now idm-security-snapshot.timer
# then in .env:  SECURITY_STATUS_ENABLED=true  and  SECURITY_STATUS_FILE=/var/idm/security-status.json
```

The page shows how long ago the snapshot was collected and flags it stale past
`SECURITY_SNAPSHOT_MAX_AGE` (default 600s) so a stopped timer is obvious. Adjust
`WorkingDirectory`/`ExecStart` paths in the unit files to your install.

**2. Live via sudo (only where php-fpm may spawn processes — i.e. not hardened).**
Leave `SECURITY_STATUS_FILE` unset and the app runs a small, fixed allow-list of
read-only commands via `sudo -n`, which needs the NOPASSWD rule:

```sh
sudo visudo -cf deploy/idm-security-status.sudoers            # syntax check
sudo install -m 0440 -o root -g root deploy/idm-security-status.sudoers \
     /etc/sudoers.d/idm-security-status
```

> If every host card reads **"unknown"** while only the App-HTTP card shows, the
> web app can't execute commands — that's the hardened-host `proc_open` case
> above. Use the collector (option 1). If just the *sudo* commands fail, check
> the sudoers rule and that the web user matches.

Confirm the tool paths on your host (`command -v ufw fail2ban-client sshd`) match
the `SECURITY_*_BIN` env vars (and the sudoers rule, for live mode). Configure the
controls themselves — don't try to change them from here — with
`scripts/harden-debian12.sh`.

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

The PHPUnit suite under `tests/Unit/` covers the config loader, the **matcher**
(exact / employee_id / name+DOB / name-only-never-auto), the importers and
column maps, staff drop-out tracking, RBAC and CSRF, the OneSync write-back/result
paths, AD username linking and the Adaxes service (with an injected HTTP client),
name-case normalization, and the VPN monitor — among others.
