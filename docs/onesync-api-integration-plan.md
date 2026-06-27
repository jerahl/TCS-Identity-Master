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

## 3. Authentication strategy — interactive SSO only (hard constraint)

**OneSync no longer supports `POST /auth` (username/password) accounts.** The
*only* way to get a OneSync JWT is the **interactive LaunchPad SSO** handshake
(`generateLaunchpadCode` → ClassLink popup → `verifyLaunchpadCode` → `loginToken`).
This is not a tuning detail — it dictates the whole shape of the feature:

- **No headless / service-account token exists.** There is no credential the
  server can hold to authenticate on its own.
- **The app can call OneSync only while a real user is logged in *and* has
  completed the SSO popup.** The token is *that user's* OneSync JWT.
- **No background, cron, or scheduled OneSync calls.** Anything that must run
  unattended (e.g. "trigger OneSync after the nightly feed lands") is **not
  possible** through the API — it would need a person present to authenticate.
  This removes the cron-chaining idea from §5 entirely.
- **Calls act as the signed-in user** — OneSync applies *its own* per-user
  permissions and audit on top of our RBAC, and the token expires on OneSync's
  schedule (re-auth = re-run the popup; the flow has no silent refresh token).

### Where the token lives — backend-mediated handshake

We keep the JWT **server-side in the user's PHP session**, never in page JS. The
browser only runs the popup; our backend mints the correlation code and does the
final exchange (the `loginToken` never touches client JavaScript):

```
1. User clicks "Connect to OneSync".
2. Backend: GET /v1p0/auth/generateLaunchpadCode  (+ settings/connectToBetaOms)
   → stash {token, serverGuid, beta} in $_SESSION; return the LaunchPad popup URL.
3. Browser opens the popup to ClassLink LaunchPad; user signs in there.
   Browser registers a `message` listener → captures `imagePath|||tenant|||email`.
4. On popup close, browser POSTs {email, imagePath} to our backend (CSRF-protected).
5. Backend: GET /v1p0/auth/verifyLaunchpadCode/<token>?email=&imagePath=
   → loginToken. Store loginToken (+ expiry, username) in $_SESSION.  [backend MAY
     poll verify on an interval w/ timeout instead of relying on popup-close.]
6. OneSync panels/actions now work until the token expires or the session ends.
```

Because steps 2 and 5 are **server-to-server**, there's no CORS on the API calls
and no JWT in the browser. CORS/postMessage only matter for the popup itself
(ClassLink-side, fixed client IDs + OMS redirect — see the reference, baked-in).

New `.env` keys (the client IDs / OMS redirect URIs are fixed ClassLink values
baked into the flow — we don't register our own):

```ini
# --- OneSync REST API (outbound — the app calls OneSync's /v1p0 API) ---
ONESYNC_API_BASE_URL=        # e.g. https://onesync.example.org   (we append /v1p0)
ONESYNC_API_TIMEOUT=15       # per-request seconds
ONESYNC_SOURCE_ID=           # OneSync source id for OUR IDM feed (for run/lookup)
# Leave ONESYNC_API_BASE_URL blank to DISABLE all outbound calls (feature-flagged off).
```

No `ONESYNC_API_USER/PASS` — there is no service account. Blank base URL ⇒ the
client is disabled and the UI hides the OneSync panels (fail-safe, off by default).

> Confirm **token TTL** against a live instance: it bounds how long a "connected"
> session can act before the user must re-run the popup. Also confirm whether
> `verifyLaunchpadCode` can be re-driven for silent re-auth or always needs the
> popup (assume the latter).

### Consequence for the dashboard health card
A read-only status like "is OneSync running?" now requires *a* connected user —
there is no app-wide token. To still show something useful to everyone, **cache
the last non-sensitive read** (health, last-run timestamp) server-side with a
"as of HH:MM, via <user>" stamp, refreshed whenever any connected user loads the
page. Uncached + nobody connected ⇒ the card invites the user to Connect.

---

## 4. Architecture & code shape

Mirror the existing layout (`src/Sync/`, `src/Service/`, thin controllers,
PDO-style services). New namespace **`App\OneSync`**:

```
src/OneSync/
  OneSyncClient.php      # thin HTTP client: bearer header from the SESSION token,
                         #   GET/POST, JSON in/out, unwrap ApiResponse, timeouts.
                         #   cURL via the existing proxy/CA conventions. On 401/
                         #   expired token: clear it + signal "reconnect needed".
  OneSyncSession.php     # the SSO handshake: generateLaunchpadCode + popup URL,
                         #   verifyLaunchpadCode exchange, store/read/clear the JWT
                         #   (+ expiry, username) in $_SESSION. isConnected().
  OneSyncException.php   # typed errors (disabled / not-connected / http / decode).
src/Service/
  OneSyncStatusService.php   # read-side: health(), lastRun(), sourceState(),
                             #   destinationCounts(), userByUniqueId($uuid), links().
                             #   caches non-sensitive reads server-side (dashboard).
src/Controller/
  OneSyncController.php       # connect()/callback()/disconnect() for the handshake;
                             #   editor/admin actions runSource()/dryRun()/commit();
                             #   each CSRF + RBAC gated, Post/Redirect/Get.
```

A small **browser script** (in the relevant templates only) drives the popup,
captures the `postMessage`, and POSTs `{email,imagePath}` back to `callback()`.
This is the one client-side piece; the token stays server-side.

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

### Phase 0 — The "Connect to OneSync" handshake — *prerequisite for everything*
Build `OneSyncSession` + `OneSyncClient` + the connect/callback/disconnect routes
and the popup browser script. Until this works, no other phase has a token. A
connection indicator (connected as <user>, expires in N min) lives in the header.

### Phase 1 — Observability (read-only, low risk)
Add `OneSyncStatusService`. No write calls.
- Dashboard health card: `settings/health` + `workers/jobCompletionData` →
  green/stale/down, "last OneSync run" timestamp + outcome. Complements the
  existing `syncHealth()` (which only knows when OneSync last *wrote back*).
  Falls back to the **cached last read** when the current user isn't connected
  (see §3) so the card is useful app-wide, not only to connected users.
- Person detail "OneSync" panel: `users` lookup by `uniqueId` → show OneSync's
  view of the user and `users/links/{id}` (destination links), next to the
  existing Provisioning panel. Read-only, gated by `view` + a live token.
- Hard fail-safe: base URL blank ⇒ panels hidden; not connected ⇒ "Connect to
  OneSync"; token expired/`401` ⇒ prompt reconnect; never breaks the page.

### Phase 2 — Triggered actions (writes, gated, **foreground only**)
All triggers run **in the user's connected session** — there is no unattended
path (see §3). So these are buttons a logged-in, connected editor/admin presses,
not scheduled jobs:
- For `edit`: **Run OneSync import** (`sources/{id}/run`) — typically right after
  a feed import succeeds — with a pre-flight threshold check
  (`thresholdCount` / `thresholdStaged/{id}`) surfaced first.
- For `admin`: **Dry-run sync** (`destinations/{id}/fullsync` dry /
  `calculateexports`) then **Commit** (`destinations/{id}/commit`) as a deliberate
  two-step (calculate → review counts → commit), each CSRF-protected,
  Post/Redirect/Get, audited.
- **Not possible:** chaining OneSync to the nightly cron feed-pull — no headless
  token. If unattended runs are required, that stays inside OneSync's own
  scheduler (CronWorker), driven by config we don't manage from here.

### Phase 3 — Deeper visibility (nice-to-have)
- `logs/system` drill-in from the health card (recent OneSync log lines).
- `destinations/exportCounts/{id}` / threshold history on the dashboard.
- Correlation monitor (`correlation/monitor`) if we ever surface match state.

---

## 6. Security & operations
- **Off by default**, feature-flagged on `ONESYNC_API_BASE_URL`; disabled = no
  outbound calls, panels hidden. Same posture as the inbound API's 503.
- **No outbound secret to store** — there is no service account. The only
  credential is the per-user JWT, held in the server-side session and cleared on
  logout/expiry. Cached non-sensitive reads (§3) hold health/last-run timestamps
  only — never per-person PII.
- The app **acts as the signed-in user** against OneSync: OneSync's own per-user
  permissions + audit apply on top of our RBAC. A user who can't run a source in
  OneSync still can't, even if our UI shows the button.
- Enforce **HTTPS** to OneSync; verify TLS (never disable). Short timeouts; treat
  every API field as untrusted before rendering.
- Triggering writes (run/commit) is **admin/editor + CSRF + audit + a live
  token** — no destination commit without an explicit human two-step.
- No new DB privileges needed: this is HTTP-only; the app keeps connecting as
  `idm_app`. The write-back path and least-privilege roles are unchanged.

## 7. Open questions to confirm against a live instance
1. **Token TTL** + whether `verifyLaunchpadCode` can be re-driven for silent
   re-auth or always needs the popup (assume popup) — bounds the connected window.
2. **CORS / popup origin:** when our app and OneSync are different origins,
   confirm the popup + OMS `postMessage` reach our opener window, and that the
   backend can call `generate`/`verifyLaunchpadCode` server-to-server. Confirm
   `serverGuid` handling for our tenant.
3. **`ApiResponse` envelope** exact shape (the spec says "typically wrapped").
4. Our **source id** and **destination ids** in OneSync (`ONESYNC_SOURCE_ID`, and
   each destination id for run/sync) — discover via `GET sources` / `destinations`.
5. Does `users` accept a filter to fetch one user by our `uniqueId` cheaply, or do
   we page + match? (drives the person-panel query.)

---

## 8. Recommendation
Ship **Phase 0 + Phase 1** first: the SSO handshake is unavoidable groundwork,
and read-only observability proves the token model while immediately answering
the two questions the passive integration can't ("is OneSync running?" / "how
does OneSync see this person?"). Gate Phase 2 behind that, since it writes to live
destinations and wants a verified token window + confirmed source/destination
ids. Set expectations up front that **everything is foreground-only** — there is
no way to drive OneSync from cron through this API, so unattended runs must stay
in OneSync's own scheduler.
