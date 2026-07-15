# PowerSchool staff export (tab-delimited → SFTP)

`bin/export_powerschool.php` exports staff changes — **new users** (not in
PowerSchool yet) and **changed users** (name or username/email moved since the
last PowerSchool import snapshot) — as two tab-delimited files that load
cleanly into PowerSchool SIS, and uploads them to the district SFTP server.
Files are always written under the **same fixed names** (each run overwrites
the previous file — an empty run writes header-only files so yesterday's
changes never get re-imported — so the PowerSchool scheduled imports can point
at a constant file name):

- **`ps_staff_demographics.txt`** — imported via **Data Import Manager**,
  target module `USERSCOREFIELDS` (writes the `Users` table + AL extensions).
  One row per exported person, matched on `USERS.TeacherNumber`
  (= Employee ID) so PowerSchool updates the existing record.
- **`ps_staff_assignments.txt`** — imported via **AutoComm / Quick Import**
  into the **Teachers** view (writes the `SchoolStaff` school-assignment
  fields). One row per exported person **per school assignment** (multi-school
  staff repeat), sorted by `SchoolID` so a split-by-school is trivial.

Also written locally on every run (never uploaded):

- `ps_staff_demographics_sample.txt` / `ps_staff_assignments_sample.txt` —
  header + first 3 rows of each file, for a **manual test import** before the
  full file is used.
- `ps_staff_exceptions.txt` — every rejected row, truncation, and unmapped
  person type from the run (empty file when the run was clean).

Individual race codes (`TeacherRace.RaceCd`) are **not importable** in this
PowerSchool build and are deliberately not exported; only spec-listed columns
are emitted. `Users.Ethnicity` is deprecated and never populated. Passwords
and SSNs are never exported.

## File format (both files)

Tab-delimited, UTF-8 without BOM, CRLF line endings, header row first, one
trailing newline, no blank rows, no quoting. Tabs and newlines inside source
values are replaced with spaces. Unknown values are empty strings — never the
literal `NULL`.

## Who is exported

Only people who need a PowerSchool update — **not** the full roster. A person
with `person.status` of `active` or `pending` is exported when they are:

- **New** — no active `person_source_id` row with `system='powerschool'`,
  i.e. the nightly PowerSchool import has never reported them back. New users
  without an ALSDE ID are **held back** and logged (district practice:
  PowerSchool staff demographics require it); once the ID is entered they
  export on the next run.
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
never silently dropped. Both files cover the same people: the assignments
file carries only the exported (new + changed) users' school assignments.

## `ps_staff_demographics.txt` (DIM → USERSCOREFIELDS)

| Column header | IDM source | Notes |
|---|---|---|
| `USERS.TeacherNumber` | `person.employee_id` | **match key**; required, unique, ≤ 20 chars (an over-long key rejects the row — the match key is never truncated) |
| `USERS.Last_Name` | `person.last_name` | required, ≤ 100 chars |
| `USERS.First_Name` | `person.first_name` | required, ≤ 100 chars |
| `USERS.Middle_Name` | `person.middle_name` | ≤ 100 chars |
| `USERS.Email_Addr` | `person.email` | ≤ 50 chars |
| `USERS.SIF_StatePrid` | `person.employee_id` | district practice: state personnel id = Employee ID; ≤ 32 chars |
| `USERS.Title` | primary `assignment.title` | ≤ 40 chars |
| `USERS.HomeSchoolId` | `school.ps_school_id` of the primary school | the PowerSchool `School_Number`, zero-padded to 4 digits (`130` → `0130`); unresolvable → row rejected |
| `USERS.TeacherLoginID` | `person.username` | PowerTeacher login; ≤ 20 chars |
| `S_USR_X.hiredate` | `person.hire_date` | `MM/DD/YYYY` |
| `S_USR_X.state_staffnumber` | `person.alsde_id` | the ALSDE ID |

Spec columns with **no IDM source** are omitted entirely — header and values —
so a DIM update never mass-blanks them: `USERS.FedEthnicity`,
`USERS.FedRaceDecline`, `S_USR_X.employmentstatus`. If IDM ever gains a
Hispanic/Latino indicator, race-decline flag, or employment status, add the
columns back per the spec (FedEthnicity: `1`/`0`/`-1`; FedRaceDecline:
`1`/`0`).

## `ps_staff_assignments.txt` (AutoComm/Quick Import → Teachers)

Headers have **no table prefix** — the Teachers import rejects `Table.Field`
names.

| Column header | IDM source | Notes |
|---|---|---|
| `TeacherNumber` | `person.employee_id` | match key; same value as file 1 |
| `SchoolID` | `school.ps_school_id` of the assignment | `School_Number`, zero-padded to 4 digits; unresolvable → row rejected |
| `Status` | `assignment.end_date` | `1` = Current; `2` = No longer here (assignment end date has passed — clears the old school after a transfer) |
| `StaffStatus` | `person.person_type` | `faculty` → `1` (Teacher), `staff` → `2` (Staff), `sub` → `4` (Substitute); anything else defaults to `2` and is logged once per type |

People with **no** `assignment` rows fall back to one row for their primary
school. Duplicate (TeacherNumber, SchoolID) pairs collapse to a single row,
preferring Current over ended.

## Validation

- Rows missing `TeacherNumber`, `Last_Name`, or `First_Name` are rejected and
  logged.
- Spec max lengths are enforced: over-long values are truncated **and
  logged** (never silently); an over-long `TeacherNumber` rejects the whole
  row instead, since truncating the match key could update the wrong record.
- `TeacherNumber` uniqueness is enforced within the demographics file —
  duplicates after the first are rejected and logged.
- Every `HomeSchoolId` / `SchoolID` must resolve to a PowerSchool
  `School_Number` via `school.ps_school_id`; unresolvable rows are rejected
  and logged.
- The CLI prints a run summary: rows per file, exceptions, distinct schools —
  and writes every exception line to `ps_staff_exceptions.txt`.

## Running it

```
php bin/export_powerschool.php                     # write files + upload to SFTP
php bin/export_powerschool.php --dry-run           # print summary + exceptions only
php bin/export_powerschool.php --no-upload         # write the files locally, skip SFTP
php bin/export_powerschool.php --demographics-only # only ps_staff_demographics.txt
php bin/export_powerschool.php --assignments-only  # only ps_staff_assignments.txt
php bin/export_powerschool.php --out=DIR           # override EXPORT_POWERSCHOOL_DIR
```

Exit code is non-zero when the export directory / SFTP drop dir is missing or
the upload fails, so it is cron-safe. Validation exceptions do **not** fail
the run — they are reported in the summary and the exceptions file.

## Configuration (.env)

| Key | Meaning |
|---|---|
| `EXPORT_POWERSCHOOL_DIR` | local directory for the export files (fixed names, each run overwrites the last) |
| `SFTP_PS_EXPORT_DIR` | remote drop directory on the district SFTP server (only the two `.txt` import files are uploaded — samples and exceptions stay local) |
| `SFTP_HOST` / `SFTP_PORT` / `SFTP_USER` / key or password / `SFTP_FINGERPRINT` | shared with the feed pull (`bin/fetch_feeds.php`) — same server, same credentials |

## Importing on the PowerSchool side

1. **Demographics** — Data Import Manager, target `USERSCOREFIELDS`, match on
   `USERS.TeacherNumber`, update existing records. The header names map 1:1.
   Run the 3-row `ps_staff_demographics_sample.txt` through DIM first and
   verify the mapped fields before importing the full file.
2. **Assignments** — AutoComm or Quick Import into the **Teachers** view.
   Because the Teachers import runs per school context, the file is sorted by
   `SchoolID`; split it by school if the import requires it. Test with
   `ps_staff_assignments_sample.txt` first.

On the first production run, verify the exported values against the district
code lists (StaffStatus codes, School_Numbers) before scheduling it.
