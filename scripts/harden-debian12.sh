#!/usr/bin/env bash
#
# TCS Identity Master — production server hardening for Debian 12 (Bookworm).
#
# Hardens an internet-facing host that runs the IDM stack provisioned by
# scripts/setup-dev-debian12.sh: nginx + php-fpm serving public/, MariaDB on
# 127.0.0.1, outbound SFTP feed pulls, SAML SSO, and systemd timers — handling
# student/staff PII, so this box is a real target.
#
# What it does (every step is idempotent and safe to re-run):
#   - Applies security updates and enables unattended-upgrades.
#   - Hardens SSH via a drop-in (root login off, key-only auth when keys exist,
#     no forwarding, modern ciphers) — WITHOUT locking you out.
#   - Configures a default-deny firewall (ufw): inbound SSH + 80/443 only,
#     outbound open (SFTP/DB/SAML/composer all need it).
#   - Installs fail2ban (sshd jail) to throttle brute force.
#   - Applies kernel/network sysctl hardening and disables core dumps.
#   - Hardens php-fpm (expose_php off, dangerous funcs disabled, secure sessions)
#     and nginx (server_tokens off, security headers, body-size cap).
#   - Tightens account policy (umask 027, password quality, login.defs).
#   - Enables time sync, persistent journald, AppArmor, and (optionally) auditd,
#     AIDE file-integrity, and a Lynis audit.
#   - Locks down permissions on the app's .env (secrets) and repo.
#
# It does NOT install the app or touch the database contents — run
# setup-dev-debian12.sh first (or deploy the app), then run this.
#
# USAGE (run as root from a checkout of this repo):
#
#   sudo bash scripts/harden-debian12.sh
#
# IMPORTANT — read before running on a remote box you reach over SSH:
#   * Make sure you can log in with an SSH KEY before this runs. If the admin
#     user has no authorized_keys, password auth is LEFT ON (with a loud warning)
#     so you are not locked out. Set up a key, then re-run, or pass
#     DISABLE_PASSWORD_AUTH=1 to force key-only.
#   * If you change SSH_PORT, the firewall and fail2ban are updated to match, but
#     you must reconnect on the new port. Keep your current session open and test
#     a second connection before closing it.
#
# Common overrides (environment variables):
#   sudo SSH_PORT=2222 ADMIN_USER=deploy bash scripts/harden-debian12.sh
#   sudo DISABLE_PASSWORD_AUTH=1 INSTALL_AIDE=1 RUN_LYNIS=1 bash scripts/harden-debian12.sh
#
set -euo pipefail

# ----------------------------------------------------------------------------
# Tunables (override via environment)
# ----------------------------------------------------------------------------
SSH_PORT="${SSH_PORT:-22}"                     # change to move SSH off 22
ADMIN_USER="${ADMIN_USER:-${SUDO_USER:-}}"     # user that must keep SSH access
DISABLE_ROOT_LOGIN="${DISABLE_ROOT_LOGIN:-1}"  # PermitRootLogin no
DISABLE_PASSWORD_AUTH="${DISABLE_PASSWORD_AUTH:-auto}"  # auto|1|0 (auto = only if keys exist)

ALLOW_HTTP="${ALLOW_HTTP:-1}"                  # open 80 (needed for ACME + redirect)
ALLOW_HTTPS="${ALLOW_HTTPS:-1}"                # open 443 (the app)

RUN_UPGRADE="${RUN_UPGRADE:-1}"                # apply pending package upgrades now
INSTALL_FAIL2BAN="${INSTALL_FAIL2BAN:-1}"
INSTALL_AUDITD="${INSTALL_AUDITD:-1}"
INSTALL_AIDE="${INSTALL_AIDE:-0}"              # file-integrity DB (slow init); off by default
RUN_LYNIS="${RUN_LYNIS:-0}"                    # run a Lynis audit at the end

HARDEN_PHP="${HARDEN_PHP:-1}"
PHP_VERSION="${PHP_VERSION:-8.2}"              # Debian 12 default
HARDEN_NGINX="${HARDEN_NGINX:-1}"
MAX_BODY_SIZE="${MAX_BODY_SIZE:-25m}"          # nginx client_max_body_size (>= UPLOAD_MAX_BYTES)

TIMEZONE="${TIMEZONE:-America/Chicago}"        # matches app APP_TIMEZONE

# Resolve repo root = parent of this script's dir (used to lock down .env).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash scripts/harden-debian12.sh"
[[ "${SSH_PORT}" =~ ^[0-9]+$ ]] && [ "${SSH_PORT}" -ge 1 ] && [ "${SSH_PORT}" -le 65535 ] \
    || die "Invalid SSH_PORT: ${SSH_PORT}"

export DEBIAN_FRONTEND=noninteractive

# ----------------------------------------------------------------------------
log "Security updates + unattended-upgrades"
# ----------------------------------------------------------------------------
apt-get update -y
if [ "${RUN_UPGRADE}" = "1" ]; then
    apt-get -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" upgrade
fi
apt-get install -y --no-install-recommends unattended-upgrades apt-listchanges

# Enable periodic update + unattended security upgrades.
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

# Auto-remove unused deps and reboot (off-hours) when the kernel needs it.
cat > /etc/apt/apt.conf.d/51unattended-upgrades-local <<'EOF'
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "03:30";
EOF
systemctl enable --now unattended-upgrades 2>/dev/null || true

# ----------------------------------------------------------------------------
log "Base hardening tools"
# ----------------------------------------------------------------------------
apt-get install -y --no-install-recommends \
    ufw fail2ban libpam-pwquality apparmor apparmor-utils
# Time sync: systemd-timesyncd is the Debian 12 default (lightweight). Install it
# only if chrony/ntpd aren't already managing the clock, to avoid a conflict.
if ! systemctl is-active --quiet chrony 2>/dev/null && ! systemctl is-active --quiet ntpsec 2>/dev/null; then
    apt-get install -y --no-install-recommends systemd-timesyncd 2>/dev/null || true
fi

# ----------------------------------------------------------------------------
log "Time synchronization"
# ----------------------------------------------------------------------------
timedatectl set-timezone "${TIMEZONE}" 2>/dev/null || warn "Could not set timezone ${TIMEZONE}"
timedatectl set-ntp true 2>/dev/null || true
if systemctl list-unit-files 2>/dev/null | grep -q '^systemd-timesyncd'; then
    systemctl enable --now systemd-timesyncd 2>/dev/null || true
fi

# ----------------------------------------------------------------------------
log "SSH hardening (drop-in: /etc/ssh/sshd_config.d/99-hardening.conf)"
# ----------------------------------------------------------------------------
# Decide whether it is SAFE to disable password auth. We only do it if the admin
# user actually has authorized_keys — otherwise disabling it locks everyone out.
keys_present=0
admin_home=""
if [ -n "${ADMIN_USER}" ] && id "${ADMIN_USER}" >/dev/null 2>&1; then
    admin_home="$(getent passwd "${ADMIN_USER}" | cut -d: -f6)"
    if [ -s "${admin_home}/.ssh/authorized_keys" ]; then
        keys_present=1
    fi
fi
# Also count root keys (some setups log in as root with a key).
[ -s /root/.ssh/authorized_keys ] && keys_present=1

case "${DISABLE_PASSWORD_AUTH}" in
    1) pw_auth="no" ;;
    0) pw_auth="yes" ;;
    auto)
        if [ "${keys_present}" = "1" ]; then
            pw_auth="no"
        else
            pw_auth="yes"
            warn "No SSH authorized_keys found for '${ADMIN_USER:-<none>}' or root."
            warn "Leaving PasswordAuthentication ON to avoid lockout."
            warn "Add a key, then re-run with DISABLE_PASSWORD_AUTH=1 for key-only auth."
        fi ;;
    *) die "DISABLE_PASSWORD_AUTH must be auto, 1, or 0" ;;
esac

root_login="prohibit-password"
[ "${DISABLE_ROOT_LOGIN}" = "1" ] && root_login="no"

mkdir -p /etc/ssh/sshd_config.d
cat > /etc/ssh/sshd_config.d/99-hardening.conf <<EOF
# Managed by harden-debian12.sh — edit tunables and re-run instead of hand-editing.
Port ${SSH_PORT}
Protocol 2

PermitRootLogin ${root_login}
PasswordAuthentication ${pw_auth}
PubkeyAuthentication yes
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PermitEmptyPasswords no
UsePAM yes

# Reduce attack surface.
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding no
PermitTunnel no
MaxAuthTries 3
MaxSessions 4
LoginGraceTime 30
ClientAliveInterval 300
ClientAliveCountMax 2

# Modern, strong algorithms only.
KexAlgorithms curve25519-sha256,curve25519-sha256@libssh.org,diffie-hellman-group16-sha512,diffie-hellman-group18-sha512
Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com
MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com
EOF

# Restrict SSH to the admin user if we know who it is (defense in depth).
if [ -n "${ADMIN_USER}" ] && id "${ADMIN_USER}" >/dev/null 2>&1; then
    echo "AllowUsers ${ADMIN_USER}" >> /etc/ssh/sshd_config.d/99-hardening.conf
    log "SSH restricted to AllowUsers ${ADMIN_USER}"
else
    warn "ADMIN_USER not set/found — not adding AllowUsers (any valid user may SSH)."
fi

# Validate config before reloading so a typo can't kill sshd.
if sshd -t; then
    systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || systemctl restart ssh
    log "sshd reloaded (port ${SSH_PORT}, PasswordAuthentication ${pw_auth}, root ${root_login})"
else
    die "sshd config test failed — NOT reloading. Fix /etc/ssh/sshd_config.d/99-hardening.conf"
fi

# ----------------------------------------------------------------------------
log "Firewall (ufw): default deny inbound, allow SSH + web"
# ----------------------------------------------------------------------------
ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming
ufw default allow outgoing          # SFTP(234), MariaDB(local), SAML/composer(443) need egress
ufw limit "${SSH_PORT}/tcp" comment 'SSH (rate-limited)'
[ "${ALLOW_HTTP}"  = "1" ] && ufw allow 80/tcp  comment 'HTTP (ACME + redirect)'
[ "${ALLOW_HTTPS}" = "1" ] && ufw allow 443/tcp comment 'HTTPS (app)'
ufw logging on
ufw --force enable
ufw status verbose || true

# ----------------------------------------------------------------------------
if [ "${INSTALL_FAIL2BAN}" = "1" ]; then
    log "fail2ban (sshd jail on port ${SSH_PORT})"
    cat > /etc/fail2ban/jail.local <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd
# Never ban the loopback.
ignoreip = 127.0.0.1/8 ::1

[sshd]
enabled  = true
port     = ${SSH_PORT}
maxretry = 3
bantime  = 1h
EOF
    systemctl enable --now fail2ban
    systemctl restart fail2ban
    fail2ban-client status sshd 2>/dev/null || true
fi

# ----------------------------------------------------------------------------
log "Kernel / network sysctl hardening"
# ----------------------------------------------------------------------------
cat > /etc/sysctl.d/99-hardening.conf <<'EOF'
# --- IP spoofing / source routing / redirects ---
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0

# --- SYN flood / ICMP ---
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1

# --- Log martians ---
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# --- Memory / pointer hardening ---
kernel.randomize_va_space = 2
kernel.kptr_restrict = 2
kernel.dmesg_restrict = 1
kernel.yama.ptrace_scope = 1
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
fs.protected_fifos = 2
fs.protected_regular = 2
fs.suid_dumpable = 0
EOF
sysctl --system >/dev/null

# ----------------------------------------------------------------------------
log "Disable core dumps"
# ----------------------------------------------------------------------------
cat > /etc/security/limits.d/99-no-coredump.conf <<'EOF'
* hard core 0
* soft core 0
EOF
mkdir -p /etc/systemd/coredump.conf.d
cat > /etc/systemd/coredump.conf.d/99-disable.conf <<'EOF'
[Coredump]
Storage=none
ProcessSizeMax=0
EOF
systemctl daemon-reload 2>/dev/null || true

# ----------------------------------------------------------------------------
log "Account policy (login.defs, umask, password quality)"
# ----------------------------------------------------------------------------
sed -i \
    -e 's/^#\?UMASK.*/UMASK 027/' \
    -e 's/^#\?PASS_MAX_DAYS.*/PASS_MAX_DAYS 365/' \
    -e 's/^#\?PASS_MIN_DAYS.*/PASS_MIN_DAYS 1/' \
    -e 's/^#\?PASS_WARN_AGE.*/PASS_WARN_AGE 14/' \
    -e 's/^#\?SHA_CRYPT_MIN_ROUNDS.*/SHA_CRYPT_MIN_ROUNDS 65536/' \
    /etc/login.defs || warn "Could not fully update /etc/login.defs"
grep -q '^SHA_CRYPT_MIN_ROUNDS' /etc/login.defs || echo 'SHA_CRYPT_MIN_ROUNDS 65536' >> /etc/login.defs

# Password quality (used when local passwords are set). Prefer the conf.d dir
# (libpwquality >= 1.4.4, present on Debian 12); fall back to the main file.
if [ -d /etc/security/pwquality.conf.d ]; then
    PWQ_FILE=/etc/security/pwquality.conf.d/99-hardening.conf
else
    PWQ_FILE=/etc/security/pwquality.conf
fi
cat > "${PWQ_FILE}" <<'EOF'
minlen = 14
dcredit = -1
ucredit = -1
ocredit = -1
lcredit = -1
retry = 3
EOF

# ----------------------------------------------------------------------------
if [ "${HARDEN_PHP}" = "1" ]; then
    PHP_FPM_DIR="/etc/php/${PHP_VERSION}/fpm"
    if [ -d "${PHP_FPM_DIR}" ]; then
        log "php-fpm hardening (${PHP_FPM_DIR}/conf.d/99-hardening.ini)"
        cat > "${PHP_FPM_DIR}/conf.d/99-hardening.ini" <<'EOF'
; Managed by harden-debian12.sh
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
allow_url_fopen = On      ; app uses HTTPS (SAML/feeds); keep on
allow_url_include = Off
; Disable functions that should never run from a web app handling PII.
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,proc_close,proc_terminate
; Hide PHP version in headers (also set expose_php above).
; Secure session cookies (app serves over HTTPS).
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
EOF
        systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null \
            || systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null \
            || warn "php${PHP_VERSION}-fpm not running — config will apply on next start."
    else
        warn "php-fpm ${PHP_VERSION} not installed — skipping PHP hardening."
    fi
fi

# ----------------------------------------------------------------------------
if [ "${HARDEN_NGINX}" = "1" ] && command -v nginx >/dev/null 2>&1; then
    log "nginx hardening (server_tokens off, security headers, body cap)"
    cat > /etc/nginx/conf.d/99-hardening.conf <<EOF
# Managed by harden-debian12.sh
server_tokens off;
client_max_body_size ${MAX_BODY_SIZE};
client_body_timeout 15s;
client_header_timeout 15s;
# Only modern TLS (cert/TLS termination configured separately, e.g. certbot).
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;
EOF

    # Security-headers snippet the site config can `include`.
    mkdir -p /etc/nginx/snippets
    cat > /etc/nginx/snippets/security-headers.conf <<'EOF'
# include this from each server{} block (after TLS is terminated):
#   include snippets/security-headers.conf;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header X-XSS-Protection "0" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
# Keep this in lockstep with src/Http/Security.php — the app sends its own CSP
# too, and the browser enforces BOTH, so any drift blocks whatever one omits
# (e.g. the Google Fonts stylesheet, or inline style="" attributes).
# script-src carries 'unsafe-eval' ONLY because /reference/data-flow (the
# interactive data-flow chart) needs it; the app-level CSP still denies eval on
# every other route, and the effective policy is the intersection of the two —
# so eval stays blocked site-wide except on that one page.
add_header Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; script-src 'self' 'unsafe-eval'; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'" always;
EOF
    if nginx -t 2>/dev/null; then
        systemctl reload nginx 2>/dev/null || true
        log "nginx reloaded. Add 'include snippets/security-headers.conf;' to your server block."
    else
        warn "nginx -t failed after writing hardening config — review /etc/nginx/conf.d/99-hardening.conf"
    fi
else
    [ "${HARDEN_NGINX}" = "1" ] && warn "nginx not installed — skipping nginx hardening."
fi

# ----------------------------------------------------------------------------
log "AppArmor"
# ----------------------------------------------------------------------------
systemctl enable --now apparmor 2>/dev/null || true
aa-status --enabled 2>/dev/null && log "AppArmor enabled" || warn "AppArmor not enforcing (check kernel boot params)."

# ----------------------------------------------------------------------------
if [ "${INSTALL_AUDITD}" = "1" ]; then
    log "auditd (basic identity/security audit rules)"
    apt-get install -y --no-install-recommends auditd audispd-plugins
    cat > /etc/audit/rules.d/99-hardening.rules <<'EOF'
# Watch sensitive auth + sudoers + ssh config.
-w /etc/passwd -p wa -k identity
-w /etc/group -p wa -k identity
-w /etc/shadow -p wa -k identity
-w /etc/sudoers -p wa -k sudoers
-w /etc/sudoers.d/ -p wa -k sudoers
-w /etc/ssh/sshd_config -p wa -k sshd
-w /etc/ssh/sshd_config.d/ -p wa -k sshd
# Track use of privilege-escalation tooling.
-w /usr/bin/sudo -p x -k priv_esc
# Lock the rules (uncomment to make immutable until reboot):
# -e 2
EOF
    systemctl enable --now auditd 2>/dev/null || true
    augenrules --load 2>/dev/null || true
fi

# ----------------------------------------------------------------------------
log "Persistent, size-bounded journald logs"
# ----------------------------------------------------------------------------
mkdir -p /etc/systemd/journald.conf.d
cat > /etc/systemd/journald.conf.d/99-hardening.conf <<'EOF'
[Journal]
Storage=persistent
Compress=yes
SystemMaxUse=500M
MaxRetentionSec=90day
EOF
systemctl restart systemd-journald 2>/dev/null || true

# ----------------------------------------------------------------------------
if [ "${INSTALL_AIDE}" = "1" ]; then
    log "AIDE file-integrity database (this can take a few minutes)"
    apt-get install -y --no-install-recommends aide aide-common
    if [ ! -f /var/lib/aide/aide.db.gz ]; then
        aideinit -y -f 2>/dev/null || aide --init 2>/dev/null || warn "AIDE init failed."
        [ -f /var/lib/aide/aide.db.new.gz ] && mv -f /var/lib/aide/aide.db.new.gz /var/lib/aide/aide.db.gz
    fi
    log "AIDE ready. Check integrity later with: sudo aide --check"
fi

# ----------------------------------------------------------------------------
log "Locking down app secrets (.env) and repo permissions"
# ----------------------------------------------------------------------------
if [ -f "${REPO_DIR}/.env" ]; then
    # php-fpm runs as www-data and must read .env; nobody else should.
    chgrp www-data "${REPO_DIR}/.env" 2>/dev/null || true
    chmod 640 "${REPO_DIR}/.env"
    log ".env -> 640 (root:www-data)"
fi
# Common secret/material dirs referenced by .env — tighten if present.
for d in /var/idm/saml /var/idm/sftp /var/idm/onesync; do
    if [ -d "${d}" ]; then
        chmod 750 "${d}" 2>/dev/null || true
    fi
done

# ----------------------------------------------------------------------------
if [ "${RUN_LYNIS}" = "1" ]; then
    log "Running Lynis audit (report below; full log in /var/log/lynis.log)"
    apt-get install -y --no-install-recommends lynis
    lynis audit system --quick 2>/dev/null || warn "Lynis audit returned non-zero."
fi

# ----------------------------------------------------------------------------
log "Hardening complete."
# ----------------------------------------------------------------------------
cat <<SUMMARY

  Host hardened for internet exposure. Key state:

   SSH        port ${SSH_PORT}, root ${root_login}, PasswordAuthentication ${pw_auth}
$( [ -n "${ADMIN_USER}" ] && echo "              AllowUsers ${ADMIN_USER}" )
   Firewall   ufw: deny inbound; allow ${SSH_PORT}/tcp$( [ "${ALLOW_HTTP}" = 1 ] && echo ", 80" )$( [ "${ALLOW_HTTPS}" = 1 ] && echo ", 443" ); outbound open
   Patching   unattended-upgrades on (auto-reboot 03:30 if kernel needs it)
$( [ "${INSTALL_FAIL2BAN}" = 1 ] && echo "   fail2ban   sshd jail active (3 tries -> 1h ban)" )
   Kernel     sysctl hardening + core dumps disabled
$( [ "${HARDEN_PHP}" = 1 ] && echo "   PHP        expose_php off, dangerous funcs disabled, secure sessions" )
$( [ "${HARDEN_NGINX}" = 1 ] && command -v nginx >/dev/null 2>&1 && echo "   nginx      server_tokens off; security-headers snippet at /etc/nginx/snippets/" )
$( [ "${INSTALL_AUDITD}" = 1 ] && echo "   auditd     watching passwd/shadow/sudoers/sshd" )
$( [ "${INSTALL_AIDE}" = 1 ] && echo "   AIDE       baseline DB created (sudo aide --check)" )

  NEXT STEPS (not automated — they need your domain/cert/IdP):
   1. Terminate TLS: install a cert (e.g. certbot --nginx) and add
      'include snippets/security-headers.conf;' to your HTTPS server block.
   2. Verify you can open a NEW SSH session$( [ "${SSH_PORT}" != 22 ] && echo " on port ${SSH_PORT}" ) before closing this one.
   3. Confirm the app still works: nginx -t, systemctl status php${PHP_VERSION}-fpm mariadb.
   4. Make sure MariaDB is NOT listening publicly: 'ss -ltnp | grep 3306' should be 127.0.0.1.
   5. Re-run this script any time — it is idempotent.

SUMMARY
