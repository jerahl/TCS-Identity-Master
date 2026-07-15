# PowerSchool staff export (AutoComm → Teachers view)

`bin/export_powerschool.php` exports staff changes — **new users** (not in
PowerSchool yet) and **changed users** (name or username/email moved since the
last PowerSchool import snapshot) — as **one tab-delimited file** for
PowerSchool's **AutoComm** import into the **Teachers** view, and uploads it
to the district SFTP server.

AutoComm/Teachers is the **only import path exposed** in the district's
PowerSchool build — there is no Data Import Manager access, so no
`USERSCOREFIELDS` / `S_USR_X` extension fields (hire date and the ALSDE ID
cannot be imported; the ALSDE ID is enforced as a *creation gate* instead,
see below). Column names are the exact Teachers-view field names from the
district data dictionary, with no `Table.Field` prefixes (the Teachers import
rejects them).

Files are always written under the **same fixed names** (each run overwrites
the previous file — an empty run writes a header-only file so yesterday's
changes never get re-imported — so AutoComm can point at a constant name):

- **`ps_staff_teachers.txt`** — the import file (uploaded to SFTP). One row
  per exported person **per school assignment**; the Users-sourced fields
  repeat on every row, the SchoolStaff-sourced fields (`SCHOOLID`, `STATUS`,
  `STAFFSTATUS`) vary per school. Sorted by `SCHOOLID` — the Teachers import
  runs per school context, so split-by-school is trivial if needed.
- **`ps_staff_teachers_sample.txt`** — header + first 3 rows, for a **manual
  test import** before the full file is used (local only).
- **`ps_staff_exceptions.txt`** — every held-back/rejected row, truncation,
  and unmapped person type from the run (local only; empty when clean).

## File format

Tab-delimited, UTF-8 without BOM, CRLF line endings, header row first, one
trailing newline, no blank rows, no quoting. Tabs and newlines inside source
values are replaced with spaces. Unknown values are empty strings — never the
literal `NULL`.

## Who is exported

Only people who need a PowerSchool update — **not** the full roster. A person
with `person.status` of `active` or `pending` is exported when they are:

- **New** — no active `person_source_id` row with `system='powerschool'`,
  i.e. the nightly PowerSchool import has never reported them back.
  **New users must have an ALSDE ID** on the golden record to be created in
  PowerSchool; without one they are **held back** and logged. (The Teachers
  view has no ALSDE column — the ID is entered in PowerSchool demographics by
  hand — so IDM enforces the requirement as a gate.) Once the ID is entered
  they export on the next run.
- **Changed** — already in PowerSchool, but the golden-record first/last name
  **or** district email differs from the **latest** PowerSchool import
  snapshot (the newest matched `staging_record` row). A username rename
  always moves the email with it, so the email comparison is how a
  username change is detected — the PowerSchool snapshot doesn't carry the
  login id. The email comparison only fires when the golden email is set and
  the snapshot recorded a PowerSchool email (`raw_json` → `fields.hr_email`);
  snapshots that predate email capture never trigger a false update.

People PowerSchool has never snapshotted are skipped (nothing to compare
against). Once PowerSchool is updated and the nightly import snapshots the
new values, changed people drop out of the export automatically; new people
drop out when the import attaches their `powerschool` source id. Rows that
fail validation (below) are **rejected and logged** to the exceptions file —
never silently dropped.

## Columns

| Column | Source table | IDM source | Notes |
|---|---|---|---|
| `TEACHERNUMBER` | Users | `person.employee_id` | **match key**; required, unique, ≤ 20 chars (an over-long key rejects the row — the match key is never truncated) |
| `LAST_NAME` | Users | `person.last_name` | required, ≤ 100 chars |
| `FIRST_NAME` | Users | `person.first_name` | required, ≤ 100 chars |
| `MIDDLE_NAME` | Users | `person.middle_name` | ≤ 100 chars |
| `EMAIL_ADDR` | Users | `person.email` | ≤ 50 chars |
| `SIF_STATEPRID` | Users | `person.employee_id` | district practice: state personnel id = Employee ID; ≤ 32 chars |
| `TITLE` | Users | primary `assignment.title` | ≤ 40 chars, display/sort only |
| `HOMESCHOOLID` | Users | `school.ps_school_id` of the primary school | the PowerSchool `School_Number`, zero-padded to 4 digits (`130` → `0130`); unresolvable → row rejected |
| `TEACHERLOGINID` | Users | `person.username` | PowerTeacher login; ≤ 20 chars |
| `SCHOOLID` | SchoolStaff | `school.ps_school_id` of the assignment | `School_Number`, zero-padded to 4 digits; unresolvable → row rejected |
| `STATUS` | SchoolStaff | `assignment.end_date` | `1` = Current; `2` = No longer here (assignment end date has passed — clears the old school after a transfer) |
| `STAFFSTATUS` | SchoolStaff | `person.person_type` | `faculty` → `1` (Teacher), `staff` → `2` (Staff), `sub` → `4` (Substitute); anything else defaults to `2` and is logged once per type |

People with **no** `assignment` rows fall back to one row for their primary
school. Duplicate (TEACHERNUMBER, SCHOOLID) pairs collapse to a single row,
preferring Current over ended.

Never exported (even though the Teachers view has fields for them):
`PASSWORD` / `TEACHERLOGINPW`, `SSN`, address/phone fields, the deprecated
`ETHNICITY`, and `FEDETHNICITY` / `FEDRACEDECLINE` (no IDM source — omitting
the columns entirely means an update never mass-blanks them).

## Validation

- New users without an ALSDE ID are held back and logged.
- Rows missing `TEACHERNUMBER`, `LAST_NAME`, or `FIRST_NAME` are rejected and
  logged.
- Dictionary max lengths are enforced: over-long values are truncated **and
  logged** (never silently); an over-long `TEACHERNUMBER` rejects the whole
  row instead, since truncating the match key could update the wrong record.
- `TEACHERNUMBER` uniqueness is enforced across people — duplicates after the
  first are rejected and logged.
- Every `HOMESCHOOLID` / `SCHOOLID` must resolve to a PowerSchool
  `School_Number` via `school.ps_school_id`; unresolvable rows are rejected
  and logged.
- The CLI prints a run summary (new/changed counts, rows, distinct schools,
  exceptions) and writes every exception line to `ps_staff_exceptions.txt`.

## Running it

```
php bin/export_powerschool.php             # write files + upload to SFTP
php bin/export_powerschool.php --dry-run   # print summary + exceptions only
php bin/export_powerschool.php --no-upload # write the files locally, skip SFTP
php bin/export_powerschool.php --out=DIR   # override EXPORT_POWERSCHOOL_DIR
```

Exit code is non-zero when the export directory / SFTP drop dir is missing or
the upload fails, so it is cron-safe. Validation exceptions do **not** fail
the run — they are reported in the summary and the exceptions file.

## Configuration (.env)

| Key | Meaning |
|---|---|
| `EXPORT_POWERSCHOOL_DIR` | local directory for the export files (fixed names, each run overwrites the last) |
| `SFTP_PS_EXPORT_DIR` | remote drop directory on the district SFTP server (only `ps_staff_teachers.txt` is uploaded — the sample and exceptions stay local) |
| `SFTP_HOST` / `SFTP_PORT` / `SFTP_USER` / key or password / `SFTP_FINGERPRINT` | shared with the feed pull (`bin/fetch_feeds.php`) — same server, same credentials |

## Importing on the PowerSchool side

Set up **AutoComm** (Start Page > System > AutoComm) against the **Teachers**
view, pointed at `ps_staff_teachers.txt` in the SFTP drop directory:

- Field delimiter: **Tab**; end-of-line: **CRLF**; first row contains field
  names matching the Teachers-view columns above.
- Match on `TEACHERNUMBER` so existing staff update instead of duplicating.
- Because the Teachers import runs per school context, the file is sorted by
  `SCHOOLID`; split it by school if the AutoComm setup requires it.
- Run `ps_staff_teachers_sample.txt` through a manual Quick Import first and
  verify the mapped fields (School_Numbers, StaffStatus codes) against the
  district code lists before scheduling the full file.

The ALSDE ID and hire date cannot be imported through the Teachers view —
they remain manual entries in PowerSchool demographics. IDM holds new users
back until the ALSDE ID is on the golden record so the operator enters it at
creation time.
