# Server hardening (Debian 12, internet-facing)

`scripts/harden-debian12.sh` hardens a production host that runs the IDM stack
(nginx + php-fpm serving `public/`, MariaDB on `127.0.0.1`, outbound SFTP feed
pulls, SAML SSO, systemd timers). Because the box holds student/staff PII and is
reachable from the internet, run this **after** the app is provisioned and
**before** you point DNS at it.

```sh
# Provision the app first (see scripts/setup-dev-debian12.sh / your deploy), then:
sudo bash scripts/harden-debian12.sh
```

The script is **idempotent** — safe to re-run any time. Re-run it after you
change a tunable.

## Set up SSH key auth first (MobaXterm)

The hardening script can disable password login — so set up **key authentication
from your MobaXterm client first**, or you'll lock yourself out. Two ways:

### A. Scripted (recommended) — MobaXterm local terminal

MobaXterm bundles OpenSSH, so run the helper in its **Local terminal** (the bash
shell on the Start tab), *not* on the server:

```sh
bash scripts/setup-key-auth-mobaxterm.sh deploy@your.server 22
```

It generates `~/.ssh/id_ed25519` (if you don't have one), copies the public key
to the server's `authorized_keys` (one password prompt), verifies key-only login
works, and prints the MobaXterm session settings to use the key. Idempotent.

### B. GUI — MobaKeyGen + server-side installer

1. **MobaXterm → Tools → MobaKeyGen** → *Generate* an Ed25519 key → save the
   private key, and copy the **"Public key for pasting into OpenSSH
   authorized_keys file"** box (the single `ssh-ed25519 AAAA… comment` line).
2. On the server, paste it in:
   ```sh
   bash scripts/install-authorized-key.sh "ssh-ed25519 AAAA…== moba@pc"
   # or for another user, as root:
   sudo bash scripts/install-authorized-key.sh --user deploy "ssh-ed25519 AAAA…=="
   ```
   It validates the key, creates `~/.ssh` (700) and `authorized_keys` (600) with
   correct ownership, and won't duplicate an existing entry.
3. In MobaXterm: **Session → SSH → Advanced SSH settings → Use private key** and
   select your key. MobaXterm reads OpenSSH keys directly (no `.ppk` needed).

Either way, **test a new connection with the key**, then run the hardening
script with `DISABLE_PASSWORD_AUTH=1` (and `SSH_PORT=…` if you changed it).

## ⚠️ Don't lock yourself out

You reach this server over SSH. Before running, make sure you can log in with an
**SSH key**:

- If the admin user has no `~/.ssh/authorized_keys`, the script leaves
  `PasswordAuthentication` **on** (with a warning) so you stay reachable. Add a
  key, then re-run with `DISABLE_PASSWORD_AUTH=1` for key-only auth.
- If you set `SSH_PORT`, the firewall and fail2ban are updated to match, but you
  must reconnect on the new port. **Keep your current session open and open a
  second session to test before closing the first.**

## What it does

| Area | Action |
|------|--------|
| Patching | Applies security updates; enables `unattended-upgrades` (auto-reboot 03:30 if a kernel update requires it). |
| SSH | Drop-in `99-hardening.conf`: root login off, key-only auth (when keys exist), no forwarding, `MaxAuthTries 3`, modern KEX/ciphers/MACs, `AllowUsers <admin>`. Validates with `sshd -t` before reloading. |
| Firewall | `ufw` default-deny inbound; allows SSH (rate-limited) + 80/443; outbound left open (SFTP/DB/SAML/composer need it). |
| fail2ban | `sshd` jail — 3 failed tries → 1h ban. |
| Kernel | `sysctl` network + memory hardening (rp_filter, syncookies, no redirects/source-routing, `kptr_restrict`, ASLR, protected links). Core dumps disabled. |
| PHP | `expose_php off`, errors not displayed, dangerous funcs (`exec`, `shell_exec`, `system`, …) disabled, secure session cookies. |
| nginx | `server_tokens off`, body-size cap, TLS 1.2/1.3 only, and a `snippets/security-headers.conf` to include in your HTTPS server block. |
| Accounts | `umask 027`, password quality (`minlen 14`, mixed classes), `login.defs` aging + SHA rounds. |
| Logging | Persistent, size-bounded `journald`; optional `auditd` rules on `passwd`/`shadow`/`sudoers`/`sshd`. |
| Secrets | `.env` → `640 root:www-data`; tightens `/var/idm/{saml,sftp,onesync}` if present. |
| Optional | AppArmor enabled; AIDE file-integrity baseline (`INSTALL_AIDE=1`); Lynis audit (`RUN_LYNIS=1`). |

## Tunables (environment variables)

| Variable | Default | Notes |
|----------|---------|-------|
| `SSH_PORT` | `22` | Firewall + fail2ban follow this. |
| `ADMIN_USER` | `$SUDO_USER` | Restricts SSH via `AllowUsers`. |
| `DISABLE_PASSWORD_AUTH` | `auto` | `auto` = key-only only if keys exist; `1` forces; `0` keeps passwords. |
| `DISABLE_ROOT_LOGIN` | `1` | `PermitRootLogin no`. |
| `ALLOW_HTTP` / `ALLOW_HTTPS` | `1` / `1` | Open 80 / 443. |
| `RUN_UPGRADE` | `1` | Apply pending upgrades now. |
| `INSTALL_FAIL2BAN` / `INSTALL_AUDITD` | `1` / `1` | |
| `INSTALL_AIDE` | `0` | Slow init; off by default. |
| `RUN_LYNIS` | `0` | Run a Lynis audit at the end. |
| `HARDEN_PHP` / `HARDEN_NGINX` | `1` / `1` | |
| `PHP_VERSION` | `8.2` | Debian 12 default. |
| `MAX_BODY_SIZE` | `25m` | nginx `client_max_body_size` (keep ≥ `UPLOAD_MAX_BYTES`). |
| `TIMEZONE` | `America/Chicago` | Matches `APP_TIMEZONE`. |

```sh
# Examples
sudo SSH_PORT=2222 ADMIN_USER=deploy bash scripts/harden-debian12.sh
sudo DISABLE_PASSWORD_AUTH=1 INSTALL_AIDE=1 RUN_LYNIS=1 bash scripts/harden-debian12.sh
```

## Letting the OneSync server reach the database

By default MariaDB listens on `127.0.0.1` and the firewall blocks 3306 — keep it
that way. The one exception is the **OneSync server**, which pulls from our DB
over ODBC as the read-only `onesync_ro` user (SELECT on `v_onesync_source` only).
`scripts/allow-db-access.sh` opens that single path, scoped to the OneSync host:

```sh
# Open 3306 to the OneSync server only (firewall + MariaDB bind + DB grants):
sudo TRUSTED_IPS="203.0.113.10" bash scripts/allow-db-access.sh

# Multiple hosts / a subnet, and pin MariaDB to one internal NIC:
sudo TRUSTED_IPS="10.20.0.5,10.20.0.0/24" BIND_ADDRESS="10.20.0.4" \
     bash scripts/allow-db-access.sh

# Once the IP grants work, remove the wildcard onesync_ro@'%' for good measure:
sudo TRUSTED_IPS="10.20.0.5" DROP_WILDCARD_GRANT=1 bash scripts/allow-db-access.sh
```

What it does (idempotent):

1. **Firewall** — `ufw allow from <ip> to any port 3306` for each trusted IP.
   It **refuses** `0.0.0.0/0` or any `/0`; the DB is never opened to the internet.
2. **MariaDB bind-address** — writes `mariadb.conf.d/99-idm-remote.cnf` so the DB
   listens for remote connections (default `0.0.0.0`, gated by the firewall;
   set `BIND_ADDRESS` to a specific internal NIC for an extra layer).
3. **DB grants** — pins `onesync_ro` to the trusted IP(s) at the MySQL level
   (reads the password from `.env`). `DROP_WILDCARD_GRANT=1` removes the
   `onesync_ro@'%'` entry that `setup-dev-debian12.sh` created.

| Variable | Default | Notes |
|----------|---------|-------|
| `TRUSTED_IPS` | *(required)* | Comma/space-separated IPv4 or CIDR (the OneSync host). |
| `DB_PORT` | `.env` → `3306` | |
| `BIND_ADDRESS` | `0.0.0.0` | Pin to an internal NIC for defense in depth. |
| `TIGHTEN_DB_GRANTS` | `1` | Also restrict `onesync_ro` by host in MySQL. |
| `DROP_WILDCARD_GRANT` | `0` | Drop `onesync_ro@'%'` after IP grants exist. |

Verify from the OneSync server (and confirm nobody else can):

```sh
mysql -h <idm-host> -P 3306 -u onesync_ro -p -e "SELECT 1"
```

To revoke: `sudo ufw delete allow from <ip> to any port 3306 proto tcp` and drop
the matching `onesync_ro@'<ip>'` user.

> Note: this is *inbound* (OneSync → our DB). The separate `ONESYNC_DB_*` config
> is the reverse — IDM reading OneSync's own database *outbound* — which needs no
> inbound rule since the firewall leaves egress open.

## After running — not automated

These need your domain, certificate, and IdP, so the script leaves them to you:

1. **TLS**: install a cert (`certbot --nginx`) and add
   `include snippets/security-headers.conf;` to the HTTPS `server {}` block. The
   `Strict-Transport-Security` header only makes sense once TLS is live.
2. **Verify SSH**: open a *new* session (on the new port if you changed it)
   before closing your current one.
3. **App health**: `nginx -t`, `systemctl status php8.2-fpm mariadb`.
4. **DB not public**: `ss -ltnp | grep 3306` should show `127.0.0.1` only.
5. **SAML/feeds**: confirm outbound 443 (SAML, composer) and SFTP (port 234)
   still work — `ufw` allows all egress by default.
