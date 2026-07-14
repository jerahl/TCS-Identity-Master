# PowerSchool staff AutoComm/DIM export (nightly, fixed-name → SFTP)

`bin/export_ps_staff.php` makes IDM the provisioning source for PowerSchool
staff records (replacing OneSync). It generates three **fixed-name** files
nightly and uploads them to the district SFTP server, where the PowerSchool
jobs pick them up by exact name:

| File | PS-side job | Purpose |
|---|---|---|
| `ps_staff_create.txt` | AutoComm (full sync) | Create new staff and refresh existing ones — matches on `TeacherNumber`, inserts when no match |
| `ps_staff_sso.txt` | AutoComm (update-only) | Align `LoginID` / `TeacherLoginID` / `Email_Addr` to AD for SSO — **must be configured to update existing records only, never insert** |
| `ps_staff_race.txt` | Data Import Manager (TeacherRace) | One row per person per declared race, into the `TeacherRace` child table |

> **The filenames are contractual.** The AutoComm/DIM jobs fetch each file by
> exact name (`PowerSchoolAutoCommExporter::FILE_*`). Renaming one here
> requires changing the PowerSchool-side job in lockstep — never rename
> casually.

**Operational sequence on the PowerSchool side:** the **create** file imports
first (records must exist), the **race** file second (child rows attach to the
records the create file made), and the **SSO** file only ever *matches*
existing records — it must never create.

## Who is exported

Same "active" definition as the existing DIM export
(`docs/powerschool-staff-export.md`): `person.status IN ('active','pending')`.
The SSO file is further limited to people with an **active
`person_source_id` row with `system='powerschool'`** — i.e. the nightly
PowerSchool import has reported them back, so they demonstrably exist in PS.
People not SSO-ready (no AD username or e-mail on the golden record yet) are
held back from the SSO file and listed in the run report — a blank value would
wipe the PS field.

Deactivation/offboarding is **out of scope**: `Status` is always `1`
(Current) and nothing here disables a PS record.

## AutoComm field lists (transcribe these into the PS AutoComm setup)

AutoComm field mapping is **positional** — the files carry **no header row**,
and the field list configured on the PowerSchool side must match this order
exactly.

`ps_staff_create.txt` (16 fields):

```
TeacherNumber
Last_Name
First_Name
HomeSchoolId
SchoolID
Title
S_USR_X.HireDate
Sched_Gender
StaffStatus
Status
SIF_StatePrid
FedEthnicity
FedRaceDecline
LoginID
TeacherLoginID
Email_Addr
```

`ps_staff_sso.txt` (4 fields):

```
TeacherNumber
LoginID
TeacherLoginID
Email_Addr
```

`ps_staff_race.txt` (DIM, **with** header row, data-dictionary names):

```
Users.TeacherNumber	TeacherRace.RaceCd
```

## Value mappings (golden-record source → PS value)

| Field | IDM source | Rule |
|---|---|---|
| `TeacherNumber` | `person.employee_id` | Verbatim — the AutoComm match key. A record without one is **skipped, logged, and fails the run** (exit non-zero). |
| `Last_Name` / `First_Name` | `person.last_name` / `first_name` | |
| `HomeSchoolId` / `SchoolID` | `school.ps_school_id` of `person.primary_school_id` | PowerSchool `School_Number`. No primary school, or a school without `ps_school_id`, is an **unmapped location — hard failure**. |
| `Title` | primary `assignment.title` | |
| `S_USR_X.HireDate` | `person.hire_date` | `MM/DD/YYYY`; empty when unknown (never a fake date). |
| `Sched_Gender` | `person.gender` | `M`/`F` only; anything else exports empty + warning. |
| `StaffStatus` | `person.person_type` | faculty→1 Teacher, staff/contractor/intern→2 Staff, sub→4 Substitute, other→0 Not Assigned. IDM has no role for 3 (Lunch Staff). |
| `Status` | constant | Always `1` (Current). |
| `SIF_StatePrid` | `person.alsde_id` | Alabama state staff ID as stored. |
| `FedEthnicity` | `person.ethnicity_code` | `1` when the resolved code is Hispanic/Latino (`4`), `0` when a non-Hispanic race is resolved, `-1` unknown. |
| `FedRaceDecline` | derived | `0` **only** when the person has ≥1 row in the race file (coupling rule, below); empty otherwise + data-quality warning. Never `1`: IDM has no "declined to answer" flag. |
| `LoginID` / `TeacherLoginID` | `person.username` | The AD sAMAccountName — both set to the same value. |
| `Email_Addr` | `person.email` | Canonical AD/Google address. |
| `TeacherRace.RaceCd` | `person.ethnicity_code` via `PS_RACE_MAP` | District race code from the PS `Gen` table (`cat='Race'`) — see below. |

**Coupling rule.** `FedRaceDecline = 0` claims "answered, races on file", so it
is only emitted when the same person has at least one row in
`ps_staff_race.txt`. Both datasets are computed in the same pass and the value
is derived from the race rows actually produced; anyone left empty is listed
under "FedRaceDecline left empty" in the run report.

### Race codes (`PS_RACE_MAP`)

`TeacherRace.RaceCd` must be the **district-defined** race code from the
PowerSchool `Gen` table (`cat = 'Race'`, District Setup → Races) — not a
hardcoded federal code. The map lives in
`PowerSchoolAutoCommExporter::PS_RACE_MAP`, keyed by IDM's resolved
`ethnicity_code` (the ALSDE code from `ethnicity_map`). **The shipped values
are placeholders that mirror the ALSDE codes and must be verified against the
district Gen table before the first live upload.** Any race value with no map
entry is a **hard failure** — the run exits non-zero and the affected modes
upload nothing; unmapped values are never dropped silently or passed through.

Known data-model gaps (IDM stores a single resolved race/ethnicity value per
person):

- a Hispanic/Latino person's separate race is unrepresentable — they get
  `FedEthnicity=1`, no race rows, and `FedRaceDecline` empty;
- "Two or More Races" (`7`) cannot be decomposed into individual races — it
  maps to one Gen code; if the district has no such code, remove the entry and
  handle those people in PS by hand;
- `FedRaceDecline=1` (declined) is never produced.

## Hard exclusions

**No password fields, ever** (`Password`, `TeacherLoginPW`): AD is the
authenticator, PS local passwords stay unmanaged. No SSNs, phone numbers, or
home addresses.

## File format

Tab-delimited (`PowerSchoolAutoCommExporter::DELIMITER` — a constant so it can
become `,` if the AutoComm setup ends up expecting CSV), `.txt`, UTF-8, CRLF
line endings. No header on the two AutoComm files; header row on the DIM race
file. Tabs/newlines inside values are replaced with spaces and every fix is
listed in the run report.

## Running it

```
php bin/export_ps_staff.php --mode=create|sso|race   # one file
php bin/export_ps_staff.php --all                    # all three files
php bin/export_ps_staff.php --all --upload           # nightly cron entry
php bin/export_ps_staff.php --all --dry-run          # report only, write nothing
php bin/export_ps_staff.php --all --out=DIR          # override EXPORT_POWERSCHOOL_DIR
```

Every run (dry or live) ends with a run report: rows per file, skipped records
and why, coupling-rule flags, warnings, sanitized values, hard failures; a dry
run adds the first 5 rows of each file. Exit codes: `0` clean, `1` any hard
failure (missing TeacherNumber, unmapped school/race, guard trip, upload
failure), `2` usage/config error — cron-safe.

Files land in `EXPORT_POWERSCHOOL_DIR` under their fixed names (written
atomically: temp file + rename), plus a timestamped audit copy in
`archive/` (`ps_staff_create_YYYYMMDD_HHMMSS.txt`, …) which is swept after
`PS_STAFF_ARCHIVE_DAYS` (default 30).

## Safety rails

- **Empty-file guard** — if a file's data-row count drops below
  `PS_STAFF_MIN_ROW_RATIO` (default `0.5`) of the previous run's, that mode is
  a hard failure: the local fixed file is not overwritten (the suspect output
  is still archived for diffing) and nothing uploads. A broken IDM query can
  never blank out staff data in PS.
- **Upload gating** — a mode that ended with a hard failure uploads
  **nothing**; yesterday's known-good file stays on the server. `create` and
  `race` are coupled (FedRaceDecline promises race rows), so a failure in
  either gates both. A clean SSO file still uploads.
- **Atomic upload** — each file goes up as `.name.txt.tmp` and is renamed to
  its fixed name only after the transfer completes, so AutoComm can never read
  a half-written file. After the rename the remote size is compared to the
  local size before the run reports success.
- **Failure visibility** — every live run is recorded in `service_run`
  (job `ps_staff_export`, shown on the admin Outputs page like the other
  nightly jobs) with counts and a one-line summary; failures also print to
  stderr and exit non-zero so cron mail / log monitoring picks them up.

## SFTP configuration (.env — never committed; template in `.env.example`)

Upload shells out to the system OpenSSH `sftp` client (the PHP stays
stdlib-only) with **key-based auth only** — no passwords on the command line,
`BatchMode=yes` so it can never prompt. Host keys are verified against the
system `known_hosts` (seed it once with `ssh-keyscan -p <port> <host>`), or a
dedicated file via `PS_STAFF_SFTP_KNOWN_HOSTS`.

| Key | Meaning |
|---|---|
| `SFTP_HOST` / `SFTP_PORT` / `SFTP_USER` / `SFTP_PRIVATE_KEY_FILE` | shared with the feed pull — same server, same key |
| `PS_STAFF_SFTP_DIR` | remote directory the AutoComm/DIM jobs read from (required for `--upload`) |
| `PS_STAFF_SFTP_KNOWN_HOSTS` | optional known_hosts override |
| `EXPORT_POWERSCHOOL_DIR` | local output dir (fixed names + `archive/`) |
| `PS_STAFF_ARCHIVE_DAYS` | archive retention, default 30 |
| `PS_STAFF_MIN_ROW_RATIO` | empty-file guard threshold, default 0.5 |

## Nightly schedule

```cron
# m h dom mon dow   command
30 3 * * *  cd /var/www/idm && /usr/bin/php bin/export_ps_staff.php --all --upload >> /var/log/idm/ps_staff_export.log 2>&1
```

Ordering matters, in this chain:

1. **02:30** — feed imports (`bin/fetch_feeds.php`, see
   `docs/cron-feed-pull.md`) refresh the golden record from NextGen/PowerSchool.
2. **03:30** — this export generates and uploads the three files (finishes in
   well under an hour).
3. **≥ 04:30** — the PowerSchool-side AutoComm/DIM jobs run, in the order
   create → race → SSO.

The PS-side jobs must be scheduled **at least an hour after** this cron entry
so a slow night can't hand AutoComm yesterday's data mid-swap; if the PS-side
times move earlier, move this entry earlier in lockstep.

## First-run checklist

1. Verify `PS_RACE_MAP` against the district Gen table (`cat='Race'`).
2. `php bin/export_ps_staff.php --all --dry-run` on production data; explain
   every warning and clear every hard failure.
3. Spot-check the generated files: a multi-school person (primary school in
   both school columns), a "Two or More Races" person, someone with no hire
   date (empty field, not a fake date).
4. Prove the upload against the real SFTP server: atomic rename, size check,
   and the gate (force a failure — e.g. temporarily blank a school's
   `ps_school_id` — and confirm nothing uploads and yesterday's files
   survive).
5. Let one supervised cron-triggered run complete cleanly before leaving it
   unattended.
