# Plan: in-app provisioning to AD + Google (retiring OneSync)

> **Goal.** Make TCS Identity Master mint usernames and provision accounts to
> **Active Directory** and **Google Workspace** directly, so OneSync is no longer
> needed for staff/faculty (and, in a later phase, students) account lifecycle.
>
> **Out of scope / unaffected.** ClassLink **OneRoster / Roster Server**
> (rostering, fed directly from PowerSchool) and **LaunchPad SSO** (our SAML IdP)
> are separate ClassLink products and stay exactly as they are. Only OneSync's
> *provisioning* role moves into this app.

This document is the design + phased rollout. It is grounded in the current
codebase: the app is already the golden record, already emits `v_onesync_source`
(one row per person), already has a working **read-only** Adaxes REST client
(`src/Service/AdaxesService.php`), and the schema already models everything the
provisioner needs (`school.ad_ou`, `school.google_ou`, `person.username` +
`username_locked`, the `ad`/`google` crosswalk systems, and the
`account_sync_status` surface). The schema header even names the eventual owner:
*"the provisioning engine that mints the username and writes it back here."*

---

## 1. What OneSync does that we must replace

| OneSync responsibility | Replace? | Where it lands |
|---|---|---|
| Read one-row-per-person source | already ours | `v_onesync_source` |
| **Mint usernames** (sAMAccountName / login) | **yes** | new `UsernamePolicy` |
| **Correlate** golden records to *existing* AD/Google accounts | **yes** | extend Adaxes search + new Google search |
| **Create / update / disable** in **AD** | **yes** | extend `AdaxesService` (writes) |
| **Create / update / disable** in **Google Workspace** | **yes** | new Google client (greenfield) |
| Threshold safety, dry-run, retry, batching | **yes** | new `ProvisioningEngine` |
| Write CSVs to **Raptor** + **PowerSchool** | **decide** (Phase 4) | optional CSV writers |
| Write back usernames + per-destination status | already ours | engine writes it directly |
| Correlation/workflows/forms/password generators | **dropped** | not used post-cutover |

OneRoster and LaunchPad are **not** in this table — they do not depend on
OneSync.

---

## 2. Target architecture

```
PowerSchool (ODBC) ─┐
NextGen (SFTP CSV) ─┼─► [ import + match ] ─► golden record (person, crosswalk)
intern/sub/contract ┘                                  │
                                                       ▼
                                          ┌─────────────────────────┐
                                          │   ProvisioningEngine     │
                                          │  (desired-state diff)    │
                                          └─────────────────────────┘
                                            │            │
                              ┌─────────────┘            └─────────────┐
                              ▼                                        ▼
                     AdProvisioner                            GoogleProvisioner
                  (Adaxes REST, writes)                  (Admin SDK Directory API)
                              │                                        │
                              └──────────────► account_sync_status ◄───┘
                                               account_sync_event
                                               person.username (minted+locked)
                                               audit_log / lifecycle_event
```

The engine is **declarative**: for each person it computes the *desired* account
in each destination, reads the *actual* account, and emits a minimal action
(`Create | Update | Disable | Enable | NoChange`). Same model OneSync uses, and
it makes every run idempotent and dry-runnable.

---

## 3. Core: the provisioning engine

New namespace `App\Provision` (`src/Provision/`).

### 3.1 Desired state
`DesiredAccount` is built per person from the golden record + reference data:

- `sAMAccountName` / `primaryEmail` ← minted username (see 3.2)
- `displayName`, `givenName`, `sn` ← name fields (preferred name honored)
- `employeeID` ← `employee_id`
- **OU** ← `school.ad_ou` (AD) / `school.google_ou` (Google) for the person's
  `primary_school_id` (data already present; `/reference` surfaces unmapped
  schools that would block provisioning)
- `enabled` ← `status IN ('active','pending')` (mirrors `StatusActive`)
- groups/license target ← derived from `person_type` + school (policy table, 3.5)

### 3.2 Username minting — `UsernamePolicy`
The app does **not** mint today (OneSync owns it). This is the first new
capability and a hard prerequisite for everything else.

- **Format**: configurable (`PROVISION_USERNAME_FORMAT`, e.g.
  `{first}.{last}`, `{f}{last}`, truncation rules, max length 20 for
  sAMAccountName). Default proposed: `{first}.{last}` lowercased, ASCII-folded,
  punctuation stripped.
- **Collision detection** against *three* namespaces: `person.username`
  (UNIQUE `uq_person_username`), live AD (Adaxes search), and Google
  (Directory `users.list`). Append numeric suffix on clash.
- **Immutability**: once assigned, write `person.username` + set
  `username_locked = 1`, stamp `username_assigned_at`, and log a
  `lifecycle_event` of type `username_assigned` (all columns already exist).
  Never re-mint a locked username.
- Email derived as `username@<PROVISION_EMAIL_DOMAIN>` unless policy overrides.

### 3.3 Correlation (link before create)
The riskiest moment is the first live run against a directory **full of existing
accounts**. We must link, not duplicate. We already have the pieces:

- AD: `AdaxesService` search by `sAMAccountName | mail | employeeID` (OR), plus
  the one-time `bin/import_ad_usernames.php` adoption path and `objectGUID`
  backfill into the `ad` crosswalk.
- Google: new search by `employeeID`/`externalId` then `primaryEmail`.

Rule: **if a confident match exists, link it** (record the directory key in
`person_source_id`, system `ad`/`google`) and treat the action as `Update`, never
`Create`. No-match + active person ⇒ `Create`. This reuses the matcher philosophy
already in `src/Matching` (strong key first, never auto-link on weak signal).

### 3.4 Decision model & idempotency
`Decision = {person, destination, op, before, after, reason}`. The engine:
1. loads desired accounts for the in-scope population,
2. loads actual accounts (live read — already implemented for AD),
3. diffs → minimal op,
4. (dry-run) records decisions only; (live) executes then records result to
   `account_sync_status` (upsert per person+destination) + `account_sync_event`.

Re-running changes nothing when already converged (`NoChange`), exactly like the
existing importers.

### 3.5 Safety rails
- **Threshold guard** (`ThresholdGuard`): abort the run if creates/disables
  exceed `PROVISION_MAX_CREATES` / `PROVISION_MAX_DISABLES` unless `--override`.
  Directly mirrors OneSync's threshold feature and guards against a shrunken
  feed mass-disabling staff.
- **Dry-run** is the default for `bin/provision.php`; `--commit` to write.
- **Disable, never delete** — consistent with the app's no-hard-delete rule.
  Leavers are *disabled* (AD `userAccountControl` / Google `suspended=true`).
- **Scope filters**: `--destination=ad|google`, `--school=`, `--person-type=`,
  `--uuid=` so cutover can be piloted on one OU/school.
- **Password handling**: generate a random initial password meeting the domain
  policy; set "must change at next logon" (AD) / `changePasswordAtNextLogin`
  (Google). The password is **never persisted** — delivered out-of-band per
  district process. (Open decision 11.4.)

### 3.6 Group / license policy
Small reference table `provision_target` (or config) mapping
`(person_type, school) → AD groups + Google OU/license`. Google licensing is
the classic footgun — prefer **OU-driven automatic licensing** where possible,
fall back to explicit Licensing API assignment.

---

## 4. AD provisioning (extend Adaxes)

`AdaxesService` is already a robust REST client (token or session→token
handshake, version-tolerant paths, graceful degradation, unit-tested with an
injected HTTP client). It is deliberately read-only. We add **write** methods,
gated behind a separate capability flag so reads can't accidentally write:

- `createUser(DesiredAccount): Result` — POST `…/api/directoryObjects` (User) in
  the target OU.
- `updateUser(guid, changes): Result` — PATCH attributes.
- `setEnabled(guid, bool)`, `setPassword(guid, secret, mustChange)`,
  `addToGroups(guid, dns)`.
- Reuse existing `getObject`/`searchByCriteria` for correlation + post-write
  verification.

New env: `ADAXES_WRITE_ENABLED=false` (default), and a **write-capable** service
account/token distinct from the read-only one already documented. Keep TLS
verification on (`ADAXES_CA_FILE`).

`AdProvisioner` (in `App\Provision\Ad`) orchestrates desired-vs-actual using
these methods and returns normalized `Result` rows the engine records.

---

## 5. Google Workspace provisioning (greenfield)

No Google code exists today (`composer.json` has only `onelogin/php-saml` +
`phpseclib`). New work:

- **Dependency**: `google/apiclient` (Admin SDK Directory + Licensing). Vendored
  via Composer; note the app has a no-Composer fallback autoloader for CLI tools,
  so the Google path must tolerate "library not installed" by reporting
  *unconfigured* (same pattern as Adaxes/VPN services), never fatal.
- **Auth**: a Google Cloud **service account with domain-wide delegation**,
  impersonating an admin, scoped to
  `admin.directory.user`, `admin.directory.group`, and (if used)
  `apps.licensing`. Config: `GOOGLE_CREDENTIALS_FILE`, `GOOGLE_ADMIN_SUBJECT`,
  `GOOGLE_CUSTOMER_ID`, `GOOGLE_DOMAIN`, `GOOGLE_PROVISION_ENABLED=false`.
- **`GoogleDirectoryClient`** (thin, injectable transport like `AdaxesService`):
  `getUser`, `searchUser`, `insertUser`, `patchUser`, `suspend(bool)`,
  `addMember`. `GoogleProvisioner` mirrors `AdProvisioner`.
- **Semantics**: create in `school.google_ou`; leavers ⇒ `suspended=true`
  (never delete); `externalId`/`employeeID` carried for correlation.

---

## 6. Data model changes (one additive migration)

`db/migrations/0009_provisioning.sql` (additive; never edit an applied
migration):

- `provision_run` — one row per engine run: started/finished, mode (dry/commit),
  scope, counts (created/updated/disabled/failed), `triggered_by`.
- `provision_action` — per person+destination decision + outcome, FK to
  `provision_run` (the dry-run ledger and the shadow-diff surface). Capped/pruned
  like `account_sync_event`.
- Optional `provision_target` — the `(person_type, school) → groups/OU/license`
  policy.

`account_sync_status` / `account_sync_event` are **reused** — the engine writes
them directly instead of importing OneSync's export log. `person.username`,
`username_locked`, `username_assigned_at` already exist.

No view change required (`v_onesync_source` stays; it remains the internal
desired-state source and keeps OneSync runnable during parallel-run).

---

## 7. Config / env additions

```
# Engine
PROVISION_ENABLED=false
PROVISION_DRY_RUN=true
PROVISION_MAX_CREATES=50
PROVISION_MAX_DISABLES=25
PROVISION_USERNAME_FORMAT={first}.{last}
PROVISION_EMAIL_DOMAIN=tuscaloosacityschools.com

# AD writes (separate, write-capable credential)
ADAXES_WRITE_ENABLED=false
ADAXES_WRITE_TOKEN=...            # or ADAXES_WRITE_USERNAME/PASSWORD

# Google
GOOGLE_PROVISION_ENABLED=false
GOOGLE_CREDENTIALS_FILE=/etc/idm/google-sa.json
GOOGLE_ADMIN_SUBJECT=svc-provisioning@tuscaloosacityschools.com
GOOGLE_CUSTOMER_ID=my_customer
GOOGLE_DOMAIN=tuscaloosacityschools.com
```

All default to **off** so nothing provisions until explicitly enabled —
consistent with how Adaxes/SAML/VPN are config-gated today.

---

## 8. CLI + UI surface

CLI (matches existing `bin/*` conventions, `--dry-run`/`--file` style):

```sh
php bin/provision.php --dry-run                 # plan only, write provision_action
php bin/provision.php --destination=ad --school=8620 --commit
php bin/provision.php --shadow                  # diff engine plan vs OneSync's reported state
php bin/mint_usernames.php --dry-run            # username policy preview
```

UI: a **Provisioning** admin screen (RBAC `admin`, CSRF, PRG — same as
`/import`): last run, planned vs applied actions, threshold trips, and a
per-person "what would change" panel reusing the existing Adaxes comparison
component. Dashboard KPI cards extend to "pending provisioning / last provision
run / provisioning failures."

---

## 9. Security & least privilege

- New outbound write scopes are the main new risk surface. Both write paths are
  **off by default**, behind their own enable flags and **separate write
  credentials** (the existing Adaxes creds stay read-only).
- DB: the app role (`idm_app`) already has SELECT/INSERT/UPDATE and can write
  `person.username` + status tables; **no new grant** needed for the engine. The
  `onesync_ro` / `idm_writeback` roles can be retired *after* cutover.
- Every action writes `audit_log` + `lifecycle_event` (infrastructure exists).
- Secrets (Google SA JSON, Adaxes write token) live only in env/files outside
  the web root; rotate independently.
- Org PII policy applies: provisioning handles names/emails/employee IDs at
  scale — logs mask values (the Adaxes debug logger already does; Google client
  must follow suit).

---

## 10. Phased rollout

Each phase is independently shippable and leaves OneSync fully functional until
the final cutover.

### Phase 0 — Username minting (prereq)
`UsernamePolicy` + `bin/mint_usernames.php` (dry-run first). Validate generated
names against live AD/Google + the golden record for collisions. **No writes to
directories.** Exit criteria: minted names match the district convention with
zero collisions on a full dry-run.

### Phase 1 — AD writer via Adaxes (highest leverage)
Add write methods to `AdaxesService`; build `AdProvisioner` + engine core +
threshold guard + `provision_action` ledger. Run `--shadow` to diff the engine's
plan against OneSync's `account_sync_status`. **Pilot** `--commit` on **one
school/OU** with low thresholds. Exit criteria: pilot OU fully provisioned by the
app, decisions match OneSync, zero unintended disables.

### Phase 2 — Google Workspace writer
Add `google/apiclient`, service account, `GoogleDirectoryClient` +
`GoogleProvisioner`. Same shadow → pilot → expand sequence. Exit criteria: pilot
population provisioned to Google with correct OU + licensing.

### Phase 3 — Full staff cutover
Expand scope to all staff/faculty for AD + Google. Run app and OneSync in
parallel (OneSync read-only/observe) for one full cycle, then **disable OneSync's
exports**. Keep `v_onesync_source` intact for rollback.

### Phase 4 — Students + remaining destinations
Extend the engine to `v_onesync_student_source` (higher volume). Decide
Raptor/PowerSchool CSV destinations: either add small CSV writers to the engine
or keep them in a trimmed OneSync. Exit criteria: students provisioned; the only
remaining OneSync use (if any) is the CSV destinations we chose not to port.

### Phase 5 — Decommission
Turn off OneSync provisioning entirely. Retire the `onesync_ro` /
`idm_writeback` DB roles and the write-back importers/endpoints. OneRoster +
LaunchPad untouched throughout.

---

## 11. Risks & open decisions

1. **Correlation accuracy on first run** — biggest risk. Mitigated by
   shadow-run + per-OU pilot + thresholds. *Decision:* confidence bar for an
   auto-link (proposed: exact `employeeID` or `objectGUID`; email/username alone
   ⇒ review, not auto-create).
2. **Roster Server feed** — confirm Roster Server pulls PowerSchool **directly**,
   not from a OneSync export, before Phase 5 (one-time console check).
3. **Google licensing model** — OU-auto-license vs explicit Licensing API.
4. **Initial password delivery** — random + must-change vs district SSO-only
   accounts (may need no interactive password). Confirm AD + Google policy.
5. **Raptor / PowerSchool CSV destinations** — port into the engine, or keep a
   minimal OneSync for just these?
6. **Dropped OneSync features** — confirm correlation/workflows/forms/password
   generators are genuinely unused before retiring them.

---

## 12. Rough effort (engineering, order-of-magnitude)

| Phase | Scope | Estimate |
|---|---|---|
| 0 | Username minting + collision | ~1 week |
| 1 | AD writer + engine core + safety + shadow + pilot | ~3–4 weeks |
| 2 | Google client + provisioner | ~3 weeks |
| 3 | Full staff cutover + parallel run | ~1–2 weeks |
| 4 | Students + CSV destinations | ~2 weeks |
| 5 | Decommission + cleanup | ~few days |

The engine core (Phase 1) is where the real value and risk sit — the API calls
are the easy part; correctness (correlation, idempotency, thresholds, dry-run)
is the work.
```
