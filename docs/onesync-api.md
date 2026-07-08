# OneSync Write-Back API

The TCS Identity Master exposes a small HTTP API for OneSync to report the
**two things only it knows for a new user**: the username it minted and the
initial password it set. That's the whole surface — per-destination
provisioning results are **not** pushed here; IDM pulls them straight from
OneSync's own database on a schedule (`bin/import_onesync_db.php`, see
[`cron-feed-pull.md`](cron-feed-pull.md)).

- **Transport:** HTTPS, JSON in / JSON out.
- **Auth:** a shared secret (no session, no CSRF).
- **Identity:** every event references a person by `uniqueId` =
  `v_onesync_source.ID` (the person UUID OneSync read on the way in).
- **Backed by** the same code as the CSV username importer, so the
  username-immutability rules are identical.

---

## Authentication

Set `ONESYNC_API_KEY` in the app environment to a long random value
(`openssl rand -hex 32`). Send it on every request as either:

```
Authorization: Bearer <ONESYNC_API_KEY>
```
or
```
X-API-Key: <ONESYNC_API_KEY>
```

- The comparison is constant-time.
- If `ONESYNC_API_KEY` is **unset/blank, the API is disabled** and every call
  returns `503` — it can't be left accidentally open.
- A missing or wrong token returns `401`.

---

## Endpoints

Base path: `https://<host>/api/onesync`

| Method | Path           | Purpose                                            |
|--------|----------------|----------------------------------------------------|
| `GET`  | `/ping`        | Health check (still requires the token).           |
| `POST` | `/username`    | Record the username/email OneSync minted.          |
| `POST` | `/password`    | Record the initial password OneSync set for a new account. |

`Content-Type: application/json` is expected on the `POST` bodies.

### `GET /api/onesync/ping`

Returns `200` with `{"ok":true,"service":"tcs-identity onesync api"}` when the
token is valid. Use it to validate connectivity + credentials.

### `POST /api/onesync/username`

Sets and **locks** the person's username (and email/UPN if supplied).

| Field      | Required | Notes                                             |
|------------|----------|---------------------------------------------------|
| `uniqueId` | yes      | person UUID (`v_onesync_source.ID`)               |
| `username` | yes      | the username OneSync assigned                     |
| `email`    | no       | primary email                                     |
| `upn`      | no       | userPrincipalName                                 |

```json
{ "uniqueId": "8f3c…", "username": "jdoe", "email": "jdoe@tcs.k12.al.us" }
```

**Outcomes** (returned in `outcome`):

| outcome     | meaning                                                            |
|-------------|-------------------------------------------------------------------|
| `applied`   | username set + locked                                              |
| `noop`      | already set to this value (re-run); idempotent                    |
| `conflict`  | a *different* username is already locked — left unchanged          |
| `skipped`   | blank username                                                     |
| `no_person` | no person matches `uniqueId`                                       |
| `error`     | unexpected failure (logged)                                        |

> Username immutability: once locked, the app never overwrites it with a
> different value. The app never *mints* usernames — this only records OneSync's
> decision.
>
> Activation: applying (or confirming) a locked username flips a `pending`
> person to `active` — a locked username means the account exists. `disabled` /
> `terminated` are left untouched.

### `POST /api/onesync/password`

Records the **initial (temporary) password** OneSync set when it created the
account. The orientation checklist shows it in the "Your account" box (and via
the `{temp_password}` placeholder); until one arrives the checklist falls back
to the "provided by your school/supervisor" wording.

| Field      | Required | Notes                               |
|------------|----------|-------------------------------------|
| `uniqueId` | yes      | person UUID (`v_onesync_source.ID`) |
| `password` | yes      | the initial password OneSync set    |

```json
{ "uniqueId": "8f3c…", "password": "Falcon-Maple-42" }
```

**Outcomes:** `applied` (stored; re-sending **replaces** the stored value —
newest wins), `no_person` (no person matches `uniqueId`), `skipped` (blank
`uniqueId`/`password`), `error`.

**How it's protected:**

- Requires `CREDENTIAL_ENC_KEY` in the app env (64 hex chars,
  `openssl rand -hex 32`). If it's unset the endpoint returns **503** — like
  the API key, it fails closed.
- The password is encrypted with libsodium (authenticated secretbox) **before**
  the database write; the DB only ever stores ciphertext.
- The value is never echoed in the response, never written to `audit_log` /
  `lifecycle_event` (they record only that a password arrived), and the debug
  log **redacts** it (see Debugging below).
- Send it in the JSON body only — never in the URL/query string, which proxies
  and access logs record.

#### One-time backfill (accounts created before this endpoint)

For people OneSync provisioned before the endpoint existed, load their
passwords once from a CSV, by hand, from a trusted shell:

```sh
php bin/backfill_passwords.php --file=/path/to/passwords.csv --dry-run   # verify matches first
php bin/backfill_passwords.php --file=/path/to/passwords.csv --unlink    # import + delete the CSV
```

Header row required; each row needs `password` plus `uniqueId` (preferred) or
`username` (headers are case-insensitive; `uuid`/`id`, `user`, and
`temp_password` aliases are accepted). `AD Login` / `AD Password` are also
recognized, so the HR personnel-action (board approval) spreadsheet works
unmodified — every other column in it is ignored, and rows without both an AD
login and a password (transfers, separations, …) are skipped and reported.
Same encryption, same guardrails, same
audit trail as the API; the script never prints or logs a password. There is
deliberately no scheduled/file-drop variant — the CSV holds plaintext
passwords, so keep it out of feed/backup/synced directories and delete it as
soon as the import succeeds (`--unlink` does this for you).

---

## Single event or batch

Both `POST` endpoints accept **either** a single JSON object **or** a JSON array
of objects (a batch):

```json
[ { "uniqueId": "…", "username": "jdoe",   "email": "jdoe@tcs.k12.al.us" },
  { "uniqueId": "…", "username": "asmith", "email": "asmith@tcs.k12.al.us" } ]
```

---

## Responses & status codes

**Single event** → the result object directly:

```json
{ "ok": true, "uniqueId": "8f3c…", "username": "jdoe", "outcome": "applied", "detail": "username 'jdoe' set + locked" }
```

| HTTP | when                                                        |
|------|-------------------------------------------------------------|
| 200  | processed (`ok:true`)                                        |
| 422  | processed but not ok (e.g. missing required field)          |
| 400  | body wasn't valid JSON (the error says why)                 |
| 401  | missing/invalid token                                       |
| 503  | API disabled (`ONESYNC_API_KEY` not set)                    |

**Batch** → `{ "ok": <all succeeded>, "results": [ … ] }`

| HTTP | when                          |
|------|-------------------------------|
| 200  | every event succeeded         |
| 207  | at least one event failed     |

---

## Examples

```sh
KEY=…   # ONESYNC_API_KEY

# Health check
curl -H "Authorization: Bearer $KEY" https://idm.example.org/api/onesync/ping

# Username minted
curl -X POST https://idm.example.org/api/onesync/username \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"8f3c…","username":"jdoe","email":"jdoe@tcs.k12.al.us"}'

# Initial password for a newly created account
curl -X POST https://idm.example.org/api/onesync/password \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"8f3c…","password":"Falcon-Maple-42"}'

# Batch
curl -X POST https://idm.example.org/api/onesync/username \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '[{"uniqueId":"8f3c…","username":"jdoe","email":"jdoe@tcs.k12.al.us"},
       {"uniqueId":"9a2d…","username":"asmith","email":"asmith@tcs.k12.al.us"}]'
```

---

## Debugging

Set `ONESYNC_API_DEBUG=true` to log every call (method, IP, which header carried
the token + a masked preview, the body, and the response status/outcome) to
`ONESYNC_API_LOG` (default `/var/idm/onesync/api_debug.log`), one JSON line per
request. Run `php bin/api_log_check.php` (as the web user) to verify it's enabled
and the log path is writable. Turn it off once OneSync is working — the body
contains usernames/emails. On the `/password` endpoint the logged body has every
`password` value replaced with `[redacted]` (an unparseable body is withheld
entirely), so the debug log never holds a password.

Common failures the log makes obvious:

| log `status` / `reason`                        | cause                                  |
|------------------------------------------------|----------------------------------------|
| `503` · `ONESYNC_API_KEY not set`              | key not configured on the server       |
| `503` · `CREDENTIAL_ENC_KEY not set`           | `/password` only — encryption key not configured |
| `401` · `token missing or mismatch`            | wrong/missing token (compare `token_preview`, `auth_scheme`) |
| `400` · `not valid JSON …`                     | body isn't a JSON object with named keys |
| `422` + `outcome: no_person`                   | `uniqueId` matches no person           |
| `outcome: conflict`                            | username already locked to another value |

---

## Security & operations

- Runs as the limited **write-back DB role** (can set username/email and the
  encrypted initial password, and write audit rows, nothing else).
- Initial passwords are stored **encrypted at rest** (libsodium secretbox under
  `CREDENTIAL_ENC_KEY`); a DB dump without the app env can't read them. Rotating
  the key orphans previously stored values — OneSync re-sends on the next run.
- Endpoints bypass the session/SAML gate but require the token; no other route is
  reachable without it.
- Enforce HTTPS (the app redirects HTTP→HTTPS in production).
- Rotate `ONESYNC_API_KEY` by updating the app env and OneSync together; an old
  key stops working immediately (no key list).

See also: [`onesync-mapping.md`](onesync-mapping.md) for the read view
(`v_onesync_source`) and the CSV/direct-DB username write-back paths.
Per-destination status + failure messages come from the DB result importer
(`bin/import_onesync_db.php`) — the authoritative provisioning-status path;
scheduling is in [`cron-feed-pull.md`](cron-feed-pull.md).
