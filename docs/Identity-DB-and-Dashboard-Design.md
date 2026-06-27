# TCS Identity Master — DB + Dashboard Design (draft v0.1)

A custom person-master database and management dashboard that becomes the single
source of truth for staff identity, with OneSync as the provisioning engine.
Companion to `schema.sql` and the project's redesign doc.

**Decisions (set 2026-06-25):** the DB is the **single OneSync source**; OneSync
**mints the username and writes it back** to the DB; build on **PHP + MySQL**
(the existing Codex stack). Scope: faculty/staff (students sync is fine as-is).

## Why this exists — the root problem

OneSync creates **one user per (source, uniqueId)**. The same human is a separate
OneSync user in PowerSchool Teachers, Interns, and NextGen, because each source
keys on its own ID and there's no shared key to merge them. That single fact
causes the intern→employee duplicate, the multi-location "conflicting records →
skip," and the username collisions seen in the logs. It can't be fixed inside
OneSync.

**The fix:** a golden-record DB with one row per human (`person`) and a crosswalk
(`person_source_id`) mapping every system ID to that one person. OneSync then
reads **one** source — a view of this DB — so there is exactly one OneSync user
per person. All the merging/dedup/judgment happens here, where a human can help.

## Architecture (hub-and-spoke)

```
 NextGen (HR)  ─┐
 PowerSchool   ─┤→  Ingestion + Matching  →  PERSON MASTER DB  →  v_onesync_source
 Manual (subs, ─┘     (staging, scoring,        (golden record,      (one row/person)
  contractors,         human review)             crosswalk,                │
  conversions)                                   assignments,              ▼
        ▲                                        lifecycle, audit)      OneSync
        │  Dashboard (PHP) — manage people,            │  ▲             (one user/person)
        └─ merge/convert, set primary location,        │  │                │
           overrides, view audit                       │  │                ▼
                                                        │  │        AD · Google · Raptor
  Username write-back loop:  OneSync mints username ────┘  │        (provisioned)
  → usernames file → (a) PowerSchool 2 AM autocomm         │
                     (b) importer → onesync_writeback ─────┘  → person.username (locked)
```

PowerSchool stays the SIS/SSO record and still receives staff records (from the
DB) and the username (via the 2 AM autocomm). NextGen stays the HR feed. The DB
sits in the middle as the identity authority.

## How it solves each problem

- **Per-source duplication:** one source (the DB view) → one OneSync user per
  person. The crosswalk absorbs all the system IDs.
- **Intern → employee:** the person keeps their `person` row; hiring just adds
  new source IDs (PowerSchool/AD) to `person_source_id`. The name-only match
  becomes a dashboard "same person?" confirmation, not a duplicate account.
- **Multi-location:** `assignment` rows with one `is_primary`; `primary_school_id`
  drives the school sent downstream. No more skip-on-conflict.
- **Username authority:** OneSync mints once (its `unique` check is authoritative
  against AD), writes back to the DB, which locks it (`username_locked`). The
  manual Adaxes pick goes away. `person.username` is UNIQUE for central safety.
- **Data quality:** `school`/`school_code_alias` resolve home-school code →
  real SchoolID; `ethnicity_map` gives complete ALSDE codes; unmapped values are
  surfaced in staging, never silently passed.

## Ingestion + matching pipeline

1. A feed (NextGen export, PowerSchool extract, or a manual entry) loads into
   `import_batch` + `staging_record` (raw JSON + normalized fields).
2. Match each staging row to a `person`, strongest key first:
   - existing `person_source_id` for that (system, source_key) → exact, auto.
   - `employee_id` match → auto.
   - `name + DOB` → high score, auto or review per threshold.
   - `name only` → **never auto**; create a `match_candidate` for human review.
3. Auto-matches update the person + add/refresh the crosswalk + assignments.
   Unmatched-and-no-candidate rows create a new `person` (status `pending`).
4. Review queue (dashboard): a human confirms/rejects candidates. Confirm =
   attach the new source ID to the existing person (the intern→employee link);
   reject = spawn a new person. Every decision is audited.

This is where the hardened script's transform logic moves to — column mapping,
ethnicity/school maps, multi-location collapse, conversion detection — now as the
DB ingestion layer instead of a standalone CSV formatter.

## Dashboard (PHP) — core features

- **People list / detail:** search, view the golden record, crosswalk IDs,
  assignments, lifecycle history, and current username/email/status.
- **Review queue:** pending `match_candidate` rows with a one-click
  "same person / different person" decision (handles intern→employee + dupes).
- **Manual add:** create subs/contractors/long-term staff not in NextGen.
- **Edits & overrides:** set primary location, correct demographics, fix a
  school-code mapping, mark status (disable/terminate) — all audited.
- **Health views:** unmapped ethnicity/school values, people missing a username,
  stale `pending` records, recent feed errors.
- **Per-account sync status:** on the person record, show provisioning status per
  destination (AD / Google / Raptor / PowerSchool) from `account_sync_status` — last
  action, success/fail, time; the health view lists accounts whose last sync failed.
- **Security:** SAML SSO against the district IdP; role-based access (admin / editor /
  readonly) enforced server-side; CSRF + secure sessions + HTTPS; all mutations and logins
  audited. **Read-only OneSync user** limited to `v_onesync_source`; the app never exposes
  passwords; PII access is role-limited and logged.

## OneSync integration

- Add one OneSync **ODBC source** pointing at `v_onesync_source` (read-only DB
  user). `uniqueId = person_uuid`. Retire the per-person sources (PowerSchool
  Teachers ODBC, Interns, the disabled NextGen/Account imports) once cut over.
- Keep the Faculty AD `BlankSAMAccountName` mint rule — it fires when
  `TeacherLoginID` (username) is NULL, i.e. a brand-new person.
- **Write-back:** OneSync continues to emit the usernames file. A small importer
  loads it into `onesync_writeback`; the app applies it to `person.username`/
  `email` and sets `username_locked=1`. The same file still feeds PowerSchool's
  2 AM autocomm. (OneSync has no native MySQL destination, so the file is the bridge.)
- **Account status write-back:** a second importer reads OneSync's export log and
  upserts one current-status row per (person, destination) into `account_sync_status`,
  so each account's provisioning state (AD / Google / Raptor / PowerSchool — last action,
  success/fail, time) shows on the person record and failures roll up to the health dashboard.

## Migration (incremental, low-risk)

1. Stand up the DB (`schema.sql`) and seed `school` / `school_code_alias` /
   `ethnicity_map`.
2. **Seed golden records** from the current PowerSchool/OneSync export
   (`Users_export.csv`) + NextGen; run matching to build `person` +
   `person_source_id`. Work the review queue until the roster is clean.
3. Build the dashboard (people, review queue, manual add, edits, audit).
4. Point a **test** OneSync source at `v_onesync_source`; compare its user set to
   today's. Reconcile.
5. Cut OneSync over to the DB source; retire the legacy per-person sources and
   `NewFaculty.py`. Turn on the write-back importer.
6. Decommission the manual Adaxes creation + Logins spreadsheet; the DB/dashboard
   replaces them.

## Open items / decisions still needed

- Exact `v_onesync_source` field names OneSync must expose (mirror current mappings).
- Seed the reference tables: real `ethnicity_map` codes and the full
  `school` + `school_code_alias` map.
- Matching thresholds (when name+DOB auto-matches vs goes to review).
- How NextGen and PowerSchool deliver to the DB (scheduled file drop the importer
  reads, or a direct pull) and on what schedule relative to the 12 AM OneSync run.
- The SAML IdP (ADFS / Entra ID / Google) + metadata, and the initial admin user(s) to seed.
- The format/location of the OneSync export-log/status file the status importer reads.
- Backups / retention / role-based access for the DB (it's now critical-path).
- Whether students ever join this model (out of scope for now).

## Status

Draft schema + design. Nothing built or connected yet. This supersedes the
earlier "OneSync as the identity layer" decision — the per-source-duplication
limit is why we're moving the authority into a dedicated DB instead.
