# TCS Identity Master — Data Flow

A plain-language walkthrough of every data path in and out of the system:
what feeds in, what the app does internally, what it hands to OneSync, and
what OneSync does with it. Written to be handed to design as the source for
a detailed flow chart — a suggested swim-lane breakdown is at the end.

> **Interactive version:** the flow chart built from this doc ships in the
> app itself at **`/reference/data-flow`** (linked from the Reference page) —
> pan/zoom, per-node detail, layer toggles, and animated new-hire / leaver
> traces.

---

## 1. The big picture

TCS Identity Master (IDM) is the **single source of truth for staff/faculty
identity**. It merges every upstream feed into **one golden record per human**,
with a crosswalk of every system ID that person carries. OneSync (ClassLink's
identity/roster sync product) reads exactly **one view, one row per person**,
so it provisions **one account per person** downstream instead of one per
source system.

```
  UPSTREAM SOURCES              IDM (this app)                    ONESYNC                DOWNSTREAM
┌──────────────────┐   ┌───────────────────────────┐   ┌───────────────────────┐   ┌──────────────────┐
│ NextGen (HR)     │──▶│ Fetch → Stage → Normalize │──▶│ reads v_onesync_source│──▶│ Active Directory │
│ PowerSchool      │──▶│ → Match → Golden record   │   │ + v_onesync_student_  │──▶│ Google Workspace │
│ Intern CSV       │──▶│                           │   │   source (read-only)  │──▶│ Raptor (CSV)     │
│ Sub CSV          │──▶│ Review queue (humans)     │   │                       │──▶│ PowerSchool (CSV)│
│ Contractor CSV   │──▶│ Dashboard / audit         │   │ mints usernames,      │   └──────────────────┘
│ AD / Adaxes      │──▶│                           │◀──│ provisions accounts,  │
│ Students (PS)    │──▶│ applies write-backs       │   │ reports results back  │
└──────────────────┘   └───────────────────────────┘   └───────────────────────┘
```

Two one-way "contracts" define the boundary:

- **IDM → OneSync:** two read-only database views (`v_onesync_source` for
  staff, `v_onesync_student_source` for students). OneSync's DB account
  (`onesync_ro`) can SELECT those views and nothing else.
- **OneSync → IDM:** OneSync reports what it did — the username it minted and
  the per-destination provisioning result — via an API, CSV files, or direct
  table writes (all three land in the same place with the same guardrails).

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

### 2.5 Active Directory / Adaxes (one-time + on-demand)

- **One-time username adoption** (`bin/import_ad_usernames.php`): imports an
  AD/Adaxes/PowerSchool export to adopt the usernames that already existed
  before OneSync was authoritative — sets + **locks** each username, records
  the AD `objectGUID` in the crosswalk.
- **Live verification** (Adaxes REST API, read-only): the person page fetches
  what AD *currently* holds and compares it to the golden record on demand.
  IDM never writes to AD.

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
username/email**; the app never mints those, that's OneSync's trigger.

### 3.4 Review queue (`/review`) — the human checkpoint

Two panels, both editor/admin-gated, every decision audited:

- **Match review:** side-by-side comparison card. *Same person → link & reuse
  account* (the intern→employee path — no duplicate downstream account) or
  *Different people → create new*.
- **Not in NextGen — review to disable:** people who dropped off the NextGen
  feed (or have a past exit date) and are still enabled. A human clicks
  **Disable**; nothing is disabled automatically. Disabling flips the person's
  status so OneSync **disables** the account on its next read.

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

### 3.7 Applying OneSync's write-backs

- **Usernames:** whatever channel OneSync uses, the app sets
  `person.username`/`email` and **locks** the username — once locked it is
  never overwritten with a different value (a mismatch logs as `conflict`).
  Applying a username flips a `pending` person to `active`.
- **Provisioning status:** upserted one row per (person, destination) into
  `account_sync_status`, plus a capped append-only event history. This feeds
  the person's Provisioning panel and the dashboard failed-sync rollup, with
  staleness flags when OneSync hasn't reported recently.

### 3.8 Everything is observable and audited

Every mutation (imports, review decisions, role changes, write-backs, service
runs, logins) lands in `audit_log` and/or the person's `lifecycle_event`
timeline. The dashboard (`/`) rolls up pending review, pending activation,
missing usernames, unmapped values, failed syncs, disable candidates, and
feed freshness. `/admin` shows live service health and lets admins run the
jobs on demand. Access is SAML SSO + server-side RBAC
(readonly / editor / admin). There are **no hard deletes** anywhere —
deactivation is a status change.

---

## 4. Outputs to OneSync (data flowing OUT of the app)

OneSync connects as a **read-only DB user** that can see exactly two views
and no base tables.

### 4.1 `v_onesync_source` — staff, one row per person

Key columns (OneSync's own field names):

| Column | Meaning |
|--------|---------|
| `ID` | the person UUID — the stable `uniqueId` OneSync must echo back on every write-back |
| `PSID` | active PowerSchool ID from the crosswalk |
| `TeacherNumber` / `EmployeeID` | employee ID (both names, same value) |
| `FirstName`, `LastName`, `Email`, `Ethnicity` | golden-record values |
| `Job Code Desc` | NextGen job description (falls back to PowerSchool title) |
| `Title` | the PowerSchool title (distinct from the NextGen one) |
| `HomeSchoolID` | PowerSchool school ID of the primary assignment |
| `username` | **NULL until OneSync mints one** — a blank username is the signal that fires OneSync's new-account rule |
| `StatusActive` | 1 = active/pending, **0 = disable this account** (disabled people stay in the view so OneSync disables rather than orphans) |

### 4.2 `v_onesync_student_source` — students passthrough

Same pattern: one row per student, keyed by a stable `student_uuid`, with
`StatusActive = 0` marking students who left so OneSync disables the account.

---

## 5. What OneSync does (and its outputs)

OneSync is **ClassLink's identity/roster sync engine** — a rules engine that
sits between IDM and the district's account systems. Per (nightly ~12 AM)
run:

1. **Reads** each configured *source* — for us, the two IDM views above.
2. **Matches rows to its own user store** by `uniqueId` (our person UUID) and
   calculates what changed (new person, changed fields, `StatusActive` 0/1).
3. **Mints usernames/emails** for rows arriving with a blank `username`
   (its `BlankSAMAccountName` rule). OneSync — not IDM — owns username and
   email format/uniqueness.
4. **Provisions to destinations**, applying add / edit / disable per person:
   - **Active Directory** (create account, set attributes, OU placement,
     enable/disable),
   - **Google Workspace** (account + OU/license),
   - **Raptor** (visitor/safety system — CSV export),
   - **PowerSchool** (staff account sync — CSV export).
   Threshold safety checks guard against runaway mass changes.
5. **Reports back to IDM** (its outputs, closing the loop):
   - **Username write-back** — the minted username/email for each new person,
     delivered by any of: `POST /api/onesync/username` (token-authenticated,
     real-time), a usernames CSV, or a direct INSERT into the
     `onesync_writeback` landing table. IDM applies it, **locks** it, and
     activates the person.
   - **Initial-password write-back** — the temporary password OneSync set
     for a newly created account, delivered via `POST /api/onesync/password`
     and stored encrypted.
   - **Provisioning status** — one result per (person, destination): action
     (Add/Edit/Disable/…), status (Success/Fail/…), and the failure message.
     OneSync does **not** push these; IDM **pulls them from OneSync's own
     database** (`bin/import_onesync_db.php` reads its `os_users` /
     `os_export_log` tables read-only) and upserts them into
     `account_sync_status`.

So the full loop for a new hire is:

```
NextGen feed → IDM stages/matches → new pending person (username NULL)
  → OneSync reads view, sees blank username → mints "jdoe"
  → OneSync creates AD + Google + Raptor + PowerSchool accounts
  → OneSync writes back username (+ initial password) → IDM locks it, person becomes active
  → IDM pulls per-destination results from OneSync's DB → Provisioning panel / dashboard
```

And for a leaver: NextGen drops the row → IDM flags the drop-out → a human
approves **Disable** on `/review` → the view shows `StatusActive = 0` →
OneSync disables (never deletes) the account everywhere → IDM's nightly DB
pull picks up the per-destination results.

---

## 6. Suggested flow-chart structure (for design)

**Five swim lanes, left to right:**

1. **Upstream sources** — NextGen (SFTP·CSV), PowerSchool (Oracle·ODBC),
   Intern/Sub/Contractor (CSV), AD/Adaxes (one-time + read-only live),
   Students (Oracle·ODBC).
2. **IDM ingestion** — Fetch → Stage (`import_batch`/`staging_record`) →
   Normalize (school + ethnicity maps; unmapped-value warnings) → Match
   (tiers 1–4) with three exits: *auto-link*, *review*, *new pending*.
3. **IDM golden record & humans** — golden `person` + `person_source_id`
   crosswalk; Review queue (link vs. new; disable approvals); drop-out
   tracking + safety valve; dashboard/audit alongside. Students bypass this
   lane entirely (straight to their view — worth drawing as a distinct
   lower track).
4. **OneSync** — reads the two views; decision diamond on blank username →
   mint; decision on `StatusActive` → add/edit vs. disable; provision fan-out.
5. **Downstream destinations** — Active Directory, Google Workspace, Raptor,
   PowerSchool.

**Two return arrows** from OneSync back into lane 3 (drawn as a loop):
*username/password write-back* (→ set + lock + activate; annotated with its
delivery options — API / CSV / direct DB) and *IDM's provisioning-results pull
from OneSync's DB* (→ Provisioning panel + failed-sync dashboard; drawn as a
read-only pull, not a push).

**Styling cues:** mark read-only edges (OneSync→views, IDM→Adaxes,
IDM→OneSync DB) differently from write edges; put the human-approval steps
(review queue, disable) in a distinct "manual gate" shape; and badge the two
hard invariants — *usernames are minted only by OneSync and locked forever
after* and *nothing is ever hard-deleted, only disabled*.
