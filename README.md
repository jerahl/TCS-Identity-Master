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

> **Status:** Milestone 1 — scaffold, config, DB layer, migrations, reference
> seeds. The dashboard, importers, matcher, review queue, OneSync write-back, and
> SAML/RBAC arrive in later milestones.

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

## Least-privilege DB users

One database, four roles — never shared or reused. Replace passwords and host
masks (`'%'`) to match your deployment. The app **never** connects as the
migrator or the OneSync reader.

```sql
-- 1) Application account — the dashboard/web app.
CREATE USER 'idm_app'@'%' IDENTIFIED BY 'change-me-app';
GRANT SELECT, INSERT, UPDATE ON tcs_identity.* TO 'idm_app'@'%';
-- No DELETE (no hard deletes — status changes + audit instead), no DDL, no GRANT.
REVOKE INSERT, UPDATE ON tcs_identity.v_onesync_source FROM 'idm_app'@'%'; -- view is read-only

-- 2) Migrator / schema owner — used ONLY by bin/migrate.php, from a trusted shell.
CREATE USER 'idm_migrate'@'%' IDENTIFIED BY 'change-me-migrate';
GRANT ALL PRIVILEGES ON tcs_identity.* TO 'idm_migrate'@'%';
-- Needs CREATE DATABASE on first run:
GRANT CREATE ON *.* TO 'idm_migrate'@'%';

-- 3) Write-back importer — limited writer for the OneSync write-back jobs only.
CREATE USER 'idm_writeback'@'%' IDENTIFIED BY 'change-me-writeback';
GRANT INSERT, UPDATE, SELECT ON tcs_identity.onesync_writeback    TO 'idm_writeback'@'%';
GRANT INSERT, UPDATE, SELECT ON tcs_identity.account_sync_status  TO 'idm_writeback'@'%';
GRANT INSERT, UPDATE, SELECT ON tcs_identity.account_sync_event   TO 'idm_writeback'@'%';
-- Needs to apply usernames to the golden record (set + lock):
GRANT SELECT, UPDATE ON tcs_identity.person TO 'idm_writeback'@'%';

-- 4) OneSync reader — READ-ONLY on the single view, nothing else.
CREATE USER 'onesync_ro'@'%' IDENTIFIED BY 'change-me-onesync';
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
