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
