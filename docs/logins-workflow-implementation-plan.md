# Implementation plan — Logins export & orientation-notification generation

Companion to [`logins-workflow-replacement.md`](logins-workflow-replacement.md),
which established the *what/why*. This is the *how*: the concrete, file-level plan
to replace both manual workflows inside the existing IDM (PHP 8.2, front
controller + PDO + server-rendered templates, no framework).

Two deliverables, built in order because the second consumes the first:

- **Workflow A → the Logins export.** A golden-record report in the Logins layout,
  on screen and as a CSV download, plus the three missing pieces (Board Approval,
  from/to transfer context, ALSDE-for-new-hires).
- **Workflow B → orientation-notification generation.** Per-person New Teacher /
  Non-Instructional Technology Orientation Checklist PDFs generated from the golden
  record, gated on a minted username.

Everything follows the conventions already in the tree: additive numbered
migration, whitelisted writer columns, `$guard('view'|'edit')` routes, CSRF on
every POST, audit + lifecycle on every write, PHPUnit coverage.

---

## Phase 0 — data model (one migration)

New migration `db/migrations/0011_logins_workflow.sql` (additive; MySQL
auto-commits DDL, so it must be independently safe — same rule as the others):

```sql
ALTER TABLE person
  ADD COLUMN board_approval_date DATE         NULL AFTER end_date,
  ADD COLUMN board_approval_note VARCHAR(120) NULL AFTER board_approval_date;
```

- `board_approval_date` is the field the Logins column actually wants; the note
  captures free text (agenda item, "pending", etc.). Both nullable — old rows and
  feed imports simply leave them blank.
- **From/To transfer context needs no new columns.** The `assignment` table already
  keeps one row per school with `effective_date`/`end_date`, and every change is in
  `lifecycle_event`. "From School / From Position" is derived (Phase 2), not stored.
- **ALSDE ID needs no new column** — `person.alsde_id` exists. What's missing is a
  *source* for people not yet in PowerSchool; that's Phase 4 (optional), and the
  `person_source_id.system` enum already reserves `alsde`.

Update `docs/schema.sql` to match (it's the canonical mirror of `0001` + notes).

---

## Phase 1 — make Board Approval editable

Wire the new columns through the existing edit path. Four touch points, all
mirroring how `notes`/`alsde_id` already flow:

1. **`src/Import/PersonWriter.php`** → add `board_approval_date`,
   `board_approval_note` to the `profileSnapshot()` SELECT (line ~630). That alone
   makes them writable by `updateProfile()`, which only writes columns present in
   the snapshot — no other writer change needed.
2. **`src/Controller/PersonController.php::update()`** → add the two fields to the
   `$fields` array (line ~222), with a `board_approval_date` format check reusing
   the existing `^\d{4}-\d{2}-\d{2}$` DOB validation.
3. **`src/Controller/PersonController.php::editForm()`** → add both to the
   `$values` seed array (line ~171) so the form round-trips them.
4. **`templates/people/edit.php`** → two inputs (date + short text) in a new
   "Board approval" block. **`templates/people/show.php`** → surface the value in
   the Position group so it's visible without editing.

RBAC/CSRF/audit come for free — `update()` already runs under `$guard('edit')`,
checks the CSRF token, and `updateProfile()` writes `audit_log` +
`lifecycle_event`.

**Tests:** extend `tests/Unit/PersonWriterDiffTest.php` (or a new
`PersonWriterBoardApprovalTest`) — set the fields, assert persistence + one audit
row; assert a bad date is rejected in the controller path.

---

## Phase 2 — the Logins export (screen + CSV)

The core replacement for the NextGen→spreadsheet copy.

### 2a. Query — `src/Service/LoginsReportService.php` (new)

One method, `rows(array $filters): array`, following `PersonService::list()`'s
shape (bound params, join to `school`). Default scope = the onboarding population:
`status IN ('pending','active')` with a recent `hire_date`/`position_start_date`,
filterable by a date window and school. For each person also resolve the **prior
primary assignment** for from/to context:

- Current (To): `person.primary_school_id` → school name, current primary
  `assignment.title` + `person.position_number`.
- Prior (From): the most recent **non-current** `assignment` row for that person
  (by `effective_date`), or blank for a brand-new hire. This is the one non-trivial
  query — a correlated lookup per person, or a window function
  (`ROW_NUMBER() OVER (PARTITION BY person_id ORDER BY effective_date DESC)`);
  MariaDB 10.11 supports window functions, so prefer that in a single pass.

Columns returned, in Logins order: last name, first name + MI (`first_name` +
initial of `middle_name`), From School, From Position, To Position, To School,
effective date, end date, board approval, employee id, DOB, gender, race
(`ethnicity_source`), ALSDE ID.

### 2b. Controller — `src/Controller/LoginsController.php` (new)

- `index()` → render the table (reuses the report service + filter form).
- `csv()` → stream the same rows as `text/csv` via the existing
  `src/Import/Csv.php` writer, `Content-Disposition: attachment`. No new CSV code.

Both are **read-only**, so both go under `$guard('view')`. Because the export
carries PII (DOB, home data is *not* in this set, but DOB is), consider gating
`csv()` at `$guard('edit')` — decide with the data owner; the report page itself
is fine at `view`.

### 2c. Routes — `public/index.php`

```php
$router->get('/logins',     $guard('view', static fn() => $logins->index()));
$router->get('/logins.csv', $guard('view', static fn() => $logins->csv()));
```

Add a nav entry in `templates/layout.php` next to People/Review/Import.

### 2d. View — `templates/logins/index.php` (new)

Filter bar (date window + school, matching `people/index.php`'s pattern), the
table, and a "Download CSV" button pointing at `/logins.csv` with the same query
string. Flag blank Board Approval / ALSDE ID cells visually so the operator sees
what still needs a human touch.

**Tests:** `tests/Unit/LoginsReportServiceTest.php` against an in-memory/seeded
dataset — assert the from/to derivation (transfer vs. new hire), the MI
formatting, the date-window filter, and that a person with no prior assignment
yields blank From columns.

**Outcome:** the NextGen→spreadsheet re-keying is gone. 11 of 14 columns are
automatic; Board Approval is entered in-app (Phase 1); ALSDE ID is present for
anyone already in PowerSchool (Phase 4 closes the new-hire gap).

---

## Phase 3 — orientation-notification generation (Workflow B)

Depends on Phase 2's report service (same rows, plus the minted account).

### 3a. Gating

Only generate for a person whose username is minted and locked
(`person.username_locked = 1`) — the checklist's whole point is to hand someone
their account. Surface a per-person "Generate orientation checklist" action on the
person page and a bulk action on `/logins`, both `$guard('edit')`.

### 3b. Template → PDF

Two HTML templates mirroring the current `.docx` files —
`templates/notify/new_teacher.php` and `templates/notify/non_instructional.php`
(pick by `person_type`) — populated from the golden record: name, school,
position, **username / email / UPN**, and the standing orientation content
(links, instructions). Because these are real HTML anchors rendered to PDF, the
embedded-hyperlink breakage from Word's *Finish & Merge* disappears by
construction — no Acrobat plugin dependency.

Rendering options, cheapest first:
- **Print-to-PDF stylesheet** — serve the filled HTML at a `/notify/{id}` route
  with a print CSS; the operator saves as PDF. Zero new dependencies; good first
  cut.
- **Server-side PDF** — add a small HTML→PDF library (e.g. Dompdf) via Composer
  and stream `application/pdf` directly. Preferred end state for a bulk "generate
  all" run. Note the strict CSP in `Security::sendHeaders()` — PDF generation is
  server-side so it's unaffected, but any print-preview HTML must keep assets
  self-hosted (the app already avoids inline JS/CDNs).

### 3c. Interim fallback (no rebuild)

If generating in-app is deferred, Workflow B still improves immediately by
**repointing the existing Word merge at `/logins.csv`** instead of the
hand-maintained workbook — same document, authoritative data source, no OneDrive
sync step. Document this in the user guide as the stopgap.

**Tests:** a render test asserting the correct template is chosen by
`person_type` and that account fields appear only when `username_locked = 1`.

---

## Phase 4 — ALSDE ID for pre-PowerSchool hires (optional)

Closes the last manual step (the certification-site lookup) for brand-new hires
who have no PowerSchool record yet. Two options, in preference order:

1. **Accept PowerSchool backfill (no code).** `alsde_id` populates automatically on
   the person's first PowerSchool import. If the checklist doesn't need ALSDE ID on
   day one, document that it fills in within a sync cycle and stop here.
2. **Manual entry now, auto-reconcile later.** `alsde_id` is already editable on the
   edit form; an operator can key the value from the certification site once, and
   the PowerSchool import later confirms/overrides it. Record provenance by
   attaching a `person_source_id` row with `system='alsde'` (enum already exists).
   A scripted lookup against `tcert.alsde.edu` is explicitly **out of scope** —
   it's public but scraping is brittle and a policy question for the data owner.

---

## Sequencing & rollout

| Phase | Deliverable | Depends on | Risk |
|---|---|---|---|
| 0 | Migration `0011` | — | low (additive) |
| 1 | Board Approval editable | 0 | low |
| 2 | Logins export (screen + CSV) | 0,1 | med (from/to query) |
| 3 | Orientation PDFs | 2 | med (PDF lib / CSP) |
| 4 | ALSDE new-hire story | — | low (mostly policy) |

Phases 0–2 deliver the bulk of the manual-effort savings and are the recommended
first PR. Phase 3 can ship its Word-repoint fallback (3c) in the same PR and the
in-app generator (3a/3b) in a follow-up. Phase 4 is a small, mostly-policy
increment. Each phase is independently shippable, RBAC-gated, and audited — no
change to the OneSync contract or the existing feed pipeline.
