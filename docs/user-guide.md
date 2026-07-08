# TCS Identity Master — User Guide

A guide for the people who use the **Identity Master** web app day to day —
verifying records, working the review queue, adding people, and keeping an eye
on syncs. No command line or setup knowledge is needed; everything here happens
in your browser.

> **What this app is for.** Identity Master keeps **one record per person** for
> Tuscaloosa City Schools staff and faculty. It pulls people in from NextGen (HR)
> and PowerSchool, links every system ID a person carries to a single "golden
> record," and hands that clean list to **OneSync**, which creates and updates
> their accounts in Active Directory and Google. Your job in this app is to keep
> those records accurate — the accounts follow automatically.

---

## Contents

1. [Signing in](#1-signing-in)
2. [Getting around](#2-getting-around)
3. [What your role can do](#3-what-your-role-can-do)
4. [Dashboard — system health](#4-dashboard--system-health)
5. [People — finding and reading records](#5-people--finding-and-reading-records)
6. [Review queue — confirming matches](#6-review-queue--confirming-matches)
7. [Adding a person manually](#7-adding-a-person-manually)
8. [Editing and disabling a person](#8-editing-and-disabling-a-person)
9. [Reference data — schools, ethnicity, field mapping](#9-reference-data)
10. [Import / feeds](#10-import--feeds)
11. [VPN status](#11-vpn-status)
12. [Users (admins)](#12-users-admins)
13. [Audit log (admins)](#13-audit-log-admins)
14. [Everyday tasks — quick recipes](#14-everyday-tasks--quick-recipes)
15. [Logins export & orientation checklists](#15-logins-export--orientation-checklists)
16. [Terms you'll see](#16-terms-youll-see)

---

## 1. Signing in

Open the app in your browser and click **Sign in with district SSO**. You'll
authenticate with your normal Tuscaloosa City Schools account and land back in
Identity Master automatically. There's no separate password to remember.

- **Every sign-in and sign-out is logged.** Admins can see this in the Audit log.
- **First-time users start read-only.** The first time you sign in, you can view
  everything but can't change anything until an administrator grants you a
  higher role (see [Users](#12-users-admins)).
- **Signing out:** click the **arrow icon** next to your name in the top-right
  corner.

Your name, role, and the current environment (e.g. `PRODUCTION`) are always
shown in the top bar so you know who you're signed in as and where.

---

## 2. Getting around

The **left sidebar** is your main menu. What you see depends on your role:

| Menu item | What it's for |
|-----------|---------------|
| **Dashboard** | System health at a glance — the home screen |
| **Review queue** | Incoming records that might match an existing person |
| **People** | The full directory of records; search, filter, drill in |
| **Add person** | Manually create a record (editors and admins only) |
| **Reference data** | The maps that translate school codes, ethnicity values, and job codes |
| **Import / feeds** | History of data pulled from NextGen and PowerSchool |
| **VPN status** | Live health of the PowerSchool VPN tunnel |
| **Users** | Manage who can sign in and their role (admins only) |
| **Audit log** | Every change and login, with before/after detail (admins only) |

**Badges on the menu:** a number next to **Review queue** tells you how many
records are waiting for a decision. A red badge means records are flagged to be
disabled.

**Search anything, anywhere.** The search box at the top of every page searches
people by name, ID, username, or email. Type and press Enter to jump to the
People list filtered to your search.

---

## 3. What your role can do

There are three roles. Each one includes everything the role below it can do.

| Role | Can do |
|------|--------|
| **Read-only** | View all pages and records. Cannot make changes. |
| **Editor** | Everything read-only can do, **plus** work the review queue, add people, edit records, disable accounts, and run imports. |
| **Admin** | Everything an editor can do, **plus** manage users and roles and view the audit log. |

If you don't see a button (like **Add person** or **Edit record**), your role
doesn't allow that action. Pages will tell you when an action needs a higher
role — for example, the review screen shows *"You have read-only access"*
instead of the confirm/reject buttons.

---

## 4. Dashboard — system health

The Dashboard is your home screen. It answers one question: **is everything
healthy, and if not, what needs attention?**

### The status cards

Across the top are cards summarizing the system. Each is **clickable** and takes
you straight to the records behind the number. A colored dot shows the health:
green is fine, amber/yellow needs a look, red needs action.

| Card | Meaning |
|------|---------|
| **Pending review** | Records waiting for you to confirm or reject a match |
| **Pending activation** | People not yet provisioned with an account |
| **Missing username** | People with no account created yet |
| **Unmapped values** | School codes or ethnicity values the system doesn't recognize |
| **Failed syncs** | Accounts whose last push to AD/Google failed |
| **To disable** | People who've left but whose account is still enabled |
| **OneSync DB sync** | When provisioning results were last pulled from OneSync's database |
| **Students → OneSync** | Status of the student passthrough sync |
| **Last feed run** | The most recent data import and whether it succeeded |

Any **alerts** (for example, a stale sync) appear as yellow banners just under
the page title.

### Below the cards

- **Recent activity** — a running log of what's changed recently. Click a name
  to open that person.
- **Feeds by source** — the latest import from each source system, with row
  counts and how many need review.
- **Students → OneSync** — status of the student data passthrough (this app only
  *shows* it; students are handled straight from PowerSchool).
- **Accounts whose last sync failed** — a table of accounts that didn't
  provision cleanly, with the error. Click a row to open the person.

---

## 5. People — finding and reading records

**People** is the full directory. It shows how many records are displayed out of
the total, and lets you narrow the list.

### Finding someone

- **Filters** across the top: by **status** (active, pending, disabled,
  terminated), **type** (faculty, staff, contractor, substitute, intern), and
  **school**. Pick from the dropdowns and the list updates immediately.
- **Quick chips:** **Missing username** and **Pending** narrow to just those
  records in one click.
- **Sort** by clicking the **Name** or **Employee ID** column headers; click
  again to reverse the order.
- **Search** using the top bar (name, ID, username, or email).

Click any row to open that person's full record.

### Reading a person's record

The detail page gathers everything known about one person:

- **Header** — their name, status, and type, plus the golden-record ID and when
  it was created/updated. Editors see an **Edit record** button here.
- **Identity** — username and email. These have a **lock icon** because
  **OneSync owns them** — it mints them once the record is activated, and any
  change you make here is ignored by sync. If they're blank, the account hasn't
  been created yet.
- **Demographics** — legal/preferred name, date of birth, gender, ethnicity,
  IDs, hire and end dates. This section is marked **Sensitive PII** — handle it
  accordingly.
- **Assignments** — every school, title, and role the person holds (people can
  be at more than one location; one is marked **PRIMARY**).
- **Source ID crosswalk** — every system that knows this person (NextGen,
  PowerSchool, AD, etc.), all tied to the one golden record.
- **Provisioning status** — per-destination state from OneSync (AD, Google,
  Raptor, PowerSchool): synced, failed, stale, or not yet synced.
- **Source field reconciliation** — a field-by-field comparison of NextGen vs
  PowerSchool. Fields that **differ** or are **missing** are highlighted so you
  can fix them before the next sync.
- **Active Directory (live)** — if enabled, a live comparison of the record
  against the actual AD account.
- **Lifecycle & audit** — a timeline of everything that's happened to the record.
- **Notes** — free-text notes on the record.

---

## 6. Review queue — confirming matches

When a new record comes in from a feed and *might* be the same person as someone
already on file, the system **does not merge automatically** — it asks a human.
That's the review queue.

> **Why this matters:** linking two records means reusing one account. Link the
> wrong people and you merge two humans into one login. When in doubt, don't
> link.

### Working a case

1. The **Pending** list on the left shows cases, oldest first, each with a
   confidence score (Strong / Moderate / Weak).
2. Click a case to open the **side-by-side comparison**: the **incoming record**
   next to the **existing person**, field by field. Matching fields, differences,
   and info-only fields are color-tagged.
3. The **confidence score** and **match basis** (name + DOB, employee ID, etc.)
   tell you how strong the match is. A **weak, name-only match** shows a red
   warning — verify date of birth, employee ID, or another source before linking.
4. Decide (editors and admins only):
   - **Same person — link & reuse account** — ties the incoming record to the
     existing person. No duplicate account is created.
   - **Different people — create new** — treats the incoming record as a new
     person.

Read-only users can view comparisons but not decide.

### The "review to disable" list

At the bottom of the page is **Not in NextGen — review to disable**: people whose
NextGen record is gone (they left, or their exit date passed) but whose account
is still enabled. NextGen won't disable these on its own, so review each one and
click **Disable** so OneSync properly disables the account instead of leaving it
orphaned.

---

## 7. Adding a person manually

*(Editors and admins.)* Most people arrive automatically from HR feeds. Use
**Add person** only for people who **aren't** in HR — long-term substitutes,
contractors, and interns.

1. Click **Add person** in the sidebar (or the button on the People page).
2. Fill in **Identity** (first and last name and type are required) and, if you
   know it, a **Primary assignment** (school, title, FTE).
3. Click **Create pending record**.

The record starts as **Pending**. **You do not set the username or email** —
OneSync creates and locks those once the record is activated.

---

## 8. Editing and disabling a person

*(Editors and admins.)*

- **Edit a record:** open the person and click **Edit record**. Remember that
  username and email are locked and owned by OneSync — editing them here has no
  effect on the account.
- **Disable a person:** disabling tells OneSync to disable the account (rather
  than leaving it enabled after someone leaves). You'll most often do this from
  the **review to disable** list on the [Review queue](#6-review-queue--confirming-matches).

Every edit and disable is recorded in the record's timeline and in the audit log.

---

## 9. Reference data

**Reference data** holds the lookup tables that translate incoming codes into
values the district uses. Unmapped values block clean account provisioning, so
this page surfaces them for you to fix. It has four tabs:

- **Schools map** — each school with its NextGen code, PowerSchool code, and the
  AD/Google locations (OUs) accounts land in. Rows missing a mapping are
  highlighted. Below the table, **unmapped school codes seen in feeds** lists
  codes that showed up in imports but have no match — people with those codes
  land at no school until an alias is added.
- **Ethnicity map** — how each source ethnicity value maps to the official ALSDE
  code. **Unmapped values** are flagged so no one is sent downstream without a
  code.
- **Positions map** — how each NextGen job code classifies an employee as
  **Faculty** or **Staff**. Codes not in the map import as Staff, so it's enough
  to list the faculty codes; job codes seen on assignments with no mapping are
  listed below the table so you can spot faculty positions still coming in as
  Staff.
- **Field mapping** — a reference crosswalk showing how each NextGen field lines
  up with PowerSchool and where it ends up on the golden record. (Read-only
  reference; useful for understanding the person detail page.)

If the Dashboard shows **Unmapped values**, this is where you come to see what's
unmapped. (Editing the maps themselves is done by an administrator.)

---

## 10. Import / feeds

**Import / feeds** shows the history of data pulled into the system from each
source. Each row is a **batch** — one import run — with its source, file, time,
row count, how many matched, and status.

- **Click a batch** to see its **staged rows** and how each one matched
  (matched, new person, needs review, etc.), with the reason.
- **Editors** can, at the top of the page:
  - **Pull & import now** — fetch the latest feeds from the district SFTP server
    and PowerSchool and import them. Already-fetched files are skipped.
  - **Upload & import a feed** — upload a single CSV for a chosen source. Tick
    **Dry run** to preview the result without saving anything.

Re-running an import is safe — existing people are re-matched by their source ID
rather than duplicated.

---

## 11. VPN status

**VPN status** is a live, read-only view of the VPN tunnel that reaches the
PowerSchool database. It shows an **overall** health badge and individual
signals — the service, the tunnel interface, the network route, database
reachability, portal liveness, and recent logs — with uptime and flap counts
over the recent window.

The page **auto-refreshes every 30 seconds**. It only *displays* status; it
never starts, stops, or changes the tunnel. If it can't reach the monitor, it
says so with a warning.

---

## 12. Users (admins)

*(Admins only.)* **Users** maps district SSO accounts to a role.

- **First-login users start read-only** until you grant them a role here.
- **Change a role:** pick a new role in the dropdown next to a user and click
  **Save**. (You can't change your own role — that's disabled to prevent locking
  yourself out.)
- **Pre-provision access:** add a user by email *before* their first login using
  **Add user**, so they arrive with the right role already set. They're matched
  by email on their first SSO sign-in.

See [What your role can do](#3-what-your-role-can-do) for what each role allows.

---

## 13. Audit log (admins)

*(Admins only.)* The **Audit log** records **every change and every
login/logout** — who did it, what changed (before and after), and when.

- **Filter** by entity (person, assignment, match, user, etc.), by action
  (insert, update, delete, merge, login, logout), or by actor (an email or a
  system process).
- Click **view** on any row to expand the **before/after** detail of a change.
- Results are paginated; use **Prev** / **Next** at the bottom.

This is your record for answering "who changed this, and when?"

---

## 14. Everyday tasks — quick recipes

**"Someone new was hired and I want to check their account is set up."**
Search their name in the top bar → open their record → check **Identity**
(username/email present) and **Provisioning status** (AD/Google synced).

**"There's a number next to Review queue."**
Open **Review queue**, work each case top to bottom, confirming or rejecting.
Verify weak matches carefully before linking.

**"An account didn't get created / a sync failed."**
Dashboard → **Failed syncs** card, or the **Accounts whose last sync failed**
table → click the person → read the error under **Provisioning status**.

**"Someone left the district but still has an account."**
Review queue → **Not in NextGen — review to disable** → click **Disable** on
their row.

**"A contractor needs an account and isn't in HR."**
**Add person** → fill in their details → **Create pending record**. OneSync
creates the account once it's activated.

**"The Dashboard says there are unmapped values."**
**Reference data** → check the **Schools** and **Ethnicity** tabs for flagged
values and ask an admin to add the missing mapping.

**"I need to give a colleague access."**
*(Admin.)* **Users** → **Add user** with their district email and a role, or
change their role once they've signed in.

---

## 15. Logins export & orientation checklists

This replaces the old manual *Logins* spreadsheet and the Word mail-merge for
new-employee account notifications.

### The Logins export

Open **Logins export** in the sidebar. It shows the golden record in the exact
columns of the old Logins spreadsheet — last name, first name + MI, from/to
school and position, effective and end dates, board approval, employee ID, DOB,
gender, race, and ALSDE ID — pulled straight from what NextGen and PowerSchool
already feed in. No more copying from NextGen by hand.

- **Filter** by status, school, and an effective-date window (position start,
  falling back to hire date), or search by name/ID.
- **From School / From Position** are filled in automatically for a transfer (from
  the person's previous assignment) and left blank for a brand-new hire.
- Cells that still need a human — **Board Approval** and, for a brand-new hire not
  yet in PowerSchool, **ALSDE ID** — are highlighted. Enter Board Approval on the
  person's **Edit** screen; ALSDE ID fills in automatically once PowerSchool has
  the person (or can be typed on the Edit screen).
- **Download CSV** exports exactly what you see (respecting the current filters).

### Orientation checklists

Once OneSync has minted a person's username, you can generate their **Technology
Orientation Checklist** — the New Teacher version for faculty, the
Non-Instructional version for everyone else — pre-filled with their name, school,
position, and their new **username, email, and sign-in**. When OneSync has also
delivered the account's **temporary password** (via the write-back API), it
appears in the "Your account" box; until then the box says *provided by your
school* / *provided by your supervisor* as before. Treat a checklist that carries
a password like the credential it contains — hand it to the new hire directly.

- On a **person's record**, click **Orientation checklist** (editors/admins; it
  appears once a username exists). It opens a preview with a **Download PDF**
  button (a real PDF generated on the server) and a **Print** option. The links
  are genuine, clickable hyperlinks, so the PDF never has the broken-link problem
  the old Word *Finish & Merge* had.
- On the **Logins export**, the **Checklist** column has an **Open** link for
  everyone whose account is ready.
- **Generate all at once.** On the Logins export, **Generate all checklists**
  produces one PDF per ready person in the current filter and downloads them as a
  single ZIP. Every generation (single or bulk) is written to the audit log and
  the person's timeline.

### Editing the checklist content

Click **Edit checklist content** on the Logins export (editors/admins) to change
the heading, intro, and steps for each variant. Content uses a simple format:

- Start a section with `## Section name`.
- Start each step with `- step text`.
- Add links as `[label](https://example.com)` — only `http`/`https` links become
  clickable.
- The placeholders `{name}`, `{username}`, `{email}`, `{employeeid}`, `{school}`,
  `{position}`, `{start_date}`, and `{temp_password}` are filled in per person
  (`{temp_password}` is blank until OneSync delivers one; the "Your account" box
  shows it automatically either way).

Account details are always inserted live regardless of the template. **Reset to
default** restores the built-in content for a variant.

> **Prefer the old Word document?** You can keep the existing merge and just point
> its data source at the Logins **Download CSV** instead of the hand-maintained
> workbook — same document, authoritative data, no OneDrive step.

---

## 16. Terms you'll see

- **Golden record** — the single, authoritative record for one person. Everything
  in this app revolves around keeping one golden record per human.
- **OneSync** — the downstream system that reads this app's clean list and
  creates/updates accounts in Active Directory and Google. It **owns usernames
  and emails**; this app owns the person's data.
- **NextGen** — the HR system; the main source of staff and faculty records.
- **PowerSchool** — the student information system; a source for some staff
  fields and the student passthrough.
- **Source ID / crosswalk** — the ID a person has in each separate system, all
  linked to the one golden record.
- **Provisioning** — the act of creating or updating a person's account in a
  destination (AD, Google, etc.).
- **Staged row / batch** — a row and the run it came in on, as data is imported
  before it's applied to records.
- **PII** — personally identifiable information (date of birth, IDs, etc.).
  Handle records marked **Sensitive PII** with care.
- **Stale** — a sync or feed that hasn't run recently enough and may be out of
  date.

---

*Questions or something not working as described? Contact your Identity Master
administrator.*
