# SAML SSO with ClassLink as the IdP

How to wire TCS Identity Master (the **SP**, service provider) to **ClassLink
LaunchPad** (the **IdP**, identity provider). The app uses `onelogin/php-saml`;
all values come from `.env` (`SAML_*`), nothing is hardcoded.

End state: staff sign in either by clicking the **TCS Identity Master** tile in
ClassLink LaunchPad (IdP-initiated) or via the **Sign in with ClassLink** button
on `/login` (SP-initiated). Both land on the ACS, which maps the assertion to an
`app_user` + role (first login = `readonly`, or `admin` if the email is in
`ADMIN_EMAILS`).

---

## 0. Endpoints (our SP)

| What | URL |
|---|---|
| SP metadata (give to ClassLink) | `https://identity.tuscaloosacityschools.com/saml/metadata` |
| ACS (assertion consumer) | `https://identity.tuscaloosacityschools.com/saml/acs` |
| SLS (single logout) | `https://identity.tuscaloosacityschools.com/saml/sls` |
| SP Entity ID | `https://identity.tuscaloosacityschools.com/saml/metadata` |

These are the `SAML_SP_*` values in `.env`. HTTPS is required.

---

## 1. Generate the SP key + certificate (once)

ClassLink expects the SP to have a signing/encryption cert. Generate a
self-signed pair, store it **outside the web root**, and point `.env` at it:

```sh
sudo install -d -m 0750 -o www-data -g www-data /var/idm/saml
sudo openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /var/idm/saml/sp.key -out /var/idm/saml/sp.crt \
  -days 1095 -subj "/CN=identity.tuscaloosacityschools.com"
sudo chown www-data:www-data /var/idm/saml/sp.{key,crt}
sudo chmod 0640 /var/idm/saml/sp.{key,crt}
```

```ini
SAML_SP_PRIVATE_KEY_FILE=/var/idm/saml/sp.key
SAML_SP_CERT_FILE=/var/idm/saml/sp.crt
```

(Renew before the 1095-day expiry; re-hand the cert to ClassLink when you do.)

---

## 2. Create the SAML connector in ClassLink

In the **ClassLink Management Console (CMC)**:

1. **Single Sign-On → SAML Console → Add New** (or copy an existing connector).
2. Set it as a **SAML** SSO app named e.g. *TCS Identity Master*.
3. Provide our SP details (paste from `/saml/metadata`, or enter by hand):
   - **ACS / Reply URL:** `https://identity.tuscaloosacityschools.com/saml/acs`
   - **Entity ID / Audience:** `https://identity.tuscaloosacityschools.com/saml/metadata`
   - **SP certificate:** the contents of `/var/idm/saml/sp.crt`.
4. **NameID:** Format `emailAddress`, Value **Email**. (The app keys users by an
   email NameID; it also reads an `email` attribute as a fallback.)

---

## 3. Attribute mapping (the part unique to ClassLink)

ClassLink has **no fixed attribute names** — you map ClassLink source fields to
the attribute names *our SP asks for*. Our metadata advertises these as
`<md:RequestedAttribute>`, so they show up as the ones to fill in. Map:

| ClassLink source field | Attribute name to use (our SP) | Required |
|---|---|---|
| Email | `email` | yes |
| Given Name | `firstName` | no |
| Family Name | `lastName` | no |
| Display Name | `displayName` | no (built from first+last if absent) |

The names on the right must match **exactly** what the SP requests. They default
to `email` / `firstName` / `lastName` / `displayName`. If your ClassLink console
forces different names, set the matching `SAML_ATTR_*` in `.env` (below) so the
SP requests *and* reads those names — keep the two in sync.

> The app is tolerant: email can arrive as the NameID or an `email`/`mail`
> attribute; the display name falls back to `firstName`+`lastName` (and common
> variants like `givenName`/`surname`), all matched case-insensitively. But
> mapping the four above cleanly is the supported path.

---

## 4. Copy ClassLink's IdP values into `.env`

From the connector's **IdP metadata** (CMC shows a metadata URL / the IdP
Issuer, SSO URL, and certificate):

```ini
SAML_IDP_ENTITY_ID=<IdP Issuer / Entity ID>
SAML_IDP_SSO_URL=<IdP SingleSignOnService redirect URL>
SAML_IDP_SLO_URL=<IdP SingleLogOutService URL, optional>
SAML_IDP_X509_CERT=<signing cert: base64 body only, no -----BEGIN----- / newlines>

SAML_ATTR_EMAIL=email
SAML_ATTR_FIRST_NAME=firstName
SAML_ATTR_LAST_NAME=lastName
SAML_ATTR_DISPLAY_NAME=displayName
```

SSO turns on automatically once `SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, and
`SAML_IDP_X509_CERT` are all set (`AuthService::isSamlConfigured()`), which also
**disables dev login**. The login page then shows **Sign in with ClassLink**.

---

## 5. First admin + roles

On first SSO login a user is created `readonly`. Grant the first admin from a
trusted shell (or list bootstrap admins in `ADMIN_EMAILS`):

```sh
php bin/set_role.php --email=you@tuscaloosacityschools.com --role=admin
```

RBAC is enforced server-side on every route; roles thereafter are managed at
`/users` by an admin.

---

## 6. Verify

1. `curl -s https://identity.tuscaloosacityschools.com/saml/metadata` → valid SP
   metadata listing the four requested attributes.
2. Click the tile in ClassLink LaunchPad **and** the `/login` button — both reach
   the dashboard.
3. Confirm the new `app_user` row has the right email + display name, and that a
   `login` row was written to `audit_log`.

### Troubleshooting
- **"SAML error / invalid_response"** — IdP cert mismatch: re-copy
  `SAML_IDP_X509_CERT` (base64 body only). Clock skew (`strict` mode) also trips
  this; keep the server's time in sync.
- **Email is blank / user keyed by an opaque id** — NameID isn't email and no
  `email` attribute was mapped. Set NameID Value = Email, or map the `email`
  attribute.
- **No display name** — map `displayName`, or `firstName`+`lastName`.
- **Tile works but button doesn't (or vice-versa)** — the ACS handles both
  IdP- and SP-initiated; a one-sided failure is usually the ACS URL or Entity ID
  not matching between ClassLink and `.env`.
