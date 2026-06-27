# Prompt for Claude Design — TCS Identity Master dashboard

Paste everything below the line into Claude Design. It's self-contained.

---

Design a clean, modern **admin dashboard UI** for an internal IT tool called the
**TCS Identity Master** — the system that manages staff/faculty identity for a K-12
school district (Tuscaloosa City Schools). Build a **high-fidelity, interactive
prototype in React + Tailwind CSS**, single-file friendly, with realistic mock data.
It will be reimplemented on a **PHP + MySQL** backend, so keep layouts implementable
with normal server-rendered pages (no exotic client-only patterns) — but the prototype
itself can be React for fidelity.

## What this tool is

It's the single source of truth for staff identity. Each real person has **one
"golden record."** Data flows in from an HR system (NextGen), the student information
system (PowerSchool), and manual entry; the tool de-duplicates people into one record
each, then feeds a downstream sync engine (OneSync) that provisions Active Directory,
Google Workspace, and other systems. The core problem it solves: the same human used
to get duplicate accounts because each source created its own record. This tool keeps
**one record per person** with a crosswalk of all their system IDs.

## Who uses it

District IT / network administrators (technical, data-dense is fine). Not end users.
The data is sensitive (staff PII), so the design should feel like a trustworthy,
auditable internal admin system — not a consumer app.

## Visual direction

- Clean, professional, light theme. Generous use of data tables, but readable.
- A persistent left sidebar nav + top bar (search, signed-in user, environment badge).
- Status as color-coded badges: **Active** (green), **Pending** (amber), **Disabled**
  (gray), **Terminated** (red).
- Calm primary color (blue/teal). No marketing flourish. Accessible contrast, keyboard
  friendly. Mobile-responsive but optimized for desktop.

## Data model the UI must reflect

- **Person (golden record):** person_uuid, type (faculty/staff/contractor/sub/intern),
  status (pending/active/disabled/terminated), first/middle/last/preferred name, DOB,
  gender, ethnicity (raw value + resolved ALSDE code), ALSDE ID, employee_id,
  primary school, hire date, end date, **username** and **email** (assigned by the sync
  engine — read-only here, with a "locked" indicator once set), created/updated, notes.
- **Source IDs (crosswalk):** a list of {system, ID} pairs — e.g. NextGen: 15241,
  PowerSchool: PST15241, AD: <objectGUID>, Google: jsmith@…, Intern: 88. This is central:
  show it prominently on the person record.
- **Assignments (multi-location):** rows of {school, title, FTE, primary?, dates}. Exactly
  one is the primary location.
- **Lifecycle events & audit:** a timeline of create/update/disable/terminate/convert/
  merge/username-assigned events, each with who + when.

## Screens to design (in priority order)

**1. Review queue — the hero screen.** When an incoming record might be an existing
person, it lands here for a human to decide. Design a **side-by-side comparison card**:
left = the incoming record (e.g., a new hire from NextGen), right = the suggested
existing person (e.g., a current intern). Show a **match-confidence score** and the
**match basis** (e.g., "name only", "name + DOB", "employee ID"). Field-by-field, align
the two and **highlight matches vs differences**. Two primary actions: **"Same person —
link & reuse account"** and **"Different people — create new"**. Make it visually clear
that linking reuses the existing account (avoids a duplicate). Emphasize caution on
weak ("name only") matches — never imply auto-merge. Include a queue list (pending count,
oldest first) to the side.

**2. People list.** Searchable, filterable table of all people: name, type, status badge,
primary school, username, email, employee ID. Filters: status, type, school, "missing
username", "pending". Row click → person detail. Bulk-friendly but read-only-ish.

**3. Person detail.** The golden record. Header with name, status badge, type, and the
person_uuid. Sections: Identity (username/email shown read-only with a small lock icon +
"assigned by sync engine" note), Demographics, **Source IDs crosswalk** (labeled chips or
a small table), **Assignments** (multi-location, primary marked), a **Provisioning status**
panel — per-destination cards/badges for Active Directory, Google Workspace, Raptor, and
PowerSchool showing last action, success/fail, and time (red when the last sync failed) —
and a **Lifecycle/Audit timeline**. Edit affordances for the human-owned fields
(demographics, primary location, status, notes) — but username/email are not editable.
Every edit should look like it gets audited.

**4. Home / health dashboard.** Top KPI cards: pending review (count), people pending
activation, people missing a username, unmapped ethnicity/school values, **accounts whose
last sync failed**, last feed run + status. Below: recent activity feed, a small "feeds"
panel (last NextGen / PowerSchool import: time, rows, errors), and a **failed-sync list**
(person, destination, last error) drawn from the per-account provisioning status.

**5. Add person (manual).** A form to create a person not in HR (long-term subs,
contractors): name, type, demographics, primary school, assignment(s). Note that username
is assigned later by the sync engine, not here.

**6. Reference data (secondary).** Simple admin tables to manage the Schools map (school +
its NextGen/PowerSchool codes + AD/Google OU) and the Ethnicity map (source value → ALSDE
code). These resolve incoming codes; surface "unmapped" values prominently.

**7. Import / feed status (secondary).** List of import batches (system, file, time, row
count, status) with a drill-in showing staged rows and their match outcome.

## Security & access (reflect in the UI)

- Sign-in is **SSO via the district SAML IdP** — design a simple "Sign in with district
  SSO" launch screen (no local password fields). Show the signed-in user + role in the top bar.
- **Role-aware UI:** three roles — admin, editor, read-only. Read-only users see everything
  but no edit/confirm/add actions (hide or disable them). Admin additionally gets a Users
  screen (map a person to a role) and reference-data management.

## Critical UX rules to honor in the design

- **Never present an automatic merge on name alone** — name-only matches must require an
  explicit human decision in the review queue.
- **Username & email are read-only** in this tool (the sync engine mints them); show a
  lock state once assigned.
- **Everything is audited** — make "who changed what, when" visible on records.
- **PII-aware** — this holds staff DOB/demographics; design for role-limited, no-nonsense
  display (no unnecessary exposure, clear it's sensitive).

## What to return

A coherent set of these screens as an interactive prototype with realistic sample data
(use plausible faculty names, schools, and IDs). Start with the **Review queue**, **People
list**, and **Person detail**, then the **Home dashboard**; reference/import screens can be
lighter. Include the shared shell (sidebar + top bar) and consistent components (status
badges, crosswalk chips, the comparison card).
