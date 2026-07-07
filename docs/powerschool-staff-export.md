# PowerSchool staff export (CSV → SFTP)

`bin/export_powerschool.php` generates CSVs for the PowerSchool SIS staff
import and uploads them to the district SFTP server. Two files per run:

- **`ps_new_staff_*.csv`** — people the IDM knows about (hired in NextGen,
  ALSDE ID entered) who do **not** exist in PowerSchool yet. Closes the manual
  "People > Staff > New Staff Member" copy/paste loop from the Identity
  Management runbook.
- **`ps_name_updates_*.csv`** — people **already in PowerSchool** whose name
  changed on the golden record (e.g. a marriage-related last-name change from
  NextGen), keyed by `Users.TeacherNumber` so PowerSchool updates the existing
  record instead of creating a new one.

## Who is exported (new staff)

A person is a candidate when **all** of these hold:

- `person.status` is `active` or `pending`;
- `person.alsde_id` is set (the ALSDE ID from the
  [ALSDE Certification Search](https://tcert.alsde.edu/Portal/Public/Pages/SearchCerts.aspx));
- there is **no** active `person_source_id` row with `system='powerschool'` —
  i.e. the nightly PowerSchool import has never reported this person back.

People who match everything except the ALSDE ID are **held back**, and the CLI
lists them explicitly (`! Lastname, Firstname … no ALSDE ID, not exported`) so
nobody is silently dropped. Once the record appears in PowerSchool and the
nightly import attaches the `powerschool` source id, the person drops out of
this export automatically.

## Who is exported (name updates)

A person lands in the update file when **all** of these hold:

- `person.status` is `active` or `pending`;
- they have an **active** `powerschool` source id (they exist in PowerSchool);
- their golden-record first or last name differs (case-insensitively) from the
  **latest** PowerSchool import snapshot (`staging_record` row matched to
  them) — i.e. the IDM/NextGen name changed and PowerSchool still has the old
  one.

The row carries the full current name (first, middle, last). Once PowerSchool
is updated and the nightly import snapshots the new name, the person drops out
of this file automatically. Changed people with **no employee id** have no
match key and are held back — the CLI lists them (`! … no employee id, name
change not exported`). People PowerSchool has never snapshotted are skipped
(there is nothing to compare against).

## Columns

Headers use the exact `Table.Field` names from the district's PowerSchool data
dictionary (pulled from `/ws/schema/table/{name}/metadata` on
tuscaloosacs.powerschool.com), so the files map 1:1 in PowerSchool's Data
Import Manager.

### `ps_new_staff_*.csv`

| CSV column | Golden-record source | Notes |
|---|---|---|
| `Users.Last_Name` | `person.last_name` | |
| `Users.First_Name` | `person.first_name` | |
| `Users.Middle_Name` | `person.middle_name` | |
| `Users.Email_Addr` | `person.email` | district e-mail (blank until OneSync mints it) |
| `Users.TeacherNumber` | `person.employee_id` | the NextGen employee id |
| `Users.SIF_StatePrid` | `person.employee_id` | district practice: StatePrId = Employee ID |
| `Users.Title` | primary `assignment.title` | |
| `Users.HomeSchoolId` | `school.ps_school_id` of the primary school | |
| `Users.TeacherLoginID` | `person.username` | AD username for PowerTeacher SSO (blank until minted) |
| `UsersCoreFields.gender` | `person.gender` | initial only — PowerSchool stores `M`/`F` |
| `UsersCoreFields.dob` | `person.dob` | `MM/DD/YYYY` |
| `S_USR_X.State_StaffNumber` | `person.alsde_id` | **the ALSDE ID** |
| `S_USR_X.HireDate` | `person.hire_date` | `MM/DD/YYYY` |
| `SchoolStaff.SchoolID` | `school.ps_school_id` | school association |
| `SchoolStaff.Status` | constant `1` | 1 = Current |
| `SchoolStaff.StaffStatus` | `person.person_type` | faculty → `1` (Teacher), everything else → `2` (Staff) |
| `TeacherRace.RaceCd` | `person.ethnicity_code` | resolved ALSDE race code (`ethnicity_map`) |

### `ps_name_updates_*.csv`

| CSV column | Golden-record source | Notes |
|---|---|---|
| `Users.TeacherNumber` | `person.employee_id` | **match key** — updates the existing record |
| `Users.First_Name` | `person.first_name` | |
| `Users.Middle_Name` | `person.middle_name` | |
| `Users.Last_Name` | `person.last_name` | |

Format (both files): comma-delimited, RFC-4180 quoting, CRLF line endings,
header row first.

## Running it

```
php bin/export_powerschool.php                # write CSVs + upload to SFTP
php bin/export_powerschool.php --dry-run      # list who would be exported / held back
php bin/export_powerschool.php --no-upload    # write the CSVs locally, skip SFTP
php bin/export_powerschool.php --new-only     # only the new-staff file
php bin/export_powerschool.php --updates-only # only the name-update file
php bin/export_powerschool.php --out=DIR      # override EXPORT_POWERSCHOOL_DIR
```

Exit code is non-zero when the export directory / SFTP drop dir is missing or
the upload fails, so it is cron-safe.

## Configuration (.env)

| Key | Meaning |
|---|---|
| `EXPORT_POWERSCHOOL_DIR` | local directory where the timestamped CSVs are written (`ps_new_staff_YYYYMMDD_HHMMSS.csv`, `ps_name_updates_YYYYMMDD_HHMMSS.csv`) |
| `SFTP_PS_EXPORT_DIR` | remote drop directory on the district SFTP server |
| `SFTP_HOST` / `SFTP_PORT` / `SFTP_USER` / key or password / `SFTP_FINGERPRINT` | shared with the feed pull (`bin/fetch_feeds.php`) — same server, same credentials |

## Importing on the PowerSchool side

In PowerSchool SIS use **Data Import Manager** with the staff/users import.
Match the columns by the `Table.Field` header names above; the `SchoolStaff.*`
constants create the school association as *Current*, and
`S_USR_X.State_StaffNumber` fills the Demographics "ALSDE ID" field. Race
(`TeacherRace.RaceCd`) and access/roles remain the district's existing manual
steps if the import template doesn't cover them.

For the name-update file, set the import to **update existing records** matched
on `Users.TeacherNumber` (never insert) — the file only ever contains people
who already exist in PowerSchool.
