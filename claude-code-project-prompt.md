# Project prompt for Claude Code — TCS Identity Master

Paste everything below the line into Claude Code at the root of a new repo. Copy the
existing specs into the repo first (so Claude Code can read them):
`schema.sql`, `Identity-DB-and-Dashboard-Design.md`, `claude-design-prompt.md`,
`current-state-SOP.md`, and `log-analysis-2026-06-25.md` → put them in `docs/`.

---

You are building the **TCS Identity Master** — an internal PHP + MySQL web app and a set
of CLI importers that serve as the single source of truth for staff/faculty identity at a
K-12 district. Work in small, reviewable steps. **Before writing code, read everything in
`docs/`** — especially `schema.sql` (the data model) and `Identity-DB-and-Dashboard-Design.md`
(the architecture). Then propose a plan and wait for my go-ahead before building each milestone.

## Mission (the why)

Today, the sync engine (OneSync) creates a separate user per *source* for the same person,
which causes duplicate accounts, name-collision usernames, and multi-location skips. This
app fixes the root cause: it keeps **one golden record per human** with a crosswalk of all
their system IDs, and exposes a single read-only view that OneSync consumes — so OneSync
produces exactly one user per person. OneSync mints the username and writes it back here.

## Stack & conventions

- **PHP 8.2+, MySQL 8+.** Plain PDO with prepared statements (no ORM required). A light
  structure is fine — no heavy framework. Server-rendered pages (Twig or plain PHP
  templates); progressive enhancement only where it helps.
- Layout: `public/` (web root), `src/` (app code: models, services, controllers),
  `bin/` (CLI importers + jobs), `db/` (migrations, seeds), `docs/`, `tests/`.
- **Config & secrets from environment** (`.env` via a loader, gitignored). Never hardcode
  DB creds, paths, or tokens. Provide `.env.example`.
- `db/migrations/0001_init.sql` = the provided `schema.sql` (split into migrations if you
  prefer). Add a tiny migration runner in `bin/`.
- Coding: typed properties, small services, no business logic in templates. Comment the
  non-obvious (especially the matcher).

## What to build

1. **Database layer + migrations + seeds.** Apply `schema.sql`. Seed scripts for the
   reference tables (`school`, `school_code_alias`, `ethnicity_map`) from CSVs in `db/seeds/`.
2. **Person service + dashboard (mirror the UI in `docs/claude-design-prompt.md`):**
   - People list (search/filter by status, type, school, "missing username", "pending").
   - Person detail: golden record, **source-ID crosswalk**, **assignments** (multi-location,
     one primary), lifecycle/audit timeline. Username/email **read-only** (assigned by
     OneSync) with a locked indicator. Editable: demographics, primary location, status, notes.
   - Add person (manual) for subs/contractors.
   - Home/health dashboard (KPIs: pending review, missing username, unmapped codes, last feed).
   - Reference-data admin (schools, ethnicity map) surfacing "unmapped" values.
   - Import/feed status (batches + drill-in to staged rows).
3. **Ingestion + matching pipeline (`bin/import_*.php`):** load a NextGen export / PowerSchool
   extract into `import_batch` + `staging_record`, normalize fields (resolve school code →
   SchoolID, ethnicity → ALSDE code via the maps; log unmapped). Then **match** each staged
   row to a person, strongest key first:
   - existing `person_source_id` (system, source_key) → exact, auto-apply.
   - `employee_id` → auto.
   - `name + DOB` → score; auto above a configurable threshold, else review.
   - **name only → NEVER auto; create a `match_candidate` for human review.**
   Auto-matches update the person + crosswalk + assignments; truly-new rows create a
   `person` with status `pending`. Importers must support `--dry-run` and be idempotent.
4. **Review queue (the hero feature):** UI to work `match_candidate` rows — side-by-side
   incoming vs. candidate with field-level highlight; **Confirm = same person** (attach the
   new source ID(s) to the existing person — this is the intern→employee link) or **Reject =
   different person** (spawn a new person). Audit every decision.
5. **OneSync interface:**
   - `v_onesync_source` already exists in the schema; create a **read-only MySQL user**
     limited to it (document the GRANT).
   - **Username write-back importer (`bin/import_writeback.php`):** read the usernames file
     OneSync emits, load into `onesync_writeback`, apply to `person.username`/`email`, and set
     `username_locked = 1`. Idempotent; never overwrite a locked username with a different value.
   - **Account status write-back (`bin/import_sync_status.php`):** read the OneSync export
     log (username, uniqueId, action, actionStatus, destination, timestamps, message) and
     **upsert one current-status row per (person, destination)** into `account_sync_status`
     (optionally append to `account_sync_event`, rotated/capped). This reflects each account's
     provisioning state in AD / Google / Raptor / PowerSchool in the dashboard.
6. **Security — SAML SSO + RBAC + audit (required, not optional):**
   - **SSO via SAML** against the district IdP (ADFS / Entra ID / Google) using a maintained
     library (`onelogin/php-saml` or simpleSAMLphp). IdP entityID/metadata/certs from config
     (env). On login, map the SAML NameID/email to an `app_user` + role; create first-login
     users as `readonly` pending an admin grant. No page is reachable unauthenticated.
   - **Role-based access enforced server-side on every route** (not just hidden in the UI):
     `admin` (full + manage users + reference data + override decisions), `editor` (edit
     people, work the review queue, manual add), `readonly` (view only — no write route reachable).
   - Secure sessions (HttpOnly, Secure, SameSite), **CSRF tokens on every form**, security
     headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options), HTTPS only.
   - **Audit every mutation and every login/logout** (before/after JSON, actor = the SAML user).
7. **Tests:** unit-test the **matcher** thoroughly (it's the risky part) — exact/employee/
   name+DOB/name-only tiers, and that name-only never auto-links. Add fixtures incl. an
   intern→employee pair and a multi-location person.

## Build order (one milestone at a time; stop for review after each)

1. Scaffold + config + DB layer + migrations + reference seeds.
2. Person service + people list + person detail (read) + audit.
3. Ingestion + matcher + staging, with `--dry-run` and unit tests.
4. Review queue UI (confirm/reject linking).
5. OneSync read-only user + write-back importer.
6. Home/health dashboard + reference-data admin + import status.
7. Hardening: auth/RBAC, validation, logging, `.env.example`, README + ops/backup notes.

## Guardrails (do not violate)

- **No hard deletes.** Use status changes + audit. Deactivate, don't destroy.
- **Never auto-merge on name alone** — weak matches go to the review queue.
- **Username immutability:** once `username_locked`, never re-mint or rename; the app never
  assigns usernames itself (OneSync does).
- Secrets only from environment. Prepared statements everywhere (no string-built SQL).
- This holds staff PII (DOB, demographics) — **HTTPS only, SAML SSO, RBAC enforced
  server-side on every route**, access logged. Treat it as a security-sensitive system.
- **Least-privilege DB users:** OneSync = read-only on `v_onesync_source` only; the app has
  its own account; the write-back importers use a limited writer (the `onesync_writeback`,
  `account_sync_status`, `account_sync_event` tables). Never share or reuse accounts.

## Acceptance criteria

- Exactly one `person` per human; all their source IDs hang off it via `person_source_id`.
- Importing a "hired intern" record links to the existing person (no duplicate) after a
  review-queue confirm; a same-named different person can be kept separate.
- Multi-location person resolves to one primary; `v_onesync_source` returns **one row** for them.
- Write-back sets and locks the username/email; re-runs are no-ops.
- Every create/update/status change/merge is in `audit_log`.
- Matcher unit tests pass, including the name-only-never-auto case.
- Access requires SAML SSO (unauthenticated → redirected to the IdP); a `readonly` user
  cannot reach any write route; logins and all mutations are audited.
- Each person shows current per-destination sync status (AD / Google / Raptor / PowerSchool)
  from `account_sync_status`; the health dashboard lists accounts whose last sync failed.

## Confirm with me before assuming

- The SAML IdP (ADFS / Entra ID / Google), its metadata/entityID/cert, and the initial
  admin user(s) to seed.
- The format/location of the OneSync export-log/status file the status importer reads.
- Where MySQL runs and how NextGen/PowerSchool files are delivered (paths/schedule) — the
  importers should take paths from config.
- Exact `v_onesync_source` field names OneSync needs (mirror current OneSync source mappings).
- The real ethnicity→ALSDE codes and the school-code→SchoolID map for the seed CSVs.

Start by reading `docs/`, then give me a short plan for Milestone 1.
