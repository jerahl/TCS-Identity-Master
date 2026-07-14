# Adaxes provisioning — direct AD create / edit / disable (bypassing ClassLink)

> **Status:** implemented, gated off. The write path now exists in code —
> `AdaxesWriter` (create/modify/disable/enable), `UsernameMinter` (the Phase-3
> identity policy), and the `AdaxesReconciler` behind `bin/adaxes_sync.php` — all
> unit/integration tested and **off by default** (`ADAXES_WRITE_ENABLED=false`);
> `AdaxesService` stays read-only and now shares its auth/transport via the
> `AdaxesHttp` trait. This document is the spec for making IDM the authoritative
> writer of Active Directory accounts through the Adaxes REST API, phase by phase,
> ending with **OneSync fully retired as the AD provisioner**.
>
> **Before enabling in production** (tracked in *Open items* below): confirm the
> exact REST create/modify/disable endpoints + payload shapes against the deployed
> Adaxes build (the `ADAXES_*_PATH` knobs), stand up the least-privilege write
> service account + Business Rules, and complete the correlation/cutover runbook.

## Goal & end-state

Today IDM is the golden record but a **passive** one with respect to AD: it
publishes `v_onesync_source`, OneSync (a ClassLink product) reads it and does the
actual create/edit/disable in AD, then writes usernames/status back to IDM.

The end-state of this work:

```
NextGen / PowerSchool ──► IDM golden record ──► Adaxes REST API ──► Active Directory
                                │  (create / edit / disable — IDM is the writer)
                                └─► mints username / email / UPN itself
OneSync:  no longer touches AD.  (Google Workspace stays on OneSync — separate branch.)
```

IDM owns the **entire AD account lifecycle**. OneSync is decommissioned for the
AD destination only; Google Workspace provisioning remains on OneSync and is out
of scope for this branch.

## What already exists (do not rebuild)

The read-only leg already contains most of the machinery a writer needs:

| Capability | Where | Reused for |
|---|---|---|
| Adaxes auth (static `Adm-Authorization` token **or** username/password handshake + session teardown) | `AdaxesService` (`authToken()`, `endSession()`) | all writes |
| HTTP transport (TLS/CA, timeout, injectable `$fetch` for tests, graceful degrade) | `AdaxesService::httpRequest()` | all writes |
| **Correlation** — resolve a person → AD account by `objectGUID`, else OR-search on `sAMAccountName`/`mail`/`employeeID` | `AdaxesService::verify()` / `searchCriteria()` | link-before-write |
| Correlation link store — `objectGUID` per person | `person_source_id (system='ad', source_key=objectGUID)` | edit/disable target key |
| GUID seeding (initial correlation run) | `bin/import_ad_usernames.php` (`AdUsernameImporter`), `bin/cleanup_ad_ids.php` | day-0 cutover |
| Field-by-field golden↔AD diff | `AdaxesService::compareToGolden()` | drives the edit delta |
| Username immutability + uniqueness | `person.username_locked`, `uq_person_username`, `uq_person_email` | mint collision guard |
| Audit + lifecycle | `audit_log`, `lifecycle_event` (`create`/`update`/`disable`/`enable`) | every write |
| OU placement | `school.ad_ou` (relative building OU, e.g. `OU=CO`) + `AD_BASE_DN` / `AD_PARENT_OU` / per-type leaf | create container + edit-phase move (`AdaxesWriter::move`) |
| Dry-run / idempotent importer conventions | every `bin/import_*.php` | the reconciler |

The correlation model is a 1:1 analog of OneSync's `correlation` controller
(stage → auto-match → link). We keep it; we only add the write.

## Terminology

- **Mint** — IDM generates a brand-new `username` / `email` / `upn` for a person
  who has none, then `username_locked=1`. Today OneSync mints; in Phase 3 IDM does.
- **Correlate / link** — associate a person with an existing AD account by storing
  its `objectGUID` in the crosswalk.
- **Reconcile** — compute desired AD state vs. live AD state for a person and apply
  the delta (create / modify / disable).

---

## Username, email & UPN policy (decided)

### Username (`sAMAccountName`)

**Format:** first-name initial + last name, **lowercase** — `John Smith → jsmith`.

**Collisions:** append an integer starting at **1**, incrementing by 1, to the
*base* form. The first holder gets the bare base; each subsequent collision gets
the next free integer.

```
John  Smith → jsmith
James Smith → jsmith1     (jsmith taken)
Jane  Smith → jsmith2     (jsmith, jsmith1 taken)
```

**Normalization rules** (applied before assembling the base):

1. Use the **legal `first_name`** (not `preferred_name`) and `last_name`.
2. Strip everything except `[A-Za-z]` from each part (drops spaces, hyphens,
   apostrophes, periods): `O'Brien → obrien`, `De La Cruz → delacruz`,
   `Mary-Jane → maryjane`.
3. Base = `strtolower(firstInitial + lastName)` → deterministic lowercase
   regardless of source casing. (`sAMAccountName` is case-insensitive in AD; we
   store the lowercased form and compare case-insensitively — `compareToGolden()`
   already does.)
4. **Length cap:** `sAMAccountName` max is 20 chars. If base + suffix would exceed
   20, truncate the **last-name portion** (never the initial or the numeric suffix)
   so the suffix always survives: `jverylonglastnameh`, then `jverylonglastname1`, …
5. Empty/all-stripped last name (data error) → do **not** mint; route to review.

**Collision domain** — a candidate is free only when it collides with **nothing**
across all three of:

- `person.username` (the `uq_person_username` index) — the authoritative ledger;
- any **locked** username already assigned (same index);
- the **live AD** directory (an Adaxes search for `sAMAccountName = candidate`) —
  catches pre-existing accounts IDM didn't create yet.

Check the DB first (cheap, indexed); confirm the winner against AD (one search)
before committing. On an AD-only collision, advance the suffix and re-check. This
is the same "propose → test → increment" loop OneSync ran with its word-hash lists.

### Email & UPN

Both are derived directly from the final username with a fixed domain:

```
email = upn = <username>@tusc.k12.al.us
```

e.g. `jsmith → jsmith@tusc.k12.al.us`. `mail`, `userPrincipalName`, and
`person.email`/`person.upn` all take this value. Domain is config, not hard-coded
(`AD_EMAIL_DOMAIN=tusc.k12.al.us`), so a future domain change is one env edit.
`uq_person_email` guards email uniqueness; because email is a pure function of the
(unique) username, an email collision can only happen against a pre-existing AD
mailbox — handled by the same AD-search check.

### Not used for minting

- `preferred_name` — usernames use the legal first name (AD convention). Preferred
  name may still flow to `displayName` (see create attributes).
- Once `username_locked=1`, the minter **never** revisits a person. Renames are
  out of scope (immutability is a core invariant).

---

## Phase plan

Each phase is independently shippable and leaves the system in a consistent state.
Writes are **off by default** behind `ADAXES_WRITE_ENABLED=false`.

### Phase 1 — Expire leavers (lowest risk, highest immediate value)

Leavers get locked out in AD promptly instead of waiting on OneSync's next read.
Rather than flipping `accountDisabled`, the reconciler **expires** the account:
it sets `accountExpires` to the person's **end date** when one is set (otherwise
today) and stamps `description` with `Account expired set by TCS-IDM on {run
date}` (the date IDM acted, independent of the expiry date) so the reason is
visible directly in AD. Expiring (vs. disabling) leaves the account in a state
Adaxes/AD already understand for a departed user and keeps a dated audit trail on
the object itself.

- The write is a plain `AdaxesWriter::modify()` (PATCH of `accountExpires` +
  `description`) — no dedicated disable endpoint needed. `AdaxesWriter::disable()`
  / `enable()` remain available for reactivations and other callers.
- New reconciler `bin/adaxes_sync.php` (dry-run capable). For Phase 1 it acts only
  on people with `person.status='disabled'` **and** a linked `objectGUID`, whose
  live AD account is **not already expired as intended** (already on the desired
  date is a no-op; with no end date, any past-or-today expiry is a no-op),
  confirmed via `verify()`.
- Source of "who to expire" is the **existing** logic — the "Not in NextGen —
  review to disable" queue + `flag_disable_candidates.php`. IDM already decides
  *who*; this makes IDM *do* it.
- **Guardrail:** never touch a person with no linked GUID (a search hit alone is
  not enough — could be the wrong account). Threshold valve
  (`ADAXES_WRITE_MAX_DISABLES_RATIO`, mirrors `NEXTGEN_DROPOUT_MAX_RATIO`) blocks a
  mass-expire from a truncated feed.

### Phase 2 — Edit / attribute drift

Push the diffs the verification panel already computes.

- `AdaxesWriter::modify(objectGuid, attrs)` — `PATCH`/`PUT` changed attributes.
- Reconciler extends to compute the delta from `compareToGolden()` and apply only
  fields that `differ`/`missing` on the AD side (never touch `sAMAccountName` —
  immutable).
- **Also kept in sync: the operational mappings.** OneSync writes these on every
  account; IDM keeps them in sync (each is pushed only when non-empty and it
  differs from AD):
  - `title` ← primary assignment title, mirrored into **`description`**.
  - `department` ← building name (Transportation staff overridden to the
    transportation department), mirrored into **`physicalDeliveryOfficeName`**
    (the AD *Office* field). `department` is load-bearing — all 22 per-school
    *Everyone* groups match on it, so a school move must propagate here or the
    Everyone-group logic breaks downstream.
  - `info` (the AD *Notes* field) ← the person's Google Workspace email
    (`<username>@GOOGLE_DOMAIN`); written only when `GOOGLE_DOMAIN` is set (we
    never put the on-prem address in Notes).
- **Also kept in sync: OU placement (moves).** The edit phase compares the
  account's current container (the parent of its `distinguishedName`) against the
  OU it *should* live in (the same `placement()` used on create) and, when they
  differ, relocates it via `AdaxesWriter::move(objectGuid, containerDn)`. This
  heals accounts that landed in the wrong OU — e.g. a bus aide created under a
  building that now belongs in `OU=trans`. The comparison ignores case and the
  optional spaces around DN separators, so cosmetic differences don't churn. The
  move endpoint is version-specific (`ADAXES_MOVE_PATH`, defaults to
  `api/directoryObjects/move`; supports an `{id}`-in-path shape too).
- Same GUID-required rule.

### Phase 3 — Create + minting (retire OneSync for AD)

The final phase. IDM mints identity and creates the account.

- New `UsernameMinter` service (pure, unit-tested) implementing the policy above.
- `AdaxesWriter::create(containerDn, attrs)` — create a `User` in the target OU;
  capture the returned `objectGUID` and link it into `person_source_id` immediately.
- Reconciler create path fires only for a person who is `active`/`pending`, has
  **no** linked GUID, and whose AD search returns **no** hit (a true net-new hire).
- Attributes IDM sends on create: `sAMAccountName`, `userPrincipalName`, `mail`,
  `displayName` (preferred-or-legal first + last), `givenName`, `sn`, `employeeID`,
  and the operational mappings kept in sync by Phase 2 — `title` + `description`
  (primary assignment title), `department` + `physicalDeliveryOfficeName` (Office;
  building name, Transportation override — see Phase 2 note on why department
  matters), `info` (Notes; Google Workspace email, when `GOOGLE_DOMAIN` is set) —
  and, when `person.end_date` is set, `accountExpires` (the position end date as a
  Windows FILETIME at midnight UTC, so it round-trips through the verify panel).
- **CN / name:** the object's name is sent top-level (it becomes the RDN), as
  `"First Last"` — OneSync's rule. CN must be unique within an OU, so on a live-AD
  `cn` hit the reconciler falls back to `"First Last (username)"`, which is
  guaranteed unique because the username is. (Exact payload field for the name is
  part of the "confirm payload shapes" open item.)
  **Everything operational** (home dir, groups, licensing, initial password policy)
  is left to **Adaxes Business Rules** — IDM does not replicate OneSync's full
  provisioning; that logic moves server-side into Adaxes, where the write account
  triggers it. (The password + must-change-at-logon + userAccountControl Business
  Rule does not exist yet — it is a **Phase 3 cutover blocker**; see Open items.)
- **Container / OU:** the full container DN is assembled most-specific first:

  ```
  [OU=<type leaf>,]  {school.ad_ou}  ,  {AD_PARENT_OU}  ,  {AD_BASE_DN}
  ```

  `school.ad_ou` holds the *relative* building OU (e.g. `OU=CO`). Every
  provisioned account nests under a shared parent OU (`AD_PARENT_OU`, default
  `OU=Faculty`) and its building OU. Contractors/subs/interns get an extra
  innermost **type leaf** OU (`AD_OU_CONTRACTOR=OU=PTC`, `AD_OU_SUB=OU=Subs` —
  plural, matching OneSync's existing placement — `AD_OU_INTERN=OU=Interns` by
  default); faculty and staff have none. Examples: a contractor at Central Office
  → `OU=PTC,OU=CO,OU=Faculty,<AD_BASE_DN>`; a faculty/staff member there →
  `OU=CO,OU=Faculty,<AD_BASE_DN>`. `AD_BASE_DN` is required for create (people
  are routed to review until it is set).

  Two **title-driven rules** (mirroring OneSync's custom mappings) trump the type
  leaf:

  - **Transportation** (title contains *bus* as a whole word — Bus Driver, Bus
    Aide, Bus Monitor, … — plus any extra titles in `AD_TRANSPORTATION_TITLES`) →
    `{AD_OU_TRANSPORTATION},OU=Faculty,<base>` (default `OU=trans`) with **no
    building segment**, and the AD `department` is overridden
    (`AD_DEPT_TRANSPORTATION`, default `Transportation`). Legacy aliases:
    `AD_OU_BUS_DRIVER` / `AD_DEPT_BUS_DRIVER`.
  - **SRO** (title matches *SRO* / *school resource officer*) →
    `{AD_OU_SRO},{school.ad_ou},OU=Faculty,<base>` (default `OU=SRO`), e.g.
    `OU=SRO,OU=BHS,OU=Faculty,<base>`.
- After create: set `username`/`email`/`upn` on the golden record, `username_locked=1`,
  activate a `pending` person, write `create` + `username_assigned` lifecycle events.
- **Correlate before create — and re-enable returning employees.** Every create
  candidate is first looked up live (`verify()`). A hit means the account already
  exists, so the reconciler **correlates** (links the `objectGUID`, adopts
  `username`/`email`/`upn`, activates the person) instead of minting a duplicate.
  The match must be **unambiguous** — an `objectGUID` plus either the locked golden
  username equal to the account's `sAMAccountName` (mail agreeing when both are set)
  **or**, for a not-yet-minted person, the golden `employee_id` equal to the
  account's `employeeID` with the surname (`sn`) agreeing. Anything short of that
  goes to **review**, never linked blindly.
  - **Returning employee (existing account is disabled/expired).** When the matched
    account is currently locked out — `accountDisabled` set, or `accountExpires` a
    past date — the reconciler **re-enables** it as part of the correlate: it clears
    `accountDisabled` (`enable()`), resets the expiry (to the person's new
    `end_date` when one is set, otherwise clears it back to *never* via
    `clearExpiration()`), and stamps `description` with `Account re-enabled by
    TCS-IDM on {run date} (returning employee)`. Counted as **rehired** (a distinct
    action on the Services summary); audited with a `create` lifecycle event. If the
    disabled account does **not** confidently match, it is routed to review flagged
    as a possible returning employee for a human to correlate + re-enable. Clearing
    `accountExpires` to *never* (an empty Timestamp value list) is best-effort so a
    version-specific clear can never turn a successful reactivation into an error.
- **OneSync cutover:** disable OneSync's AD destination (its `destinations/{id}/status`).
  OneSync keeps running for Google Workspace (separate branch). The
  `import_writeback` / `/api/onesync/username` inbound paths become no-ops for AD
  but stay for any Google-minted values.

| Phase | IDM owns in AD | New code |
|---|---|---|
| 1 | disable / enable | `AdaxesWriter::disable/enable`, `bin/adaxes_sync.php` |
| 2 | + attribute edits | `AdaxesWriter::modify`, delta from `compareToGolden()` |
| 3 | + create + minting → **OneSync off for AD** | `AdaxesWriter::create`, `UsernameMinter`, OU resolution |
| 4 | + group membership | `GroupPolicy`, `AdaxesService::memberOf`, `AdaxesWriter::add/removeFromGroup`, reconciler `groups` phase |

---

## Component interfaces (proposed)

### `App\Service\AdaxesWriter`

Sits beside `AdaxesService`, sharing its auth/transport (extract the auth +
`request()` plumbing into a small trait or a shared base, or compose an
`AdaxesService` instance). Every method returns a result envelope (never throws),
mirrors the read service, and is exercised through the injectable `$fetch`.

```php
final class AdaxesWriter
{
    /** @return array{ok:bool, error:?string, guid:?string, created:bool} */
    public function create(string $containerDn, array $attrs): array;

    /** @return array{ok:bool, error:?string, changed:array<string,string>} */
    public function modify(string $objectGuid, array $attrs): array;

    /** @return array{ok:bool, error:?string, changed:bool} */
    public function disable(string $objectGuid): array;
    public function enable(string $objectGuid): array;

    public function configured(): bool;      // requires ADAXES_WRITE_ENABLED + a write token/acct
}
```

> **Endpoints are version-specific and MUST be confirmed against the deployed
> Adaxes build**, exactly like the read paths (`ADAXES_OBJECTS_PATH` etc. are
> already configurable for this reason). Create/modify/disable in the Adaxes REST
> API are documented at <https://www.adaxes.com/sdk/ApiDocumentation.RESTApi/>;
> expose each as `ADAXES_CREATE_PATH` / `ADAXES_MODIFY_PATH` / `ADAXES_DISABLE_PATH`.

### `App\Service\UsernameMinter`

Pure logic, no I/O in the core; collision checks injected so it unit-tests without
a DB or a live AD (same pattern as `Matcher` and `WritebackImporter::decide()`).

```php
final class UsernameMinter
{
    /** Deterministic base, pre-collision: "JSmith". */
    public static function base(string $firstName, string $lastName): string;

    /**
     * Lowest free candidate. $isTaken($candidate) returns true if the candidate
     * collides in the DB or live AD; the caller wires it to both checks.
     * @param callable(string):bool $isTaken
     */
    public static function mint(string $firstName, string $lastName, callable $isTaken): string;

    public static function emailFor(string $username, string $domain): string; // JSmith@tusc.k12.al.us
}
```

---

## Configuration additions (`.env`)

> **Admin settings page.** All of the non-secret knobs below are also editable in
> the web console at **Settings → Configuration** (admin-only), backed by the
> `app_setting` table (migration `0017`). Those values layer **under** real
> environment variables and **over** `.env`, and are pushed into `Config` at
> bootstrap so they apply to both the app and the CLI reconciler. **Secrets**
> (`ADAXES_TOKEN` / `ADAXES_WRITE_TOKEN`, service-account password, DB / SAML /
> Google credentials) are intentionally **not** editable there — they stay in
> `.env` / the environment. The whitelist in `SettingsService` is the boundary.

```sh
# --- Adaxes WRITE (direct AD provisioning; bypasses OneSync for AD) ------------
ADAXES_WRITE_ENABLED=false          # master switch; false = read-only (today's behavior)
ADAXES_WRITE_TOKEN=                 # token for the WRITE service account (see role below)
                                    #   (falls back to ADAXES_TOKEN / username+password if unset)
ADAXES_CREATE_PATH=api/...          # confirm per Adaxes version
ADAXES_MODIFY_PATH=api/...
ADAXES_DISABLE_PATH=api/...
ADAXES_WRITE_MAX_DISABLES_RATIO=0.2 # safety valve, mirrors NEXTGEN_DROPOUT_MAX_RATIO
ADAXES_WRITE_MAX_CREATES=50         # per-run cap on net-new account creation

# --- Identity minting (IDM becomes the username authority in Phase 3) ----------
AD_EMAIL_DOMAIN=tusc.k12.al.us      # email = upn = <username>@this
AD_UPN_SUFFIX=tusc.k12.al.us        # usually identical to AD_EMAIL_DOMAIN
```

Placement config: `AD_BASE_DN` (domain base appended to the relative
`school.ad_ou`), `AD_PARENT_OU` (shared parent OU every account nests under,
default `OU=Faculty`), and per-type leaf OUs `AD_OU_CONTRACTOR` / `AD_OU_SUB` /
`AD_OU_INTERN` (defaults `OU=PTC` / `OU=Subs` / `OU=Interns`), plus the
title-driven Bus Driver / SRO placement (`AD_OU_BUS_DRIVER` / `AD_DEPT_BUS_DRIVER`
/ `AD_OU_SRO`).

### Phase 4 — Group membership

IDM owns group membership directly (the source-of-truth approach), replacing
OneSync's Faculty AD group mappings. `GroupPolicy` (pure, unit-tested) computes
the group set a person *should* be in from the structured truth IDM already
holds; the reconciler's `groups` phase reads live `memberOf`, diffs, adds what's
missing, and removes memberships **IDM manages** that the person no longer
qualifies for (groups outside the managed set are never touched — so a school
move correctly drops the old Everyone group).

Rules (from the OneSync destination):

- **All-Faculty** — everyone.
- **Per-school Everyone group** — from the building OU token: `OU=CO` →
  `CO-Everyone`. Naming exceptions: `RQES → RQS`, `UPE → UP`.
- **Transportation** — everyone in transportation (Bus Drivers).
- **Microsoft 365 license** (exactly one): the **A1** group if the title contains
  `CNP` / `custodian` / `bus driver` / `aide` / `sub` / `intern` / `SRO`, or the
  person is a contractor / sub / intern; otherwise the **A3** group.
- **Raptor role** (exactly one, first match wins by title):
  - `Raptor_BuildingAdmin` — title contains *Principal* or *IT Computer Tech*
  - `Raptor_ClientAdmin` — *IT Technician Supervisor*, *Safety Contractor*, or *Director of Technology*
  - `Raptor_EntryAdmin` — *Secretary* or *bookkeeper*
  - `Raptor_GlobalAdmin` — *Network Administrator* or *Security Specialist*
  - `Raptor_EmergencyManagementUser` — everyone else

  **Per-person exceptions.** The title rule is the default; an admin can override
  the Raptor role for an individual on the person page (the *Raptor role
  exception* control). The choice is stored on `person.raptor_group_override` as a
  stable role **key** (`buildingadmin` / `clientadmin` / `entryadmin` /
  `globaladmin` / `emergency`), so it survives a group-name change; `''` = automatic
  (by title) and `none` = exclude from every Raptor group. The groups phase reads
  the override and, because the resulting group is still in the managed set, the
  person's old Raptor group is removed and the exception added on the next sync
  (`GroupPolicy::resolveRaptor` / `raptorRoleOptions`).

Group **names** are configurable (`AD_GROUP_*`, including
`AD_GROUP_RAPTOR_*`); confirm they match the real AD names before enabling. Membership is written through the Adaxes group-member API
(`ADAXES_GROUP_MEMBERS_PATH`, default `api/directoryObjects/groupMembers`):
`POST …/groupMembers {"group":…,"newMember":…}` to add, `DELETE
…/groupMembers?group=…&member=…` to remove. The API takes a **DN/GUID**, not a
name, so the reconciler resolves each group name to its DN first
(`AdaxesService::findGroup`, cached per run) and reports an error if a group
isn't found in AD.

## Adaxes-side setup (not code)

- A dedicated **write service account** with an Adaxes **Security Role** granting
  *Create User*, *Modify*, and *Enable/Disable Account* **scoped to the staff OUs**
  — least privilege, separate from the existing read-only account. Generate its
  token with `New-AdmAccountToken`.
- **Business Rules** to own the operational side of new accounts (home directory,
  group membership, licensing, password policy) so IDM only sends identity core.
- Keep the read-only account for `verify()`; the writer is additive.

---

## Identity lifecycle: email, unlink, delayed events, rename

Beyond create/edit/disable/groups, IDM owns the human side of identity changes.

- **Email.** `Mailer` sends notifications (rename notices, alias-expiry reminders)
  through a pluggable transport (`smtp` / `sendmail` / disabled default) and logs
  every send to `email_outbox`. Off until `MAIL_ENABLED=true` + a transport; a
  disabled send is queued, never dropped.
- **Unlink username.** `PersonWriter::unlinkUsername` undoes a bad mint / bad
  correlation (wrong name, employee id, or a link to the wrong AD account) —
  clears username/email/upn + the lock and **removes** the `ad` objectGUID
  crosswalk row(s) entirely (so a wrong/stale GUID can't resolve here or block
  re-linking it) so the reconciler re-assigns a corrected identity. Admin-only
  (`/people/{id}/unlink`); shows even for a linked-but-unnamed person (a bad
  correlation with no username), and cancels any pending rename events.
  **Removing the crosswalk needs `DELETE` on `person_source_id`** — the setup
  script grants it to the app role; if the deployed role lacks it, unlink falls
  back to *deactivating* the row (which still stops it resolving a GUID, since an
  inactive `ad` link no longer matches) and says so. Grant DELETE for a full
  removal: `GRANT DELETE ON <db>.person_source_id TO '<app_user>'@'<host>';`
- **Delayed events.** `scheduled_event` + `ScheduledEventService` are a durable
  queue for future actions; `bin/run_scheduled_events.php` (a systemd timer, every
  ~15 min) claims due rows and `ScheduledEventRunner` dispatches them by type.
  Failures retry then park; scheduling is idempotent via a dedupe key.
- **Rename on last-name change.** When a person's legal last name changes, an admin
  approves the rename (`RenameService::approve`, `/people/{id}/rename`). IDM mints
  the new username and:
  1. **now** — emails the employee, their **principal** (looked up from the golden
     record: an active person at the same building whose assignment title contains
     *Principal* — same data PowerSchool has, always present), and **IT**, stating
     the name change and that on **cutover date** (`RENAME_NOTICE_DAYS`, default 7)
     the username/email will change from XX to YY, with the old address delivering
     for `RENAME_ALIAS_DAYS` (default 90);
  2. **at cutover** — renames the AD account (`sAMAccountName`/UPN/mail — the one
     sanctioned exception to username immutability, via `AdaxesWriter::rename`),
     keeps the old address as a delivering **alias** (`proxyAddresses`, read-modify-
     write) in AD **and** Google, stamps the golden record, and schedules the alias
     removal + reminders;
  3. **before removal** — emails reminders (`RENAME_ALIAS_REMINDER_DAYS`, default
     `14,3`);
  4. **at +90 days** — removes the old alias in AD + Google and confirms by email.

  The rename is admin-approved (not automatic on feed drift), so a typo/hyphenation
  fix doesn't silently rename accounts.

  **Editable text.** The four rename emails (notice / done / reminder / removed)
  are edited in the web console at **Settings → Email templates** (admin), backed
  by `EmailTemplateService` + `email_template` (migration 0020). Each has a subject
  and body with `{placeholder}` tokens (`{name}`, `{old_username}`, `{new_email}`,
  `{cutover_date}`, `{remove_date}`, …); built-in defaults apply until edited, and
  "Reset" reverts. Recipients (employee / principal / IT) are fixed — the page
  controls wording only.

## Guardrails (invariants across all phases)

1. **Link before write.** Edit/disable only ever act on a person with a linked
   `objectGUID`. A search hit without a stored GUID is a *correlation* task, not a
   write — route it to review.
2. **Create only when truly new.** No linked GUID **and** no AD search hit.
3. **Username immutability.** Never re-mint or rename a `username_locked` person;
   the minter skips them entirely.
4. **Threshold valves** on both disable (ratio) and create (absolute count).
5. **Audit everything** to `audit_log` + `lifecycle_event`, actor `system:adaxes_sync`.
6. **Dry-run** (`--dry-run`) on the reconciler is also the change report: it
   reads live AD and prints, per person, what is currently set vs. what would
   change — edits/moves as `current → proposed`, disables showing the account is
   still enabled, groups as the +add/-remove delta — and changes nothing.
   Required before every real run during rollout.
7. **Off by default** — `ADAXES_WRITE_ENABLED=false` until deliberately enabled.
8. **TLS verification stays on** (existing `ADAXES_CA_FILE` / `ADAXES_VERIFY_TLS`).

## Data model

Minimal change. Reuse `person_source_id` (GUID link), `person` (username/email/upn/
lock), `audit_log`, `lifecycle_event`. Optional provenance nicety: record that a
username was **IDM-minted** vs. OneSync-supplied — either a new
`person.username_source ENUM('onesync','idm','ad_import')` column (migration) or an
inference from `lifecycle_event`. Not required for correctness; useful for cutover
reporting. Decide during Phase 3.

---

## Cutover runbook (Phase 3 go-live)

1. **Correlate first.** Run the Employee List GUID import + `cleanup_ad_ids.php` so
   every existing staff member has a linked `objectGUID`. Verify coverage on the
   dashboard (people with an `ad` crosswalk row).
2. **Dry-run the reconciler.** `php bin/adaxes_sync.php --dry-run` — confirm it
   proposes disables/edits for known accounts and **creates only genuine new hires**.
   Investigate any unexpected create (usually a missing GUID link, i.e. a
   correlation gap, not a real new person).
3. **Live-test a small cohort.** With `ADAXES_WRITE_ENABLED=true`, target a few
   test accounts so a *real* write fires (and with it the Adaxes Business Rules —
   home dir, groups, licensing, password) without touching anyone else:
   `php bin/adaxes_sync.php --only=<person_ids> --verbose` (or
   `--employee=<employee_ids>`). Verify the account, its OU, attributes, group
   membership, and the Business-Rule side effects in AD, then widen. Dry-run the
   same cohort first (`--only=… --dry-run`).
4. **Enable writes for disable/edit** first (Phases 1–2 in production) and watch a
   few cycles.
4. **Enable create.** Turn on `ADAXES_WRITE_ENABLED` create path with a low
   `ADAXES_WRITE_MAX_CREATES`; ramp up once clean.
5. **Turn off OneSync's AD destination** (`destinations/{id}/status` disable). Leave
   OneSync running for Google.
6. **Monitor** the failed-write rollup (extend the dashboard's "failed syncs" card
   to include Adaxes-write failures) and the audit timeline.

**Rollback:** set `ADAXES_WRITE_ENABLED=false` and re-enable OneSync's AD
destination. IDM state is unchanged; no data migration to reverse.

## Testing

- `UsernameMinter` — unit tests for base form, casing, punctuation stripping
  (`O'Brien`, `De La Cruz`, hyphenates), the 20-char truncation-with-suffix rule,
  and the increment sequence (`JSmith → JSmith1 → JSmith2`), with `$isTaken`
  stubbed. Pure, no I/O.
- `AdaxesWriter` — unit tests with an injected `$fetch` asserting the request
  method/body for create/modify/disable and the envelope on success / 4xx / 5xx /
  transport failure (mirror `AdaxesServiceTest`).
- Reconciler — integration test over a seeded DB + fake Adaxes covering: disable a
  linked leaver, skip an unlinked one (→ review), create a net-new hire and link its
  GUID, skip a locked person, and trip both threshold valves.

## Open items

- ~~Confirm exact Adaxes REST create/modify/disable payload shapes.~~ **Done.**
  Per the REST API docs ([Modify](https://www.adaxes.com/sdk/REST_ModifyDirectoryObject/),
  [Create](https://www.adaxes.com/sdk/REST_CreateDirectoryObject/),
  [Setting property values](https://www.adaxes.com/sdk/REST_SetPropertyValues/)):
  the target is named in the **body** (`directoryObject` for modify, `createIn`
  for create), properties are `{propertyName, propertyType, values:[…]}` (NOT
  `{name, value}`), `cn` is a normal property (not a top-level `name`), and
  `accountExpires` is a `Timestamp` in ISO-8601 (`2027-05-31T00:00:00Z`), not a
  Windows FILETIME. `AdaxesWriter` now sends these; error responses surface the
  Adaxes message so a bad property/value is obvious.
- Confirm the staff OU layout so `school.ad_ou` values are complete/correct for
  every building before enabling create. The 22 per-school Everyone groups give
  the authoritative building list to validate against.
- **Verify the real leaf OU names in AD** before enabling create: IDM defaults
  `AD_OU_SUB=OU=Subs` (OneSync's placement); a mismatch double-trees the
  directory. Also confirm `OU=PTC` for contractors — it has **no OneSync
  counterpart** and is intentional new design; sign off (or change it) before
  cutover.
- **Password + UAC Business Rule (Phase 3 cutover blocker).** OneSync does
  generated-password + must-change-at-next-logon + Normal Account on create.
  The design defers this to an Adaxes Business Rule so IDM never handles
  secrets — that BR must be authored and tested before any real create.
- **Welcome email on create.** OneSync's "Email on Create" has no equivalent
  yet — neither the reconciler nor a Business Rule sends the credentials /
  welcome notification. `NotifyTemplateService` is the natural hook if IDM owns
  it; decide IDM-vs-Adaxes alongside the password BR.
- **Group membership (Phase 4)** — the matching rules are implemented
  (`GroupPolicy`). Before enabling: (a) confirm the exact AD group **names**
  (`AD_GROUP_ALL_FACULTY` / `_TRANSPORTATION` / `_M365_A1` / `_M365_A3`, the
  `-Everyone` suffix, and the Everyone-token remaps `RQES→RQS` / `UPE→UP`); and
  (b) confirm the group-membership endpoint (`ADAXES_GROUP_MEMBERS_PATH`, default
  `api/directoryObjects/groupMembers`) matches the deployed Adaxes build. The
  `sub` (A1) keyword and the `Secretary` spelling were normalized from OneSync's
  rules; spot-check against the live destination.
- Confirm `AD_DEPT_TRANSPORTATION` — IDM defaults the transportation department
  override to `Transportation`; verify the exact string OneSync writes (group
  matching is string-sensitive). Transportation staff = any title with *bus* as a
  whole word (Bus Driver, Bus Aide, …); add non-"bus" transportation titles to
  `AD_TRANSPORTATION_TITLES`.
- **Email transport.** `Mailer` ships with SMTP and sendmail transports; confirm
  which the district uses (an internal relay / Exchange Online submission on :587,
  vs. the host MTA) and set `MAIL_*` / `SMTP_*`. A Microsoft 365 Graph transport
  can be added behind the same `MailTransport` interface if preferred.
- **Rename `proxyAddresses` + Google alias mechanics.** The AD alias lifecycle is
  read-modify-write on `proxyAddresses` via the modify endpoint, and Google uses
  the Admin SDK / GAM alias calls — confirm both against the deployed builds (same
  "confirm payload shapes" caveat), and confirm `sAMAccountName` rename is
  permitted for the write account.
- Decide whether to add `person.username_source` provenance (reporting only).
