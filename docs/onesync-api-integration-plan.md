# Plan — Integrating the OneSync API into the web app

How we turn the TCS Identity Master web app from a **passive** OneSync partner
(it exposes a view, OneSync writes back) into one that can also **call OneSync's
own REST API** — to observe OneSync's health and per-person state, and to
trigger/monitor the sync it already drives.

Sources: [`OneSync-API-Reference.md`](../OneSync-API-Reference.md) (human map)
and [`openapi.yaml`](../openapi.yaml) (authoritative: 289 paths, 332 operations,
178 schemas; `servers: /v1p0`, global `bearerAuth` JWT).

---

## 1. Where we are today

Integration is one-directional and file/DB based (Milestone 5):

```
IDM  --SELECT-->  v_onesync_source         OneSync reads one row per person
OneSync mints username, provisions AD/Google/Raptor/PowerSchool
OneSync --write-back-->  /api/onesync/*  (or CSV / direct DB)   IDM records the result
```

So **IDM never talks to OneSync.** We can't, from the app: confirm OneSync is
alive, see whether last night's run actually happened, trigger an off-cycle run
after a feed lands, or look up how OneSync currently sees one person. All of that
lives behind OneSync's `/v1p0` API, which the reference + spec now describe
verb-accurately.

This plan adds an **outbound OneSync API client** and surfaces it in the
existing dashboard / person / import screens, without disturbing the inbound
write-back path (which stays the system of record for usernames + sync status).

---

## 2. What the API buys us (and what we deliberately won't use)

The API has 289 paths spanning auth, sources, destinations, collections,
correlation, users, workflows, logs, workers, and settings. We are a **source**
to OneSync, not its administrator — so we only adopt the slice that serves *this*
app's job (one golden record per person, observably provisioned). Mapping the
relevant endpoints to app value:

| App need | OneSync endpoint(s) | Where it shows |
|---|---|---|
| Is OneSync alive? | `GET settings/health` | dashboard health card |
| Did the nightly run happen / job outcomes | `GET workers/jobCompletionData` | dashboard "OneSync run" indicator |
| List our source + its state | `GET sources`, `GET sources/{id}` | import/settings screen |
| **Trigger an import after a feed lands** | `GET sources/{id}/run` | `/import` button (editor) |
| Pre-run safety | `GET sources/{id}/thresholdCount`, `thresholdStaged/{id}` | shown before a run |
| List destinations + counts | `GET destinations/dropdown`, `GET destinations/exportCounts/{id}` | dashboard / settings |
| **Trigger a sync (dry-run first)** | `GET destinations/{id}/fullsync`, `POST .../calculateexports`, `POST .../commit` | `/import` actions (editor/admin) |
| How does OneSync see *this* person | `GET users` (filtered), `GET users/{id}`, `GET users/links/{id}` | person detail "OneSync" panel |
| Recent OneSync log lines | `GET logs/system` | drill-in from health card |

**Out of scope** (OneSync-admin surface we don't own): `authCredentials`,
`emailCredentials`, `ftpConnections`, password generators, word-hash lists,
workflows/forms, collections authoring, settings/property writes, account CRUD,
licensing, RMQ control. We *read* a few settings (`health`, maybe
`connectToBetaOms`) but never write OneSync config from this app.

---

## 3. Authentication strategy (the key decision)

The reference documents two ways to get a OneSync JWT:

1. **`POST /auth`** with username/password → JWT. Server-to-server friendly.
2. **LaunchPad SSO** (`generateLaunchpadCode` → popup → `verifyLaunchpadCode`).
   This is an *interactive browser* handshake (popup + `postMessage`). It needs a
   human at a ClassLink login and a popup window — wrong fit for a PHP backend
   making scheduled/triggered calls.

**Recommendation:** use a dedicated **OneSync service account** via `POST /auth`.
The app obtains a JWT, caches it (short TTL, in a server-side store), and
re-authenticates on `401`. All `/v1p0` calls send `Authorization: Bearer <jwt>`.
This keeps the integration headless and auditable, and matches how every other
machine credential in this app is handled (`.env`, least privilege).

We do **not** reuse the inbound `ONESYNC_API_KEY` (that's *OneSync → us*); this is
a separate outbound credential. New `.env` keys (mirroring existing conventions):

```ini
# --- OneSync REST API (outbound — the app calls OneSync's /v1p0 API) ---
ONESYNC_API_BASE_URL=        # e.g. https://onesync.example.org   (we append /v1p0)
ONESYNC_API_USER=            # dedicated OneSync service account
ONESYNC_API_PASS=
ONESYNC_API_TIMEOUT=15       # per-request seconds
ONESYNC_SOURCE_ID=           # OneSync source id for OUR IDM feed (for run/lookup)
# Leave ONESYNC_API_BASE_URL blank to DISABLE all outbound calls (feature-flagged off).
```

Blank base URL ⇒ the client is disabled and the UI hides/greys the OneSync
panels — same "fail safe, off by default" posture as `ONESYNC_API_KEY`.

> Token lifetime/refresh nuance lives in the reference (`onesync_auth.py`,
> `bearerAuth`). Confirm token TTL against a live instance during Phase 1 so the
> refresh-on-401 + small cache window is tuned correctly.

---

## 4. Architecture & code shape

Mirror the existing layout (`src/Sync/`, `src/Service/`, thin controllers,
PDO-style services). New namespace **`App\OneSync`**:

```
src/OneSync/
  OneSyncClient.php      # thin HTTP client: auth, bearer header, GET/POST,
                         #   JSON in/out, unwrap ApiResponse, timeouts, retry-on-401.
                         #   cURL via the existing proxy/CA conventions.
  OneSyncAuth.php        # POST /auth -> JWT; cache + refresh; constant-time-ish.
  OneSyncException.php   # typed errors (disabled / auth / http / decode).
src/Service/
  OneSyncStatusService.php   # read-side: health(), lastRun(), sourceState(),
                             #   destinationCounts(), userByUniqueId($uuid), links().
src/Controller/
  OneSyncController.php       # editor/admin actions: runSource(), dryRun(),
                             #   commit(); each CSRF + RBAC gated, Post/Redirect/Get.
```

Conventions to follow (already in the codebase):
- Config via `App\Config::get()`; **never hardcode** hosts/secrets.
- All outbound HTTP honors the agent proxy + CA bundle (see env notes); set a
  short timeout and treat OneSync as untrusted input (validate/escape every field
  before rendering — it feeds an HTML page).
- Responses are "typically wrapped in `ApiResponse`" per the spec — the client
  unwraps a consistent envelope and surfaces `{ok,data,error}` to callers.
- Every state-changing action (run/dry-run/commit) writes to `audit_log` via the
  existing `AuditService`, exactly like review-queue actions.
- Map identity by **`person_uuid` == OneSync `uniqueId`** — already the contract
  in [`onesync-mapping.md`](onesync-mapping.md). Person→OneSync lookups filter
  `users` by our `ONESYNC_SOURCE_ID` and match `uniqueId`.

RBAC mapping (existing capabilities `view` / `edit` / `admin`):
- read-only panels → `view`
- trigger source import / dry-run → `edit`
- `calculateexports` + `commit` (writes to live destinations) → `admin`

---

## 5. Phased delivery

### Phase 1 — Observability (read-only, low risk) — *do first*
Build `OneSyncClient` + `OneSyncAuth` + `OneSyncStatusService`. No write calls.
- Dashboard health card: `settings/health` + `workers/jobCompletionData` →
  green/stale/down, "last OneSync run" timestamp + outcome. Complements the
  existing `syncHealth()` (which only knows when OneSync last *wrote back*).
- Person detail "OneSync" panel: `users` lookup by `uniqueId` → show OneSync's
  view of the user and `users/links/{id}` (destination links), next to the
  existing Provisioning panel. Read-only, gated by `view`.
- Hard fail-safe: base URL blank ⇒ panels hidden; any client error ⇒ panel shows
  "OneSync API unreachable", never breaks the page.
- **Verifies the auth/token model against a live instance** before we let the app
  trigger anything.

### Phase 2 — Triggered actions (writes, gated)
- `/import` gains, for `edit`: **Run OneSync import** (`sources/{id}/run`) after a
  feed import succeeds, with a pre-flight threshold check
  (`thresholdCount` / `thresholdStaged/{id}`) surfaced first.
- For `admin`: **Dry-run sync** (`destinations/{id}/fullsync` dry / 
  `calculateexports`) then **Commit** (`destinations/{id}/commit`) as a deliberate
  two-step (calculate → review counts → commit), each CSRF-protected,
  Post/Redirect/Get, audited.
- Optional: a `bin/onesync_run.php` CLI so cron can chain feed-pull → OneSync run
  (today cron stops at the feed; see [`cron-feed-pull.md`](cron-feed-pull.md)).

### Phase 3 — Deeper visibility (nice-to-have)
- `logs/system` drill-in from the health card (recent OneSync log lines).
- `destinations/exportCounts/{id}` / threshold history on the dashboard.
- Correlation monitor (`correlation/monitor`) if we ever surface match state.

---

## 6. Security & operations
- **Off by default**, feature-flagged on `ONESYNC_API_BASE_URL`; disabled = no
  outbound calls, panels hidden. Same posture as the inbound API's 503.
- Outbound secrets (`ONESYNC_API_USER/PASS`) live only in `.env`, rotated
  independently of the four DB roles and the inbound `ONESYNC_API_KEY`.
- Enforce **HTTPS** to OneSync; verify TLS (never disable). Short timeouts; treat
  every API field as untrusted before rendering.
- Triggering writes (run/commit) is **admin/editor + CSRF + audit** — no
  destination commit without an explicit human two-step.
- No new DB privileges needed: this is HTTP-only; the app keeps connecting as
  `idm_app`. The write-back path and least-privilege roles are unchanged.

## 7. Open questions to confirm against a live instance
1. **Token TTL / refresh** behavior (tune the cache + refresh-on-401 window).
2. **`ApiResponse` envelope** exact shape (the spec says "typically wrapped").
3. Our **source id** and **destination ids** in OneSync (`ONESYNC_SOURCE_ID`, and
   each destination id for run/sync) — discover via `GET sources` / `destinations`.
4. Does `users` accept a filter to fetch one user by our `uniqueId` cheaply, or do
   we page + match? (drives the person-panel query.)
5. Service-account permissions in OneSync (can it run sources / commit, or
   read-only?) — scope the account to exactly the phases we ship.

---

## 8. Recommendation
Ship **Phase 1 only** first: it's read-only, proves the auth model, and
immediately improves the dashboard ("is OneSync actually running?") and person
detail — the two questions the current passive integration can't answer. Gate
Phases 2–3 behind that, since they write to live identity destinations and want a
verified token model + confirmed source/destination ids before we let the app
press the button.
