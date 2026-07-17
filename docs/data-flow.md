# TCS Identity Master — Data Flow

A plain-language walkthrough of every data path in and out of the system:
what feeds in, what the app does internally, and how it **provisions accounts
directly** to Active Directory (via Adaxes) and Google Workspace. Written to be
handed to design as the source for a detailed flow chart — a suggested swim-lane
breakdown is at the end.

> **Cutover note.** IDM was originally a feeder for **OneSync** (ClassLink's
> sync engine), which minted usernames and provisioned every downstream account.
> IDM is now the **authoritative provisioner**: it mints usernames itself and
> writes AD and Google accounts directly. OneSync is retired for AD/Google at
> cutover — an admin flips one switch (`ONESYNC_DB_SYNC_ENABLED`, the toggle on
> `/admin`) when ready. This document describes the post-cutover, direct model;
> the OneSync path is noted where it still exists as a fallback.

> **Interactive version:** the flow chart built from this doc ships in the
> app itself at **`/reference/data-flow`** (linked from the Reference page) —
> pan/zoom, per-node detail, layer toggles, and animated new-hire / leaver
> traces.

---

## 1. The big picture

TCS Identity Master (IDM) is the **single source of truth for staff/faculty
identity**. It merges every upstream feed into **one golden record per human**,
with a crosswalk of every system ID that person carries — so there is **one
account per person** downstream instead of one per source system. IDM then
**mints the username itself and provisions the accounts directly**: it writes
Active Directory through the Adaxes REST API and Google Workspace through the
Directory/Licensing APIs (or GAM).

```
  UPSTREAM SOURCES              IDM (this app)                         DOWNSTREAM (direct)
┌──────────────────┐   ┌───────────────────────────────┐        ┌──────────────────────────┐
│ NextGen (HR)     │──▶│ Fetch → Stage → Normalize      │        │ Active Directory (Adaxes)│
│ PowerSchool      │──▶│ → Match → Golden record        │──write▶│  create/edit/disable ·   │
│ Intern CSV       │──▶│                                │        │  OU place+move · groups  │
│ Sub CSV          │──▶│ Mint username (UsernameMinter) │        ├──────────────────────────┤
│ Contractor CSV   │──▶│ Reconcile → AD + Google        │──write▶│ Google Workspace         │
│ AD / Adaxes      │◀─▶│ Review queue (humans)          │        │  create/suspend · OU ·   │
│ Students (PS)    │──▶│ Dashboard / audit / Services   │        │  Education Plus license  │
└──────────────────┘   └───────────────────────────────┘        └──────────────────────────┘
                                     │  (legacy, until cutover)
                                     └──▶ OneSync views + result pull  ─▶ Raptor / PowerSchool CSV
```

The provisioning boundary:

- **IDM → AD (Adaxes REST):** a write service account creates/edits/disables
  accounts, mints usernames, places and **moves** OUs, and manages group
  membership. Off by default (`ADAXES_WRITE_ENABLED`), with mass-change safety
  valves; a separate read-only account powers live verification.
- **IDM → Google (Directory + Licensing / GAM):** creates/suspends accounts,
  keeps the org unit correct (moving suspended users to a disabled OU), and
  adds/removes the Education Plus (staff) license, checking seat availability
  first.
- **Legacy — IDM ⇄ OneSync (until cutover):** two read-only views
  (`v_onesync_source`, `v_onesync_student_source`) that OneSync's `onesync_ro`
  account can SELECT, plus IDM's nightly pull of OneSync's result log. Still
  used for students and any destination not yet cut over (Raptor / PowerSchool
  account CSVs); turned off for AD/Google once IDM is authoritative.

---

## 2. Import sources (data flowing INTO the app)

### 2.1 NextGen (HR) — the source of record

- **What:** the district HR system's full staff roster (ITExtract CSV).
- **How it arrives:** pulled nightly from the district **SFTP server**
  (`bin/fetch_feeds.php`, cron or the "Pull from SFTP" button on `/import`);
  re-downloaded whenever the remote file's modification time is newer than
  the last fetch. Manual CSV upload on `/import` is a fallback.
- **What it carries:** employee #, name, e-mail, position, location/school,
  job code/description, hire/start/end dates, ethnicity, gender, phone,
  address.
- **Role:** NextGen is **authoritative** for the golden record's HR fields and
  drives create/update/disable decisions. A person absent from a completed
  NextGen import is flagged "not in NextGen" (drop-out tracking, section 3.5).

### 2.2 PowerSchool — verification + a few owned fields

- **What:** the student-information system's staff tables, read **directly
  from PowerSchool's Oracle database over ODBC** (no CSV/SFTP hop).
  `TEACHERS` is the anchor (one row per teacher-per-school, all assignment IDs
  linked to the crosswalk); `USERS` + extension tables add middle name,
  classifications, DOB, gender, and the Alabama State ID (ALSID).
- **Role:** PowerSchool is pulled mainly to **verify** it agrees with NextGen
  before OneSync runs (field-by-field reconciliation panel, section 3.4). Its
  contact/demographic fields are comparison-only and never overwrite the
  golden record — **except DOB and ALSID, which PowerSchool owns** and which
  are stored. Usernames/emails are deliberately *not* imported — OneSync owns
  those.

### 2.3 Intern, long-term substitute, and contractor CSVs

- **What:** three smaller rosters, one CSV feed each, for people who exist in
  neither NextGen nor PowerSchool.
- **How:** SFTP pull or web upload, same pipeline as NextGen; each has its own
  header map and sets the person type (`intern` / `sub` / `contractor`).
- **Role:** these people live **only in IDM**; the review queue is what links
  an intern who later becomes an employee to their existing record so no
  duplicate account is created.

### 2.4 Students — a separate passthrough (not golden records)

- **What:** active + future enrollments pulled from PowerSchool over the same
  ODBC connection (`bin/import_students.php`).
- **Role:** students get **no matching, no crosswalk, no review** — a straight
  staged copy in a `student` table, exposed to OneSync via its own view. A
  student absent from the latest pull is flagged inactive so OneSync disables
  (never orphans, never hard-deletes) the account.

### 2.5 Active Directory / Adaxes (adopt, verify — and now write)

- **One-time username adoption** (`bin/import_ad_usernames.php`): imports an
  AD/Adaxes/PowerSchool export to adopt the usernames that already existed
  before IDM was authoritative — sets + **locks** each username, records the AD
  `objectGUID` in the crosswalk. This seeds correlation so IDM edits the right
  account instead of creating a duplicate.
- **Live verification** (Adaxes REST API, read-only): the person page fetches
  what AD *currently* holds and compares it to the golden record on demand.
- **Direct writes** (Adaxes REST API, section 4): IDM now creates, edits,
  disables, moves, and manages the group membership of AD accounts. See the
  reconciler in section 4.1.

### 2.6 OneSync results (data coming BACK in — see section 5)

The write-back channels (API / CSV / direct DB / reading OneSync's own DB)
are inputs too, but they're covered with the OneSync loop below.

---

## 3. Internal processes (what the app does)

### 3.1 Fetch

`bin/fetch_feeds.php` (cron, nightly, before the OneSync run — or on demand
from `/import` and `/admin`) downloads each configured SFTP feed and pulls
PowerSchool/students straight from Oracle. Fetches are deduped by remote
file modification time via `feed_fetch_log`.

### 3.2 Stage → Normalize

Every import lands raw in `import_batch` + `staging_record` first — nothing
touches the golden record directly. Normalization then resolves school codes
to `school_id` (via the alias map) and ethnicity values to ALSDE codes;
**unmapped values are logged as warnings** on the staged row and surfaced on
`/reference` because they block clean provisioning. System accounts (Admin,
Lookup) are skipped by name, recorded with a reason.

### 3.3 Match → Apply (the heart of the app)

Each staged row is matched against existing people, strongest key first,
first hit wins:

| Tier | Key | Outcome |
|------|-----|---------|
| 1 | existing crosswalk ID (system + source key) | **auto-link** (this makes re-runs idempotent) |
| 2 | employee ID | **auto-link** |
| 3 | full name + DOB | scored; **auto** if ≥ threshold and unambiguous, else **human review** |
| 4 | full name only | **always human review**, never auto |
| — | no candidate | **new pending person** |

An **auto** match attaches the incoming source ID to the crosswalk
(`person_source_id`), refreshes HR fields on the golden record, and upserts
the school assignment (one primary). A **review** match files a
`match_candidate`. A **new** person is created `pending` — with **no
username/email** yet; the provisioning reconciler (section 4.1) mints the
username and creates the account on its next run.

### 3.4 Review queue (`/review`) — the human checkpoint

Two panels, both editor/admin-gated, every decision audited:

- **Match review:** side-by-side comparison card. *Same person → link & reuse
  account* (the intern→employee path — no duplicate downstream account) or
  *Different people → create new*.
- **Not in NextGen — review to disable:** people who dropped off the NextGen
  feed (or have a past exit date) and are still enabled. A human clicks
  **Disable**; nothing is disabled automatically. Disabling flips the person's
  status so the reconciler **disables** (never deletes) the AD/Google account
  on its next run — the disable phase, guarded by a mass-disable ratio valve.

### 3.5 Drop-out tracking + safety valve

After a completed full-roster import, active crosswalk IDs absent from the
feed are marked inactive (feeding the disable panel above). A safety valve
**blocks** this step if a suspiciously large share of people would drop at
once (default >20%) — the signature of a truncated feed — and logs instead.

### 3.6 Reconciliation (NextGen ↔ PowerSchool)

`FieldMap` is the single crosswalk of which NextGen field maps to which
PowerSchool field and where it lands on the golden record. It drives the
`/reference` field-mapping page and the per-person **source field
reconciliation** panel (NextGen value beside PowerSchool value, verdict:
match / differs / missing), comparing what each system actually staged so
genuine mismatches are visible before provisioning runs.

### 3.7 Username lifecycle + provisioning status

- **Usernames (IDM mints, then locks):** when the reconciler creates an AD
  account it derives the username with `UsernameMinter` — lowercase
  first-initial + last name, an integer suffix on collision (John Smith →
  `jsmith`, a second Smith → `jsmith1`) — sets `person.username`/`email`/`upn`,
  and **locks** it. A locked username is never overwritten with a different
  value (a mismatch logs as `conflict`). Creating the account flips a `pending`
  person to `active`. The one-time AD adoption import and the legacy OneSync
  write-back API can also set + lock a username, for accounts that predate
  direct provisioning.
- **Rename (last-name change):** an admin-approved workflow renames the
  username/email on a schedule — the employee, their principal (looked up in
  PowerSchool), and IT are emailed; the old address keeps delivering as an
  alias in AD **and** Google for a retention window, with reminders, before the
  alias is removed. Delayed steps run off a `scheduled_event` queue.
- **Provisioning status:** every direct write reflects its outcome into
  `account_sync_status` (one row per person, destination) + a capped event
  history — the same table the legacy OneSync pull writes — so the person's
  Provisioning panel and the dashboard failed-sync rollup show direct and
  pulled results identically, with staleness flags.

### 3.8 Everything is observable and audited

Every mutation (imports, review decisions, role changes, write-backs, service
runs, logins) lands in `audit_log` and/or the person's `lifecycle_event`
timeline. The dashboard (`/`) rolls up pending review, pending activation,
missing usernames, unmapped values, failed syncs, disable candidates, feed
freshness, and the **AD and Google sync** tiles (last run + outcome of each
direct sync). `/admin` (Services) shows live health for every moving part —
including the AD and Google syncs — lets admins run jobs on demand, and carries
the **OneSync cutover switch**. Access is SAML SSO + server-side RBAC
(readonly / editor / admin). There are **no hard deletes** anywhere —
deactivation is a status change.

---

## 4. Direct provisioning (what IDM writes)

IDM provisions accounts itself. Two engines run on a nightly timer (and on
demand from `/admin` or the CLI), each with a dry-run mode and mass-change
safety valves, each recording every real run in `service_run` for the
dashboard/Services status.

### 4.1 Active Directory — the Adaxes reconciler (`bin/adaxes_sync.php`)

Writes go through the Adaxes REST API with a dedicated write service account,
**off by default** (`ADAXES_WRITE_ENABLED=false` → the run previews and writes
nothing). Correlation is by `objectGUID` (crosswalk), else an OR-search on
`sAMAccountName` / `mail` / `employeeID` — so IDM edits the existing account
rather than duplicating it. The run is four ordered phases:

1. **Disable** — a linked leaver still enabled in AD is disabled (never
   deleted). A ratio valve blocks a mass-disable from a truncated feed.
2. **Edit** — pushes golden↔AD drift: identity fields, plus the operational
   mappings `title` + `description`, `department` + `physicalDeliveryOfficeName`
   (Office = the school; transportation staff overridden), and `info` (the
   Google email). It also **moves the OU** when the account's container has
   drifted from where it should live. `sAMAccountName` is immutable here.
3. **Create** — mints the username (`UsernameMinter`), creates the account in
   the computed OU, links the returned `objectGUID`, and stamps the golden
   record (username locked, email/UPN set, activated). An absolute per-run
   create cap applies; a locked-but-unlinked person is routed to review, never
   duplicated.
4. **Groups** — reconciles membership against the policy (`GroupPolicy`),
   touching **only** the groups IDM manages so manual/custom groups are never
   disturbed: All-Faculty (everyone), the per-school *Everyone* group (from the
   building OU token, with `RQES→RQS` / `UPE→UP` remaps), Transportation, one
   M365 license group (A1 by title keywords + contractor/sub/intern, else A3),
   and one Raptor role group by title (BuildingAdmin / ClientAdmin / EntryAdmin
   / GlobalAdmin, else EmergencyManagement) — with a **per-person Raptor
   exception** an admin can set on the person page — plus the additive
   Raptor_StudentSafeUser group for Principals, Assistant Principals, Social
   Workers, and Counselors (granted on top of their Raptor role).

**OU placement:** `{type-leaf,] {school.ad_ou}, {AD_PARENT_OU}, {AD_BASE_DN}`;
transportation staff go to a transportation OU with no building segment, SROs
under an SRO leaf. All group/OU/attribute names are configurable; endpoints are
version-specific.

### 4.2 Google Workspace — the Google sync (`bin/sync_google.php`)

Direct provisioning through the Directory + Enterprise License Manager APIs (or
GAM), **off** unless `GOOGLE_DIRECT_ENABLED` + credentials are set. Per person:

- **Create** an account for an active person with a golden email but no Google
  account; **suspend** (never delete) a disabled/terminated person's account;
  **push** name drift.
- **Org unit** — active users are kept in their building's OU
  (`school.google_ou`), moved back if drifted; **suspended users are moved to
  the disabled OU** (`GOOGLE_DISABLED_OU`, default `/tcs/faculty/disabled`).
- **Education Plus (staff) license** — assigned to active faculty/staff and
  removed from suspended accounts, **checking seat availability first** (a
  configured seat cap is never over-subscribed; blocked assignments are
  reported).
- A mass-suspend ratio valve guards against a truncated feed. `--only` /
  `--employee` restrict a run to a test cohort.

### 4.3 Legacy contract — the OneSync views (until cutover)

Until an admin turns OneSync off, IDM still exposes two **read-only views** its
`onesync_ro` account can SELECT (and nothing else), for students and any
destination not yet cut over.

`v_onesync_source` — staff, one row per person (OneSync's own field names):

| Column | Meaning |
|--------|---------|
| `ID` | the person UUID — the stable `uniqueId` OneSync echoes back on write-back |
| `PSID` | active PowerSchool ID from the crosswalk |
| `TeacherNumber` / `EmployeeID` | employee ID (both names, same value) |
| `FirstName`, `LastName`, `Email`, `Ethnicity` | golden-record values |
| `Job Code Desc` / `Title` | NextGen job description / PowerSchool title |
| `HomeSchoolID` | PowerSchool school ID of the primary assignment |
| `username` | the IDM-minted username (blank only for a not-yet-provisioned person) |
| `StatusActive` | 1 = active/pending, **0 = disable** (row stays so OneSync disables rather than orphans) |

`v_onesync_student_source` — one row per student, keyed by `student_uuid`, with
`StatusActive = 0` marking students who left. **Students still flow through
OneSync** — IDM does not provision student accounts directly.

---

## 5. Cutover + the legacy OneSync loop

### 5.1 The cutover switch

`ONESYNC_DB_SYNC_ENABLED` (the toggle on `/admin`, stored in `app_setting`)
turns the OneSync result pull on/off. While on (pre-cutover), OneSync provisions
in parallel and IDM pulls its results; once IDM is confirmed authoritative for
AD/Google, an admin turns it **off** — `bin/import_onesync_db.php` then skips,
the Services card shows "cutover", and IDM's direct engines are the only writers
for AD/Google.

### 5.2 What OneSync still does (while enabled / for students, Raptor, PS)

OneSync (ClassLink's sync engine) reads the two views nightly, matches by
`uniqueId`, and provisions the destinations it still owns. It reports back:

- **Username / initial-password write-back** — `POST /api/onesync/username`
  (or a CSV / direct INSERT) and `POST /api/onesync/password` (stored
  encrypted). IDM applies + locks the username. *After cutover, IDM mints
  usernames itself, so this is only relevant for the legacy path.*
- **Provisioning status** — IDM **pulls** one result per (person, destination)
  from OneSync's own DB (`bin/import_onesync_db.php`, reading `os_users` /
  `os_export_log` read-only) into `account_sync_status`. OneSync never pushes
  its export log.

The full loop for a new hire, **post-cutover**:

```
NextGen feed → IDM stages/matches → new pending person (username NULL)
  → adaxes_sync create phase mints "jdoe", creates the AD account, links objectGUID,
    locks the username, activates the person
  → google sync creates the Google account, sets the OU, assigns the Education Plus license
  → adaxes groups phase adds All-Faculty + school-Everyone + M365 + Raptor role
  → outcomes reflected into account_sync_status → Provisioning panel / dashboard tiles
```

And for a leaver: NextGen drops the row → IDM flags the drop-out → a human
approves **Disable** on `/review` → the adaxes disable phase disables the AD
account and the google sync suspends the Google account (moving it to the
disabled OU and releasing its license) — never deleted, all audited.

---

## 6. Suggested flow-chart structure (for design)

**Five swim lanes, left to right:**

1. **Upstream sources** — NextGen (SFTP·CSV), PowerSchool (Oracle·ODBC),
   Intern/Sub/Contractor (CSV), AD/Adaxes (one-time adopt + read-only live
   verify), Students (Oracle·ODBC).
2. **IDM ingestion** — Fetch → Stage (`import_batch`/`staging_record`) →
   Normalize (school + ethnicity maps; unmapped-value warnings) → Match
   (tiers 1–4) with three exits: *auto-link*, *review*, *new pending*.
3. **IDM golden record & humans** — golden `person` + `person_source_id`
   crosswalk; **username minting (`UsernameMinter`)**; Review queue (link vs.
   new; disable approvals); drop-out tracking + safety valve; rename/unlink
   workflows; dashboard/audit/Services alongside. Students bypass this lane
   (straight to their view — a distinct lower track).
4. **IDM provisioning engines** — the **Adaxes reconciler** (disable → edit
   → create → groups; OU place + move; write account) and the **Google sync**
   (create/suspend; OU move to disabled; Education Plus license add/remove with
   a seat check). Draw the phase order and the two safety valves (mass-disable
   ratio, create cap / mass-suspend ratio). This lane is what replaced OneSync.
5. **Downstream destinations** — **Active Directory (direct, via Adaxes)** and
   **Google Workspace (direct)** as write targets; **Raptor / PowerSchool
   account CSVs and students** on the *legacy OneSync* path (draw OneSync as a
   smaller box beneath lane 4, reading the views and pulling results — gated by
   the cutover switch).

**Return / status edges:** the direct engines write their outcome into
`account_sync_status` (→ Provisioning panel + dashboard AD/Google tiles); the
legacy path adds OneSync's *username write-back* (→ set + lock + activate) and
IDM's *read-only results pull* from OneSync's DB. Mark the cutover switch as the
gate on that legacy loop.

**Styling cues:** mark read-only edges (IDM→Adaxes verify, IDM→OneSync DB pull,
OneSync→views) differently from write edges (IDM→AD, IDM→Google); put the
human-approval steps (review queue, disable, rename approval) in a distinct
"manual gate" shape; and badge the hard invariants — *IDM mints the username and
locks it forever after*, *group/OU changes touch only IDM-managed groups*, and
*nothing is ever hard-deleted, only disabled*.
