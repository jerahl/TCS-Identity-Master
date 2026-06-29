# SAML SSO setup & troubleshooting

TCS Identity Master authenticates users against the district IdP over SAML 2.0
(SP-initiated, HTTP-POST ACS). This guide covers configuring it and debugging
login failures. The implementation is a thin wrapper over `onelogin/php-saml`
in `src/Auth/SamlProvider.php`; all config comes from `.env`
(`src/Config.php`) — nothing is hardcoded.

## How the flow works

```
User → /login → "Sign in with district SSO" → /saml/login
     → SamlProvider builds an AuthnRequest, redirects browser to the IdP
IdP  → authenticates the user → HTTP-POST SAMLResponse to /saml/acs
App  → validates the response, maps NameID/email → app_user + role → session
     → redirect to /
```

Relevant routes (`public/index.php`, all public/no-auth):

| Route | Purpose |
|-------|---------|
| `GET /saml/metadata` | SP metadata XML to hand to the IdP admin. |
| `GET /saml/login` | Start SSO (redirect to IdP). |
| `POST /saml/acs` | Assertion Consumer Service — the IdP posts the response here. |
| `GET /logout` | Local session logout (see [Logout](#logout)). |

## Prerequisites

- **HTTPS is live and PHP can see it.** TLS is terminated at nginx; the vhost
  written by `scripts/install-wildcard-cert.sh` passes `fastcgi_param HTTPS on;`
  so `$_SERVER['HTTPS']` is set. `Security::isHttps()` also honors
  `X-Forwarded-Proto: https` if you front the app with a separate reverse proxy.
  This matters: php-saml validates the response **Destination** against
  `APP_BASE_URL`, and the session cookie is `Secure`.
- **Clocks are synchronized.** SAML assertions have a tight validity window;
  `scripts/harden-debian12.sh` enables time sync. Skew between the IdP and this
  host is a common cause of "assertion not yet valid / expired" errors.

## Step 1 — Service Provider (SP) config in `.env`

These describe *this* app to the IdP. **They must use your real external
hostname.** `.env.example` ships `identity.tuscaloosacityschools.com`; if your
host is `identity.tusc.k12.al.us`, change all of them — a host mismatch is the
#1 cause of ACS failures.

```ini
APP_BASE_URL=https://identity.tusc.k12.al.us
SAML_SP_ENTITY_ID=https://identity.tusc.k12.al.us/saml/metadata
SAML_SP_ACS_URL=https://identity.tusc.k12.al.us/saml/acs
SAML_SP_SLS_URL=https://identity.tusc.k12.al.us/saml/sls
```

`APP_BASE_URL` is authoritative for php-saml's URL/destination checks, so it must
exactly match the address browsers use (scheme + host, no trailing slash).

### (Optional) SP signing key + cert

Only needed if your IdP requires **signed** AuthnRequests or **encrypted**
assertions. Generate a self-signed SP keypair and point `.env` at it:

```sh
sudo install -d -m 750 /var/idm/saml
sudo openssl req -x509 -newkey rsa:2048 -nodes -days 1095 \
  -keyout /var/idm/saml/sp.key -out /var/idm/saml/sp.crt \
  -subj "/CN=identity.tusc.k12.al.us"
sudo chmod 640 /var/idm/saml/sp.key /var/idm/saml/sp.crt
sudo chown root:www-data /var/idm/saml/sp.key /var/idm/saml/sp.crt
```

```ini
SAML_SP_PRIVATE_KEY_FILE=/var/idm/saml/sp.key
SAML_SP_CERT_FILE=/var/idm/saml/sp.crt
```

If the files are absent the app omits the SP key from metadata and runs unsigned
— fine for IdPs that don't require request signing.

## Step 2 — Give the IdP admin your SP metadata

With Step 1 in place (you do **not** need IdP config yet):

```sh
curl -sS https://identity.tusc.k12.al.us/saml/metadata
```

It returns an `<md:EntityDescriptor>` advertising your entityID and ACS URL.
Hand this to whoever administers the IdP, or have them point their tooling at
the URL directly. Confirm the ACS URL inside is the correct host before sending.

## Step 3 — Configure the IdP

Register this app as a Relying Party / Service Provider. The specifics vary, but
you always supply the **entityID** and **ACS URL** from your metadata and decide
what claims to release.

**NameID:** format `urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress` —
the user's email. The app keys accounts on NameID/email.

**Attributes/claims** the app reads (first match wins —
see `SamlProvider::extractEmail()` / `extractDisplayName()`):

| Purpose | Accepted claim names |
|---------|----------------------|
| Email | the NameID (if email-format), else `email`, `mail`, `emailAddress`, or the WS-Fed `.../emailaddress` claim |
| Display name | `displayName`, `name`, `cn`, the MS `.../displayname` claim, or `givenName`+`surname`/`sn` |

Platform notes:
- **AD FS** — add a Relying Party Trust; release the email as NameID
  (Email-Address) plus Display-Name / Given-Name / Surname.
- **Entra ID (Azure AD)** — Enterprise App → Single sign-on (SAML); set
  Identifier (entityID) and Reply URL (ACS); the default claim set already
  includes email/displayname.
- **Google Workspace** — custom SAML app; map Primary email to NameID.

The IdP must **sign assertions** — the app sets `wantAssertionsSigned = true`.

## Step 4 — IdP config in `.env`, then restart

From the IdP's federation metadata:

```ini
SAML_IDP_ENTITY_ID=...        # the IdP's entityID / issuer
SAML_IDP_SSO_URL=...          # IdP HTTP-Redirect SSO endpoint
SAML_IDP_SLO_URL=...          # (optional) single logout endpoint
SAML_IDP_X509_CERT=MIID...    # the IdP token-SIGNING cert, base64, ONE LINE
```

> **Certificate gotcha:** `.env` is line-based, so the inline cert must be a
> **single unbroken line** — no `-----BEGIN/END-----`, no wrapping. A multi-line
> paste is silently truncated and login fails with *"Unable to extract public
> key."* If you'd rather keep the cert as a normal multi-line PEM file, leave
> `SAML_IDP_X509_CERT` blank and set `SAML_IDP_X509_CERT_FILE=/var/idm/saml/idp.crt`
> instead.

The login button appears only once all three required IdP values
(`SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, `SAML_IDP_X509_CERT`) are set —
`AuthService::isSamlConfigured()`. Reload php-fpm so new env/file values are
read:

```sh
sudo systemctl reload php8.2-fpm
```

> Once SAML is configured (or `APP_ENV=production`), the non-production **dev
> login** form is automatically disabled (`AuthService::devLoginAllowed()`).

## Step 5 — Bootstrap the first admin (avoid a lockout)

On first SSO login a user is created **`readonly`** unless their email is listed
in `ADMIN_EMAILS`. Admin-only pages (`/users`, `/audit`) then return 403 — so if
nobody is an admin, **nobody can grant roles**. Avoid this by setting bootstrap
admins *before* first login:

```ini
ADMIN_EMAILS=you@tuscaloosacityschools.com,deputy@tuscaloosacityschools.com
```

Already locked out, or prefer the CLI? Grant a role directly:

```sh
php bin/set_role.php --email=you@tuscaloosacityschools.com --role=admin
```

Roles rank `readonly < editor < admin`; capabilities are enforced server-side in
`public/index.php` (not just hidden in the UI).

## Logout

`GET /logout` clears the **local** session only — it does not initiate IdP
Single Logout, even if `SAML_SP_SLS_URL`/`SAML_IDP_SLO_URL` are set. Users may
still have an active session at the IdP. (SLO can be wired via
`SamlProvider::logout()` later if required.)

---

## Troubleshooting SSO login issues

### First: read the real error

The ACS handler shows the user a generic *"Single sign-on failed"* but records
the underlying reason. There are two channels:

**1. The SAML debug log (most reliable).** php-fpm worker `error_log()` output is
often NOT routed to the journal on a stock Debian pool — so "nothing in the log"
usually means the message was written to a discarded stream, not that the app
stayed silent. Capture it to a known file instead:

```ini
# .env — enable, reload php-fpm, reproduce the login, then read the file:
SAML_DEBUG=true
SAML_LOG=/var/idm/saml/saml_debug.log
```

```sh
sudo systemctl reload php8.2-fpm
# attempt the SSO login, then read the latest entry. The file is JSON-LINES
# (one object per line), so pretty-print a single line — `python3 -m json.tool`
# on the whole file errors with "Extra data" because it expects one document:
sudo tail -n 1 /var/idm/saml/saml_debug.log | python3 -m json.tool
# all recent entries (jq streams JSON-lines fine):
sudo tail -n 5 /var/idm/saml/saml_debug.log | jq .
```

Each failure is one JSON line with the `reason` (e.g. *"The response was received
at … instead of …"*, *"Signature validation failed"*, *"The Assertion … is not
signed"*) plus the decoded SAML response. **Turn `SAML_DEBUG` back off afterward**
— the decoded response contains identity attributes (PII).

**2. The PHP error log.** `acs()` also logs `[idm] SAML ACS: <reason>` and
`samlLogin()` logs `[idm] SAML login: <reason>` via `error_log()`. To make those
visible in the journal, ensure the pool captures worker output —
`/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
catch_workers_output = yes
php_admin_value[error_log] = /var/log/php8.2-fpm-www.log
php_admin_flag[log_errors] = on
```

then `sudo systemctl restart php8.2-fpm` and watch `/var/log/php8.2-fpm-www.log`.

A browser SAML tracer extension (to capture the AuthnRequest/Response) and the
IdP's own sign-in logs are the other essential tools. **Do not** enable
`APP_DEBUG=true` in production to chase this — use the logs above.

### Signature policy (assertion vs message) — ClassLink

SAML signatures can be on the **assertion** element or on the enveloping
**response/message**. ClassLink (verified against a live response) signs the
**response**, whose enveloped signature cryptographically covers the assertion
inside it. The app requires the **message** signature by default, which matches:

```ini
SAML_WANT_MESSAGES_SIGNED=true      # response signature required (ClassLink default)
SAML_WANT_ASSERTIONS_SIGNED=false
```

If a future IdP signs the **assertion element** instead, you'll see
*"The Assertion of the Response is not signed and the SP requires it"* in the
SAML debug log — flip the two flags (`MESSAGES=false`, `ASSERTIONS=true`). Always
keep at least one `true`; setting both `false` would accept unsigned responses.
(The defaults live in `SamlProvider::settings()`.)

### Symptom → cause → fix

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `/saml/metadata` shows *"Start tag expected, '<' not found"* | Older bug: SP metadata required IdP config and emitted an invalid body. | Fixed — update to current code; metadata now generates before the IdP is configured. |
| ACS error *"…was received at `http://…/saml/acs` instead of `https://…`"* or destination mismatch | PHP isn't seeing HTTPS, or `APP_BASE_URL`/ACS URL don't match the real host. | Ensure the 443 vhost sets `fastcgi_param HTTPS on;` (or proxy sends `X-Forwarded-Proto: https`); set `APP_BASE_URL` and `SAML_SP_ACS_URL` to the exact external URL. |
| *"Signature validation failed"* / `invalid_response` | Wrong `SAML_IDP_X509_CERT` (not the token-signing cert, or stale after IdP cert rollover). | Paste the IdP's current **token-signing** certificate (base64 body). Re-pull IdP metadata after any cert rotation. |
| *"Assertion not yet valid"* / *"…has expired"* | Clock skew between IdP and this host. | Verify `timedatectl`; ensure NTP is active (harden script enables it). |
| Login succeeds at IdP but app bounces back to `/login` (redirect loop, "not signed in") | Session cookie not stored: `Secure` flag set but request seen as HTTP, or cookie blocked. | Confirm `Security::isHttps()` is true at ACS (HTTPS passed to PHP). The cookie is `SameSite=Lax`; the post-ACS redirect to `/` is a same-site GET, so it should persist — if not, HTTPS detection is the usual culprit. |
| Button says *"SSO is not configured"* | One of `SAML_IDP_ENTITY_ID` / `SAML_IDP_SSO_URL` / `SAML_IDP_X509_CERT` is missing or blank. | Set all three; reload php-fpm. |
| 500 (or now a "couldn't start SSO" flash) when clicking the SSO button | Missing SP config (`SAML_SP_ENTITY_ID`/`SAML_SP_ACS_URL`) or `onelogin/php-saml` not installed. | Set SP vars; run `composer install`. Check the `[idm] SAML login:` log line. |
| Logged in but every page is 403 / can't open `/users` | Account is `readonly` — `ADMIN_EMAILS` wasn't set before first login. | `php bin/set_role.php --email=<you> --role=admin`, then re-login. |
| New account created with a weird username/key, email empty | IdP NameID isn't email-format and no email attribute was released. | Set NameID to Email-Address format, or release an `email`/`mail` claim. |
| *"Unable to extract public key"* | `SAML_IDP_X509_CERT` is malformed — usually a multi-line paste truncated by the line-based `.env`. | Put the cert on one unbroken line (no BEGIN/END), or use `SAML_IDP_X509_CERT_FILE`. |
| `idp_cert_or_fingerprint_not_found_and_required` | No IdP cert configured at all. | Set `SAML_IDP_X509_CERT` (or `SAML_IDP_X509_CERT_FILE`). |

### Diagnostic checklist

```sh
# 1. Config the app actually sees (reads .env + real env):
php -r 'require "src/bootstrap.php"; foreach (["APP_BASE_URL","SAML_SP_ENTITY_ID",
  "SAML_SP_ACS_URL","SAML_IDP_ENTITY_ID","SAML_IDP_SSO_URL"] as $k)
  printf("%-22s %s\n", $k, App\Config::get($k) ?? "(unset)");'

# 2. Metadata renders and advertises the right ACS host:
curl -sS https://identity.tusc.k12.al.us/saml/metadata | grep -o 'Location="[^"]*"'

# 3. HTTPS reaches PHP (should print "on") — run on the server:
#    add a temporary phpinfo or check that the 443 vhost has: fastcgi_param HTTPS on;

# 4. Time is synced:
timedatectl

# 5. Watch the log while you attempt a login:
sudo journalctl -u php8.2-fpm -f | grep -i saml
```

### Related

- TLS/vhost: [`server-hardening.md`](server-hardening.md#enable-https-with-your-wildcard-certificate)
- Config loader semantics (env wins over `.env`): `src/Config.php`
