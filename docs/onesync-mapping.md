# OneSync ⇆ TCS Identity Master — direct DB integration mapping

How OneSync reads from and writes back to the Identity Master database. OneSync
**pulls** from one view and **writes back** the username/email it mints (plus
the initial password, via the API — see `onesync-api.md`). Per-user provisioning
status (success/failure + message) is **not** written back by OneSync: IDM pulls
it straight from OneSync's own database (`bin/import_onesync_db.php`). Sections
3–4 below describe the retired direct-write status path and are kept only as a
schema reference — the DB pull upserts the same rows.

- DB: MySQL/MariaDB, database `tcs_identity` (adjust to your deployment).
- Stable key everywhere is **`person_uuid` (CHAR(36))**, surfaced on the read view
  as **`ID`**. Use it as the OneSync `uniqueId` and as the join key on write-back.

---

## 1. READ — source view: `v_onesync_source`  (read-only)

Connect as the read-only user (`onesync_ro`). One row **per person**; the only
object OneSync should read. The view exposes exactly the columns OneSync's
faculty profile consumes, under OneSync's own names.

| OneSync field    | View column      | Type        | Notes |
|------------------|------------------|-------------|-------|
| `ID`             | `ID`             | CHAR(36)    | = `person_uuid`. The stable identity key (the value used as `uniqueId` on write-back). |
| `PSID`           | `PSID`           | VARCHAR(128)| active PowerSchool id from the crosswalk (`person_source_id`, `system='powerschool'`); nullable. |
| `Job Code Desc`  | `Job Code Desc`  | VARCHAR(120)| the golden (NextGen) job description — **primary** assignment's `title`, **falling back to the PowerSchool `Title`** when the NextGen value is blank; nullable. (Column name contains a space — quote it.) |
| `HomeSchoolID`   | `HomeSchoolID`   | VARCHAR(20) | PowerSchool `SchoolID` of the **primary** assignment. |
| `TeacherNumber`  | `TeacherNumber`  | VARCHAR(40) | = `employee_id`, nullable (subs/contractors/interns may lack one). |
| `EmployeeID`     | `EmployeeID`     | VARCHAR(40) | same source as `TeacherNumber` (`employee_id`); exposed under both names. |
| `Email`          | `Email`          | VARCHAR(160)| nullable until assigned. |
| `username`       | `username`       | VARCHAR(64) | = `username`. **NULL until minted** → fires OneSync's `BlankSAMAccountName` rule for new people. |
| `Title`          | `Title`          | VARCHAR(120)| the **PowerSchool** title (`USERS`/`TEACHERS.Title`), from the latest PowerSchool import snapshot; nullable. Distinct from `Job Code Desc` (NextGen). |
| `FirstName`      | `FirstName`      | VARCHAR(80) | |
| `LastName`       | `LastName`       | VARCHAR(80) | |
| `StatusActive`   | `StatusActive`   | 1 / 0       | 1 when status ∈ (active, pending). |
| `Ethnicity`      | `Ethnicity`      | VARCHAR(10) | resolved ALSDE code; nullable. |

Row filter: the view returns people with status ∈ (active, pending, disabled)
— `disabled` are kept so OneSync can disable, not orphan, the account
(`StatusActive` = 0 marks them).

```sql
SELECT ID, PSID, `Job Code Desc`, HomeSchoolID, TeacherNumber, EmployeeID,
       Email, username, Title, FirstName, LastName, StatusActive, Ethnicity
FROM v_onesync_source;
```

---

## 2. WRITE-BACK — minted username/email: table `onesync_writeback`

OneSync **inserts one row per provisioned user** after it mints the username.
This is a landing table; the app then applies it to the golden record and
**locks** the username (so it is never re-minted or renamed).

| Column        | OneSync sets | Type         | Notes |
|---------------|:------------:|--------------|-------|
| `id`          | no (auto)    | BIGINT PK    | |
| `person_uuid` | **yes**      | CHAR(36)     | = `uniqueId` |
| `username`    | **yes**      | VARCHAR(64)  | the minted sAMAccountName / login |
| `email`       | optional     | VARCHAR(160) | |
| `received_at` | no (default) | DATETIME     | leave to default |
| `applied`     | no           | TINYINT      | app sets to 1 when applied — **leave 0** |
| `applied_at`  | no           | DATETIME     | app sets |

```sql
INSERT INTO onesync_writeback (person_uuid, username, email)
VALUES (:uniqueId, :username, :email);
```

**Apply step (app side).** A scheduled job applies the rows OneSync wrote:

```sh
php bin/import_writeback.php --pending      # applies onesync_writeback rows where applied=0
```

It sets `person.username` / `email`, stamps `username_assigned_at`, sets
`username_locked = 1`, and audits it. Idempotent; it will **never overwrite a
locked username with a different value** (logged as a conflict).

> If OneSync prefers to emit a file instead, `bin/import_writeback.php
> --file=usernames.csv` ingests `uniqueId,username,email[,upn]` — same result.

---

## 3. (Retired) provisioning status: table `account_sync_status`

> **This direct-write path is retired.** OneSync no longer writes provisioning
> status back — IDM pulls it from OneSync's DB (`bin/import_onesync_db.php`)
> and performs the upserts below itself. Kept as a reference for what lands in
> the table.

OneSync provisions to **multiple destinations**, so there is **one row per
destination per person** — i.e. for a fully-synced user up to four rows (one
each for AD, Google, Raptor, PowerSchool), each with its own
action/status/message.

Unique key: **(`person_uuid`, `destination`)** — upsert on it.

**Canonical destinations.** Send these exact `destination` labels so the UI
groups them consistently (the person's Provisioning panel always shows all four,
marking any that haven't reported as *Not synced*):

| `destination`      | `dest_type`       |
|--------------------|-------------------|
| `Active Directory` | `ActiveDirectory` |
| `Google Workspace` | `GSuite`          |
| `Raptor`           | `CSV`             |
| `PowerSchool`      | `CSV`             |

(If you split AD into `Faculty AD` / `Staff AD`, those still map to the Active
Directory card automatically, and both rows are kept.)

| Column         | OneSync sets | Type          | Allowed / notes |
|----------------|:------------:|---------------|-----------------|
| `person_uuid`  | **yes**      | CHAR(36)      | = `uniqueId` |
| `destination`  | **yes**      | VARCHAR(80)   | e.g. `Faculty AD`, `Staff AD`, `Google Workspace`, `Raptor`, `PowerSchool` |
| `dest_type`    | optional     | VARCHAR(40)   | `ActiveDirectory` / `GSuite` / `CSV` … |
| `last_action`  | **yes**      | ENUM          | one of: `Add`, `Edit`, `Disable`, `Enable`, `NoChange`, `New` |
| `last_status`  | **yes**      | ENUM          | one of: `Success`, `Fail`, `Skipped`, `New` |
| `last_sync_at` | **yes**      | DATETIME      | when this sync ran |
| `message`      | on failure   | VARCHAR(1000) | the failure message (or info); truncate to 1000 |
| `person_id`    | no           | BIGINT        | **leave NULL** — a DB trigger resolves it from `person_uuid` |
| `updated_at`   | no (auto)    | DATETIME      | maintained by the DB |

> `last_action` / `last_status` are **ENUMs** — send exactly the values above.
> Map OneSync's raw verbs (e.g. `Failure`→`Fail`, `Succeeded`→`Success`) before
> writing. (Need different/looser values? Ask and we'll widen the column.)

```sql
-- one row per user per destination, upserted each run:
INSERT INTO account_sync_status
  (person_uuid, destination, dest_type, last_action, last_status, last_sync_at, message)
VALUES
  (:uniqueId, :destination, :destType, :action, :status, :syncedAt, :message)
ON DUPLICATE KEY UPDATE
  dest_type   = VALUES(dest_type),
  last_action = VALUES(last_action),
  last_status = VALUES(last_status),
  last_sync_at= VALUES(last_sync_at),
  message     = VALUES(message);
```

`person_id` is filled automatically by migration `0004`'s triggers, so the row
links to the golden record immediately.

**Freshness / staleness.** The dashboard's "OneSync DB sync" freshness comes
from the pull job's own run record (`service_run`), not from these rows. Each
person's destination card still shows a **stale** marker when its
`last_sync_at` (OneSync's export `endTime`, carried over by the pull) is older
than `SYNC_STALE_HOURS` (default 26h). Stale input feeds are flagged separately
via the import timestamps (`FEED_STALE_HOURS`).

---

## 4. (Retired) event history: table `account_sync_event`

Append-only per-event log (the current-status table above is the primary
surface). Like section 3, this is now populated by the DB pull, not by OneSync.

| Column        | OneSync sets | Type          |
|---------------|:------------:|---------------|
| `person_uuid` | **yes**      | CHAR(36)      |
| `destination` | **yes**      | VARCHAR(80)   |
| `action`      | optional     | VARCHAR(20)   |
| `status`      | optional     | VARCHAR(20)   |
| `message`     | optional     | VARCHAR(1000) |
| `occurred_at` | optional     | DATETIME      |

```sql
INSERT INTO account_sync_event (person_uuid, destination, action, status, message, occurred_at)
VALUES (:uniqueId, :destination, :action, :status, :message, :occurredAt);
```

The app prunes this to `ACCOUNT_SYNC_EVENT_CAP` rows. If OneSync writes events
directly, schedule a prune or keep it bounded on the OneSync side.

---

## 5. Least-privilege DB users

Two distinct accounts — never reuse the app or root account for OneSync.

```sql
-- Reader: SELECT on the source views only (staff + students passthrough).
CREATE USER 'onesync_ro'@'%' IDENTIFIED BY 'change-me';
GRANT SELECT ON tcs_identity.v_onesync_source         TO 'onesync_ro'@'%';
GRANT SELECT ON tcs_identity.v_onesync_student_source TO 'onesync_ro'@'%';

-- Writer: only the username landing table. No access to base tables; the
-- person_id-resolving trigger runs as definer, so no SELECT on `person` needed.
-- (Status tables need no OneSync grant — the app's own pull writes them.)
CREATE USER 'onesync_writer'@'%' IDENTIFIED BY 'change-me';
GRANT INSERT ON tcs_identity.onesync_writeback TO 'onesync_writer'@'%';
FLUSH PRIVILEGES;
```

(The app's own `idm_writeback` role covers the status tables and the
`--pending` apply job; `onesync_writer` is the account you hand to OneSync.)

---

## 6. End-to-end flow

```
OneSync  --SELECT-->  v_onesync_source            (one row per person; username NULL = new)
OneSync  mints username
OneSync  --INSERT-->  onesync_writeback           (person_uuid, username, email)
app job  --apply -->  person.username (+lock)      php bin/import_writeback.php --pending
OneSync  provisions AD / Google / Raptor / PowerSchool
app job  --SELECT-->  OneSync's own DB (os_users / os_export_log, read-only)
app job  --UPSERT-->  account_sync_status          php bin/import_onesync_db.php (nightly)
                          |
                          v  (trigger resolves person_id)
                      person detail "Provisioning status" + dashboard failed-sync rollup
```
