# OneSync Write-Back API

The TCS Identity Master exposes a small HTTP API for OneSync to report what it
did, **per event** — a username it minted, or the result of provisioning an
account to a destination (AD, Google, Raptor, PowerSchool). It's an alternative
to the CSV write-back importers: same effect, same guardrails, but real-time.

- **Transport:** HTTPS, JSON in / JSON out.
- **Auth:** a shared secret (no session, no CSRF).
- **Identity:** every event references a person by `uniqueId` =
  `v_onesync_source.uniqueId` (the person UUID OneSync read on the way in).
- **Backed by** the same code as the CSV importers, so the username-immutability
  and one-row-per-(person,destination) rules are identical.

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
| `POST` | `/sync-status` | Record a per-destination provisioning result.      |

`Content-Type: application/json` is expected on the `POST` bodies.

### `GET /api/onesync/ping`

Returns `200` with `{"ok":true,"service":"tcs-identity onesync api"}` when the
token is valid. Use it to validate connectivity + credentials.

### `POST /api/onesync/username`

Sets and **locks** the person's username (and email/UPN if supplied).

| Field      | Required | Notes                                             |
|------------|----------|---------------------------------------------------|
| `uniqueId` | yes      | person UUID (`v_onesync_source.uniqueId`)         |
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

### `POST /api/onesync/sync-status`

Upserts the current provisioning status for one `(person, destination)` and
appends to the capped event history.

| Field         | Required | Notes                                                        |
|---------------|----------|--------------------------------------------------------------|
| `uniqueId`    | yes      | person UUID                                                  |
| `destination` | yes      | free text, e.g. `Active Directory`, `Google`, `Raptor`, `PowerSchool` |
| `action`      | yes      | `Add` / `Edit` / `Disable` / `Enable` / `NoChange` / `New` (synonyms accepted: create, update, modify, none…) |
| `status`      | yes      | `Success` / `Fail` / `Skipped` / `New` (synonyms: succeeded, ok, failed, error, skip…). `actionStatus` is also accepted as the key. |
| `message`     | no       | failure detail (truncated to 1000 chars)                    |
| `timestamp`   | no       | when OneSync ran it (any parseable date/time)               |
| `destType`    | no       | override the derived type (`ActiveDirectory` / `GSuite` / `CSV`) |
| `username`    | no       | informational                                               |

```json
{ "uniqueId": "8f3c…", "destination": "Active Directory",
  "action": "Add", "status": "Success", "timestamp": "2026-06-26 12:16:45" }
```

`destType` is derived from `destination` when omitted (Google→`GSuite`,
Active Directory/Azure/Entra→`ActiveDirectory`, Raptor/PowerSchool/CSV→`CSV`).

**Outcomes:** `upserted` (status recorded), `no_person` (still recorded, but no
golden record matched `uniqueId`), `skipped` (missing `uniqueId`/`destination`),
`error`.

---

## Single event or batch

Both `POST` endpoints accept **either** a single JSON object **or** a JSON array
of objects (a batch):

```json
[ { "uniqueId": "…", "destination": "Active Directory", "action": "Add", "status": "Success" },
  { "uniqueId": "…", "destination": "Google",           "action": "Add", "status": "Fail", "message": "license unavailable" } ]
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

# Per-destination result
curl -X POST https://idm.example.org/api/onesync/sync-status \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"uniqueId":"8f3c…","destination":"Active Directory","action":"Add","status":"Success"}'

# Batch
curl -X POST https://idm.example.org/api/onesync/sync-status \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '[{"uniqueId":"8f3c…","destination":"Google","action":"Add","status":"Success"},
       {"uniqueId":"9a2d…","destination":"Raptor","action":"Add","status":"Fail","message":"timeout"}]'
```

---

## Debugging

Set `ONESYNC_API_DEBUG=true` to log every call (method, IP, which header carried
the token + a masked preview, the body, and the response status/outcome) to
`ONESYNC_API_LOG` (default `/var/idm/onesync/api_debug.log`), one JSON line per
request. Run `php bin/api_log_check.php` (as the web user) to verify it's enabled
and the log path is writable. Turn it off once OneSync is working — the body
contains usernames/emails.

Common failures the log makes obvious:

| log `status` / `reason`                        | cause                                  |
|------------------------------------------------|----------------------------------------|
| `503` · `ONESYNC_API_KEY not set`              | key not configured on the server       |
| `401` · `token missing or mismatch`            | wrong/missing token (compare `token_preview`, `auth_scheme`) |
| `400` · `not valid JSON …`                     | body isn't a JSON object with named keys |
| `422` + `outcome: no_person`                   | `uniqueId` matches no person           |
| `outcome: conflict`                            | username already locked to another value |

---

## Security & operations

- Runs as the limited **write-back DB role** (can set username/email and write
  sync status + audit, nothing else).
- Endpoints bypass the session/SAML gate but require the token; no other route is
  reachable without it.
- Enforce HTTPS (the app redirects HTTP→HTTPS in production).
- Rotate `ONESYNC_API_KEY` by updating the app env and OneSync together; an old
  key stops working immediately (no key list).

See also: [`onesync-mapping.md`](onesync-mapping.md) for the read view
(`v_onesync_source`) and the CSV/direct-DB write-back paths.
