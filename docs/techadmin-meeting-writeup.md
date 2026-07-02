# TCS Identity Master — Tech Admin Meeting Write-Up

## What it is

TCS Identity Master (IDM) is our new single source of truth for staff and
faculty identity. It keeps **one "golden record" per human being**, with a
crosswalk of every system ID that person carries (NextGen employee #,
PowerSchool teacher IDs, AD objectGUID, etc.), and exposes it to OneSync as a
single read-only database view. The result: OneSync provisions **exactly one
account per person** instead of one per source system.

## The problem it solves

Today the same person can arrive from multiple feeds — hired in NextGen,
already in PowerSchool as an intern, already holding an AD account — and each
feed can spawn its own downstream account. Duplicate accounts, orphaned
accounts when someone leaves, and no one place to see a person's full identity
across systems. IDM sits between the source systems and OneSync to fix that.

## How it works (in one paragraph)

Nightly, IDM pulls the NextGen HR export from the district SFTP server and
reads PowerSchool directly from its Oracle database (read-only ODBC). Interns,
long-term subs, and contractors come in via their own CSV feeds or manual
entry. Each incoming row is matched to a person using tiered rules — known
source ID, then employee ID, then name + date of birth. Anything ambiguous
goes to a **human review queue**; a name-only match is *never* auto-linked, so
two different "John Smiths" can't be merged by accident. OneSync then reads
one clean view (`v_onesync_source`) and writes back the usernames and
per-system provisioning results it mints, which IDM locks onto the record.
Students are handled separately as a straight PowerSchool → OneSync
passthrough (no matching, no editing — just sync status on the dashboard).

## What staff see in the web app

- **Dashboard** — health at a glance: pending reviews, pending activations,
  failed syncs, people flagged for disable, last feed per source.
- **People** — search everyone, open a person to see their golden record,
  every source-system ID, a NextGen-vs-PowerSchool field comparison, OneSync
  provisioning status per destination (AD, Google, …), and a live
  Active Directory check (read-only, via the Adaxes API).
- **Review queue** — resolve possible duplicate matches ("same person — link"
  vs. "different people — create new") and approve disables for people who
  have dropped off the NextGen feed. **Nothing is disabled automatically** — a
  human approves every one, and every decision is audited.
- **Import** — batch history, per-row match results, browser CSV upload, and a
  "Pull from SFTP" button.

## Security posture

- Sign-in is **SAML SSO against the district IdP**; no page is reachable
  unauthenticated.
- **Role-based access** (readonly / editor / admin) enforced server-side on
  every route, not just hidden in the UI.
- HTTPS enforced with HSTS, strict CSP, CSRF protection on every form, and
  full audit logging (logins, role changes, all data mutations).
- **Least-privilege database roles**: the web app can't run DDL or delete,
  OneSync's account can read only the two views, and the write-back account
  can touch only its own tables. Credentials live in the environment, never
  in the repo.
- Integrations are read-only where possible: PowerSchool via a SELECT-only
  Oracle account; AD verification reads through Adaxes and never writes.
- No hard deletes anywhere — deactivation is a status change and history is
  preserved.

## Current status

All seven build milestones are complete — the app is **feature-complete** per
the build plan: ingestion + matching, review queue, OneSync interface (views,
CSV/API/direct-DB write-back), dashboard, students passthrough, and the
SSO/RBAC hardening milestone. Stack is deliberately boring and maintainable:
PHP 8.2+, MySQL/MariaDB, no heavy framework, PHPUnit tests, one-script server
provisioning, and systemd timers for the nightly jobs.

## What we need before/at cutover

1. **Real reference data** — replace the placeholder school map and ALSDE
   ethnicity CSVs with the district's actual values.
2. **Confirm OneSync's file/column formats** for the username and export-log
   write-backs (current maps are documented assumptions).
3. **SAML registration** with the district IdP and initial admin assignment.
4. **Backups** — the DB becomes authoritative for staff identity, so nightly
   dumps with ≥30-day retention and a tested restore, finishing before the
   midnight OneSync run.
5. **One-time AD username adoption** — import the existing AD usernames so
   OneSync doesn't re-mint accounts people already have.

## Questions welcome

Full documentation lives in the repo: end-user guide (`docs/user-guide.md`),
architecture (`docs/Identity-DB-and-Dashboard-Design.md`), OneSync API
reference, SAML setup, and server hardening notes.
