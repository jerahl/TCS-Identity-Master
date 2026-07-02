# Code Review & Remediation Plan — July 2026

Diagnostic review of TCS-Identity-Master covering the auth/HTTP core, controllers
and templates, the service layer, the import/sync/matching pipeline, and the
Python VPN monitor plus ops tooling. Every finding below was confirmed by reading
(and in a couple of cases running) the actual code.

**Baseline commit reviewed:** `ce7285d` (main).
**Status:** diagnostic only — no fixes are applied in this document. It is the
tracking plan for the remediation work packages (WP1–WP7).

Overall the codebase is well built: SQL is uniformly parameterized, `ORDER BY` is
whitelisted, CSRF is enforced on every write handler, output is escaped with
`ENT_QUOTES`, UUIDs are cryptographically random, and the staff import already has
the exact safety valve the student import is missing. The serious problems are
concentrated in a few specific places and are high-stakes because this system
disables and renames real student and staff accounts.

---

## Findings

### Critical

**C1 — An empty or short student feed disables every student account, unattended, nightly.**
`src/Import/StudentImporter.php:99-106`. The staff path guards this two ways: it
skips deactivation on a failed run, and `PersonWriter::deactivateMissingSourceIds`
(`:148-155`) *blocks* when more than `NEXTGEN_DROPOUT_MAX_RATIO` of active people
would be deactivated. The student path has **neither guard** — `importRows([])`
deactivates 100% of active students, and `runFromOdbc` passes the ODBC result
straight through with no empty check. Triggers on: PowerSchool maintenance/restore,
a year-rollover enroll-status flip, or a misconfigured `PS_ODBC_SCHEMA` pointing at
an empty clone. Runs as a oneshot systemd timer (`deploy/idm-students.timer`) at
01:30 with no human present.

### High

**H1 — Wrong-person merges from corroboration-free ID matching.**
- *Tier-2 employee-ID auto-match* — `src/Matching/Matcher.php:58-65`,
  `src/Matching/PdoMatchLookup.php:30-38`. `findPersonIdByEmployeeId` runs
  `... WHERE employee_id = :emp LIMIT 1` with no name/DOB corroboration and no
  multiple-hit detection, then auto-links at score 100. NextGen, sub-pool, and
  contractor feeds overload the *same* `person.employee_id` column
  (`ColumnMap.php:89-90,106-107` map `SubID` to both `source_key` and
  `employee_id`), so a colliding `SubID` — or a one-digit typo — merges two people;
  `updateHrFields` (`PersonWriter.php:303`, `HR_REQUIRED`) then renames the victim's
  golden record.
- *Review confirm/reject grafting arbitrary people* — `src/Service/ReviewService.php:247-289,292-327`.
  `confirm()` runs `attachSourceId`/`updateHrFields`/`upsertAssignment` on the
  caller-supplied `$candidatePersonId` **before** any status guard, and
  `loadStaging` never filters on `match_status`. An editor (or a replayed POST) can
  point a resolved staging row at any person ID and overwrite their HR fields;
  `reject()` unconditionally `createPerson`s, so a double-click makes duplicate
  golden records.

**H2 — SFTP path traversal.** `src/Sync/FeedFetcher.php:73` uses
`fnmatch('*.csv', $name)` without `FNM_PATHNAME`, so `*` matches `/`; a malicious or
spoofed server can list `../../../var/www/idm/public/x.csv` and `download()`
(`:76,90,95`) writes it outside the feed directory as the service user.

**H3 — SFTP host-key verification is optional.**
`src/Sync/Sftp/PhpseclibSftpClient.php:43-53` only verifies the host key when
`SFTP_FINGERPRINT` is set; unset means no verification, then it sends `SFTP_PASS`
to whoever answers. Ingested CSVs drive account creation, so a spoofed feed is a
full compromise path.

**H4 — `/dev-login` auth bypass on default config.** `APP_ENV` defaults to
`development` (`src/Http/Security.php:24,40`), and `devLoginAllowed()`
(`src/Service/AuthService.php:84-88`) opens `/dev-login` in any non-production env
before SAML is configured. `devLogin` (`AuthController.php:94-114`) accepts
`role=admin` from POST unrestricted, so any anonymous visitor can self-provision as
admin during the window before an operator sets `APP_ENV=production`.

**H5 — `/people/{id}/reconcile` calls a method that does not exist.** Route wired at
`public/index.php:103` to `$person->reconcile()`; there is no `reconcile()` on
`PersonController` or the base controller (only `FieldMap::reconcileRows`). The
"Use this" golden-record override feature (`templates/people/show.php:238`) 500s for
every editor, every time, after CSRF/permission checks pass.

### Medium

- **Strict CSP silently breaks working features.** `Security.php:48-52` sends
  `script-src 'self'` (no `unsafe-inline`, no nonce), but templates rely on inline
  handlers: People filters use `onchange="this.form.submit()"` with the Apply button
  only inside `<noscript>` (`templates/people/index.php:52-68`) → filters unusable in
  a normal browser; import batch drill-in is `onclick`-only
  (`templates/import/index.php:67`); VPN auto-refresh is an inline `<script>`
  (`templates/vpn/index.php:142`) that never runs.
- **Adaxes wrong-account verification.** `searchByCriteria` does an OR query
  (sAM OR mail OR employeeID) and `firstSearchHit` takes `[0]` with no multi-hit
  check (`src/Service/AdaxesService.php:252-296,522-533`) → the panel and AD-link
  backfill can lock the wrong username/GUID onto a person.
- **`ADAXES_VERIFY_TLS=false` disables peer verification on the credentialed
  channel** (`AdaxesService.php:751-768`) — a "temporary" flip exposes the AD
  service-account password to an on-path attacker. `ADAXES_CA_FILE` already exists.
- **Debug logs are world-readable and full of PII.** `SamlLog`/`ApiLog`/Adaxes
  `debugLog` create files at umask default (0644) despite docblocks claiming 0640,
  and hold decoded SAML assertions, staff PII, and employee IDs. Also a **gzinflate
  decompression bomb** with no size cap in `SamlLog::failure()` (`:50-56`) when
  `SAML_DEBUG=true`.
- **Audit log is tamperable by design** — written with the general app DB role, no
  INSERT-only grant or append-only protection (`AuditService.php:29-32`), and
  `actor VARCHAR(60)` can truncate/abort audits for long emails.
- **DOB parser accepts impossible dates via overflow** (verified by running it):
  `31/12/2020` under `m/d/Y` → `2022-07-12`; `2020-02-31` → `2020-03-02`, plus a
  loose `strtotime` fallback (`src/Import/Normalizer.php:231-245`). Corrupts a
  matching-critical field.
- **`X-Forwarded-Proto` trusted unconditionally** (`Security.php:64`) — if the app
  port is ever directly reachable, an HTTP request with that header skips the HTTPS
  redirect and gets a `secure` cookie over cleartext.
- **Host-header open redirect** in the HTTPS 301 (`Security.php:30-34`) —
  `Location: https://$HTTP_HOST$REQUEST_URI` with no host allowlist.
- **Committed `pseast-vpn-monitor/config.json`** (tracked, not in `.gitignore`)
  holds internal topology and the `alerts.webhook_url` slot — the moment an operator
  pastes a webhook to enable alerts, that write credential lands in git.

### Low

Stale session privileges (role cached at login — `AuthService.php:46-55`);
`GET /logout` CSRF; error-swallowing that shows DB faults as benign states
(`PersonService.php:167-174,278-280`, `ReviewController.php:61-64,80-83`);
first-login TOCTOU race → 500 on concurrent SSO; `PersonService::list()` has no
LIMIT and its `total` count ignores filters; freshness math mixes PHP and DB
timezones; `install-wildcard-cert.sh` passes the key passphrase in argv; systemd
units run unsandboxed; web.py 500s leak exception detail. Dependencies are current
(`onelogin/php-saml 4.3.2`, `phpseclib 3.0.55`, `xmlseclibs 3.1.5`).

---

## Remediation plan

Each work package is intended to be its own commit/PR so they can be reviewed and
rolled back independently. WP1–WP3 are independent and can land in parallel; WP4
then WP5 (overlapping files: `Security.php`, `index.php`).

| Order | Work package | Severity | Effort |
|-------|-------------|----------|--------|
| 1 | Student import safety valve | Critical | S |
| 2 | Matching & review integrity | High | M |
| 3 | SFTP ingestion hardening | High | S |
| 4 | Auth fail-safe defaults | High | M |
| 5 | Broken features (reconcile, CSP/JS) | High/Med | M |
| 6 | Logging & secrets hygiene | Med | M |
| 7 | Correctness & robustness sweep | Med/Low | M |

### WP1 — Student import safety valve (fixes C1)
- `StudentImporter.php:99-106`: before `deactivate($dropouts)`, skip entirely when
  `count($rows) === 0`, and block when
  `activeBefore >= STUDENT_DROPOUT_MIN_ACTIVE && count($dropouts)/activeBefore > STUDENT_DROPOUT_MAX_RATIO`,
  mirroring `PersonWriter::deactivateMissingSourceIds:148-155`. Record a `blocked`
  flag on the batch so the dashboard surfaces it.
- Add `STUDENT_DROPOUT_MIN_ACTIVE` (~50) and `STUDENT_DROPOUT_MAX_RATIO` (~0.10) to
  config + `.env.example`.
- Optionally factor the guard into a shared `DropoutGuard` helper reused by both
  paths so they cannot drift again.
- Tests: `importRows([])` ⇒ `deactivated=0, blocked=true`; over-ratio ⇒ blocked;
  normal feed ⇒ proceeds.

### WP2 — Matching & review integrity (fixes H1)
- `PdoMatchLookup.php:30-38` / `Matcher.php:58-65`: return *all* employee-ID matches
  (drop `LIMIT 1`) plus name fields; auto-link only when exactly one row matches AND
  its normalized name agrees, else route to REVIEW. Namespace the ID keyspace so a
  `SubID` cannot collide with a NextGen employee number (prefix by source in
  `ColumnMap.php:89-90,106-107`).
- `ReviewService.php`: add `AND match_status = 'pending_review'` to `loadStaging`
  (`:331`); in `confirm` (`:255-259`) and `reject` (`:302`), run the guarded
  `UPDATE ... WHERE status='pending'` **first** and abort when `rowCount() === 0`
  before any `PersonWriter` mutation.
- Tests: colliding IDs ⇒ REVIEW; ID+matching name ⇒ AUTO; confirm on resolved row
  ⇒ throws, no writes; double reject ⇒ one person.

### WP3 — SFTP ingestion hardening (fixes H2, H3)
- `FeedFetcher.php:73`: add `FNM_PATHNAME`. Reject remote names containing `/`, `\`,
  `..`; `realpath`-check the destination stays inside `FEED_*_DIR` before download.
- `PhpseclibSftpClient.php:43-53`: fail closed when neither a pinned fingerprint nor
  a known_hosts entry exists; add explicit `SFTP_ALLOW_UNVERIFIED` (default false)
  for lab use only.
- Tests: `InMemorySftpClient` fixture with a `../../etc/x.csv` entry ⇒ rejected,
  nothing written outside sandbox; missing fingerprint ⇒ throws unless override set.

### WP4 — Auth fail-safe defaults (fixes H4 + related medium/low)
- Default `APP_ENV` to `production` (`Security.php:24,40`, `AuthService.php:84`).
- Require explicit `DEV_LOGIN_ENABLED=true` in addition to the non-prod + no-SAML
  check; restrict `provisionDev` role.
- Add `TRUST_FORWARDED_HEADERS` (default false) gating `isHttps()` (`:64`).
- Validate `HTTP_HOST` against `APP_ALLOWED_HOSTS` before the 301 (`:30-34`).
- Refresh role + `is_active` from `app_user` per request (`AuthService.php:46-55`).
- Convert `/logout` to POST + CSRF (`index.php:83`).

### WP5 — Broken features (fixes H5 + CSP medium)
- Implement `PersonController::reconcile(array $params)` matching the form fields in
  `templates/people/show.php`, applying assignments via the existing
  `FieldMap`/`PersonWriter` path, then audit + redirect.
- Add a route→handler smoke test asserting `method_exists` for every registered
  route.
- Create `public/assets/js/app.js` (loaded via `asset()`) with delegated handlers
  for auto-submit selects, clickable rows, and the VPN refresh; remove
  `<noscript>`-only controls. Keep the CSP as the secure end state.

### WP6 — Logging & secrets hygiene (fixes medium cluster)
- One hardened log writer replacing the three copies (`SamlLog.php:74-78`,
  `ApiLog.php:44-49`, `AdaxesService::debugLog:725-732`): create + `chmod 0640`,
  cap entry size.
- Cap `gzinflate` length in `SamlLog::failure():50-56`.
- `git rm --cached pseast-vpn-monitor/config.json`, add to `.gitignore`, keep
  `config.example.json`. (History grep is clean — no rewrite needed.)
- Give `AuditService` an INSERT-only DB role; widen `actor` to match
  `app_user.email` and store a stable `user_id`.

### WP7 — Correctness & robustness sweep (medium/low)
- `Normalizer.php:231-245`: round-trip validate dates, drop `strtotime` fallback for
  DOB.
- `AdaxesService.php:252-296,522-533`: refuse to auto-link on >1 search hit; remove
  the `ADAXES_VERIFY_TLS=false` switch (`:751-768`).
- Stop swallowing DB faults as benign (`PersonService.php:167-174,278-280`,
  `ReviewController.php:61-64,80-83`).
- Add LIMIT/OFFSET and filter-aware `total` to `PersonService::list():97-111`; clamp
  `page` in `AuditController.php:24-26`.
- Normalize timestamps to UTC (`Freshness.php:29-34`,
  `SyncStatusImporter.php:195-203`).
- Wrap the status upsert + event insert in one transaction
  (`SyncStatusImporter::applyEvent:134-164`, `OneSyncResultImporter:228-230`).
- Assert header/column-count in `Importer.php:135-138`.
- Ops: `-passin env:` in `install-wildcard-cert.sh:72-83,137`; add
  `NoNewPrivileges/ProtectSystem=strict/PrivateTmp` + a dedicated user to
  `deploy/idm-*.service`; generic 500s in `web.py:124,137`.

### Cross-cutting
- Centralize CSRF in the dispatch pipeline (in `$guard`/Router) so every POST is
  checked once instead of each handler remembering `Csrf::check()`.
- The route-table smoke test (WP5) is cheap and prevents the whole class of
  dead-wiring bugs that produced H5.
