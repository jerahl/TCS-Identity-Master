# OneSync ⇆ TCS Identity Master — direct DB integration mapping

How OneSync reads from and writes back to the Identity Master database. OneSync
**pulls** from one view and **writes back** username/email and per-user
provisioning status (success/failure + message) to three tables.

- DB: MySQL/MariaDB, database `tcs_identity` (adjust to your deployment).
- Stable key everywhere is **`person_uuid` (CHAR(36))**, surfaced to OneSync as
  **`uniqueId`**. Use it as the OneSync `uniqueId` and as the join key on write-back.

---

## 1. READ — source view: `v_onesync_source`  (read-only)

Connect as the read-only user (`onesync_ro`). One row **per person**; the only
object OneSync should read.

| OneSync field      | View column      | Type        | Notes |
|--------------------|------------------|-------------|-------|
| `uniqueId`         | `uniqueId`       | CHAR(36)    | = `person_uuid`. The stable identity key. |
| `First_Name`       | `First_Name`     | VARCHAR(80) | |
| `Last_Name`        | `Last_Name`      | VARCHAR(80) | |
| `PreferredName`    | `PreferredName`  | VARCHAR(80) | nullable |
| `Email_Addr`       | `Email_Addr`     | VARCHAR(160)| nullable until assigned |
| `TeacherLoginID`   | `TeacherLoginID` | VARCHAR(64) | = `username`. **NULL until minted** → fires OneSync's `BlankSAMAccountName` rule for new people. |
| `TeacherNumber`    | `TeacherNumber`  | VARCHAR(40) | = `employee_id`, nullable (subs/contractors/interns may lack one) |
| `School_ID`        | `School_ID`      | VARCHAR(20) | PowerSchool `SchoolID` of the **primary** assignment |
| `Ethnicity`        | `Ethnicity`      | VARCHAR(10) | resolved ALSDE code |
| `StatusActive`     | `StatusActive`   | 1 / 0       | 1 when status ∈ (active, pending) |
| `PersonType`       | `PersonType`     | VARCHAR     | faculty / staff / contractor / sub / intern / other |

Row filter: the view returns people with status ∈ (active, pending, disabled)
— `disabled` are kept so OneSync can disable, not orphan, the account.

```sql
SELECT uniqueId, First_Name, Last_Name, PreferredName, Email_Addr,
       TeacherLoginID, TeacherNumber, School_ID, Ethnicity, StatusActive, PersonType
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

## 3. WRITE-BACK — provisioning status (success/failure + message): table `account_sync_status`

OneSync provisions to **multiple destinations**, so it writes **one row per
destination per person** — i.e. for a fully-synced user OneSync upserts up to
four rows (one each for AD, Google, Raptor, PowerSchool), each with its own
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

**Freshness / staleness.** `last_sync_at` drives the staleness indicators:
the dashboard flags **"OneSync hasn't run"** (no rows) or a stale write-back, and
each person's destination card shows a **stale** marker when its `last_sync_at`
is older than `SYNC_STALE_HOURS` (default 26h). Always send a current
`last_sync_at` so the freshness reads correctly. Stale input feeds are flagged
separately via the import timestamps (`FEED_STALE_HOURS`).

---

## 4. (Optional) WRITE-BACK — event history: table `account_sync_event`

Append-only per-event log (the current-status table above is the primary
surface). Useful if OneSync wants to record every attempt, not just the latest.

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
-- Reader: SELECT on the single view only.
CREATE USER 'onesync_ro'@'%' IDENTIFIED BY 'change-me';
GRANT SELECT ON tcs_identity.v_onesync_source TO 'onesync_ro'@'%';

-- Writer: only the three write-back tables. No access to base tables; the
-- person_id-resolving trigger runs as definer, so no SELECT on `person` needed.
CREATE USER 'onesync_writer'@'%' IDENTIFIED BY 'change-me';
GRANT INSERT                 ON tcs_identity.onesync_writeback    TO 'onesync_writer'@'%';
GRANT INSERT, UPDATE         ON tcs_identity.account_sync_status  TO 'onesync_writer'@'%';
GRANT INSERT                 ON tcs_identity.account_sync_event   TO 'onesync_writer'@'%';
FLUSH PRIVILEGES;
```

(The app's own `idm_writeback` role already covers these tables for the
`--pending` apply job; `onesync_writer` is the account you hand to OneSync.)

---

## 6. End-to-end flow

```
OneSync  --SELECT-->  v_onesync_source            (one row per person; username NULL = new)
OneSync  mints username
OneSync  --INSERT-->  onesync_writeback           (person_uuid, username, email)
app job  --apply -->  person.username (+lock)      php bin/import_writeback.php --pending
OneSync  provisions AD / Google / Raptor / PowerSchool
OneSync  --UPSERT-->  account_sync_status         (per person+destination: action, status, message)
                          |
                          v  (trigger resolves person_id)
                      person detail "Provisioning status" + dashboard failed-sync rollup
```
