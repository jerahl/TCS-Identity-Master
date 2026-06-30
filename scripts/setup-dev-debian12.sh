#!/usr/bin/env bash
#
# TCS Identity Master — dev server provisioning for Debian 12 (Bookworm).
#
# Installs PHP 8.2 + extensions, Composer, and MariaDB (Debian 12 ships MariaDB
# 10.11 natively — a drop-in for the app's MySQL usage), then:
#   - generates .env with random per-role passwords (never overwrites an existing .env)
#   - creates the database + the four least-privilege users with the documented GRANTs
#   - runs `composer install`, `bin/migrate.php`, and `bin/seed.php`
#   - optionally configures an nginx + php-fpm site pointing at public/
#
# Safe to re-run (idempotent). Run as root (or with sudo) from a checkout of this repo:
#
#   sudo bash scripts/setup-dev-debian12.sh
#
# Override behavior with env vars, e.g.:
#   sudo DB_NAME=tcs_identity INSTALL_WEBSERVER=0 bash scripts/setup-dev-debian12.sh
#
set -euo pipefail

# ----------------------------------------------------------------------------
# Tunables (override via environment)
# ----------------------------------------------------------------------------
DB_NAME="${DB_NAME:-tcs_identity}"
DB_HOST_GRANT="${DB_HOST_GRANT:-%}"          # host mask for app/writeback/onesync users
PHP_VERSION="${PHP_VERSION:-8.2}"            # Debian 12 default; matches "PHP 8.2+"
INSTALL_WEBSERVER="${INSTALL_WEBSERVER:-1}"  # 1 = nginx + php-fpm site, 0 = skip
SERVER_NAME="${SERVER_NAME:-identity.dev.local}"
RUN_MIGRATE="${RUN_MIGRATE:-1}"
RUN_SEED="${RUN_SEED:-1}"
SEED_DEMO="${SEED_DEMO:-0}"                   # 1 = load dev demo people (non-production)

# Resolve repo root = parent of this script's dir.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Who should own vendor/ and run composer (the human, not root).
APP_USER="${SUDO_USER:-root}"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash scripts/setup-dev-debian12.sh"
[ -f "${REPO_DIR}/composer.json" ] || die "composer.json not found in ${REPO_DIR} — run from the repo."

# Run a command as the app user (falls back to root if invoked directly as root).
run_as_app() {
    if [ "${APP_USER}" = "root" ]; then
        ( cd "${REPO_DIR}" && "$@" )
    else
        sudo -u "${APP_USER}" -H bash -c 'cd "$1" && shift && "$@"' _ "${REPO_DIR}" "$@"
    fi
}

# Generate a URL/SQL-safe random password.
gen_pass() { openssl rand -base64 24 | tr -d '/+=' | cut -c1-24; }

# Set or replace KEY=VALUE in .env (value passed literally).
set_env() {
    local key="$1" val="$2" file="${REPO_DIR}/.env"
    if grep -qE "^${key}=" "${file}"; then
        # Use a non-/ delimiter; escape & and \ for sed replacement.
        local esc; esc=$(printf '%s' "${val}" | sed -e 's/[&\\|]/\\&/g')
        sed -i "s|^${key}=.*|${key}=${esc}|" "${file}"
    else
        printf '%s=%s\n' "${key}" "${val}" >> "${file}"
    fi
}

# Read a value back from .env.
get_env() { grep -E "^$1=" "${REPO_DIR}/.env" | head -n1 | cut -d= -f2-; }

# Admin to MariaDB as root. On Debian, root@localhost uses the unix_socket auth
# plugin, so running the client as the root user connects with no password.
mysql_admin() { mariadb "$@"; }

# ----------------------------------------------------------------------------
log "Installing base packages"
# ----------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive

# Self-heal: earlier versions of this script added the Oracle MySQL APT repo,
# whose signing key has since expired (EXPKEYSIG ...). Leaving it on disk breaks
# every `apt-get update`. We use MariaDB now, so remove those artifacts.
if [ -f /etc/apt/sources.list.d/mysql.list ] || [ -f /etc/apt/keyrings/mysql.gpg ]; then
    warn "Removing stale MySQL APT repo left by a previous run."
    rm -f /etc/apt/sources.list.d/mysql.list /etc/apt/keyrings/mysql.gpg
fi

apt-get update -y
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg lsb-release apt-transport-https \
    git unzip openssl

# ----------------------------------------------------------------------------
log "Installing PHP ${PHP_VERSION} + extensions"
# ----------------------------------------------------------------------------
apt-get install -y --no-install-recommends \
    "php${PHP_VERSION}-cli" "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-intl" "php${PHP_VERSION}-zip" \
    || die "PHP install failed (is ${PHP_VERSION} available in your apt sources?)"

php -v | head -n1
php -m | grep -qi pdo_mysql || die "pdo_mysql extension missing after install"

# ----------------------------------------------------------------------------
log "Installing Composer"
# ----------------------------------------------------------------------------
if ! command -v composer >/dev/null 2>&1; then
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [ "${EXPECTED}" = "${ACTUAL}" ] || die "Composer installer checksum mismatch"
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
composer --version

# ----------------------------------------------------------------------------
log "Installing MariaDB"
# ----------------------------------------------------------------------------
# Debian 12 ships MariaDB 10.11 in the base repos — no extra apt source needed.
apt-get install -y --no-install-recommends mariadb-server mariadb-client

systemctl enable --now mariadb 2>/dev/null || systemctl enable --now mysql 2>/dev/null || true

# On Debian, root@localhost uses the unix_socket auth plugin: running the client
# as the OS root user (this script) connects with no password. Re-runs Just Work.
mysql_admin -e 'SELECT 1' >/dev/null 2>&1 \
    || die "Cannot connect to MariaDB as root via socket. Is the service running? (systemctl status mariadb)"

# ----------------------------------------------------------------------------
log "Generating .env (if missing) with random role passwords"
# ----------------------------------------------------------------------------
if [ ! -f "${REPO_DIR}/.env" ]; then
    cp "${REPO_DIR}/.env.example" "${REPO_DIR}/.env"
    chown "${APP_USER}:${APP_USER}" "${REPO_DIR}/.env" 2>/dev/null || true
    chmod 640 "${REPO_DIR}/.env"

    set_env DB_NAME "${DB_NAME}"
    set_env DB_HOST 127.0.0.1
    set_env DB_APP_PASS       "$(gen_pass)"
    set_env DB_MIGRATE_PASS   "$(gen_pass)"
    set_env DB_WRITEBACK_PASS "$(gen_pass)"
    set_env DB_ONESYNC_PASS   "$(gen_pass)"
    log ".env created with generated passwords."
else
    warn ".env already exists — leaving it untouched (passwords below come from it)."
fi

APP_USER_DB="$(get_env DB_APP_USER)";       APP_PASS_DB="$(get_env DB_APP_PASS)"
MIG_USER="$(get_env DB_MIGRATE_USER)";      MIG_PASS="$(get_env DB_MIGRATE_PASS)"
WB_USER="$(get_env DB_WRITEBACK_USER)";     WB_PASS="$(get_env DB_WRITEBACK_PASS)"
OS_USER="$(get_env DB_ONESYNC_USER)";       OS_PASS="$(get_env DB_ONESYNC_PASS)"
DB_NAME="$(get_env DB_NAME)"

# ----------------------------------------------------------------------------
log "Creating database '${DB_NAME}' + users + database-level grants"
# ----------------------------------------------------------------------------
# DB name is operator config; validate before interpolating into DDL.
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || die "Unsafe DB_NAME: ${DB_NAME}"

# Phase A — things that DON'T depend on tables existing yet. (MariaDB refuses a
# table-level GRANT for a table that doesn't exist, so the writeback/view grants
# wait until after migrations — Phase B below.)
mysql_admin <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1) Application account (DML on app tables; no DELETE/DDL).
CREATE USER IF NOT EXISTS '${APP_USER_DB}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${APP_PASS_DB}';
ALTER USER '${APP_USER_DB}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${APP_PASS_DB}';
GRANT SELECT, INSERT, UPDATE ON \`${DB_NAME}\`.* TO '${APP_USER_DB}'@'${DB_HOST_GRANT}';

-- 2) Migrator / schema owner (DDL) — used only by bin/migrate.php.
CREATE USER IF NOT EXISTS '${MIG_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${MIG_PASS}';
ALTER USER '${MIG_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${MIG_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${MIG_USER}'@'${DB_HOST_GRANT}';
GRANT CREATE ON *.* TO '${MIG_USER}'@'${DB_HOST_GRANT}';

-- 3) + 4) Create the limited-writer and OneSync-reader users now; their
-- table/view grants are applied in Phase B, after migrations create the objects.
CREATE USER IF NOT EXISTS '${WB_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${WB_PASS}';
ALTER USER '${WB_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${WB_PASS}';
CREATE USER IF NOT EXISTS '${OS_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${OS_PASS}';
ALTER USER '${OS_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${OS_PASS}';

FLUSH PRIVILEGES;
SQL
log "Database + users ready (table-level grants applied after migration)."

# ----------------------------------------------------------------------------
log "composer install"
# ----------------------------------------------------------------------------
run_as_app composer install --no-interaction --prefer-dist

# ----------------------------------------------------------------------------
if [ "${RUN_MIGRATE}" = "1" ]; then
    log "Running migrations"
    run_as_app php bin/migrate.php

    log "Applying table-level grants (writeback + OneSync reader)"
    # Phase B — the tables and the view now exist.
    mysql_admin <<SQL
-- 3) Write-back importer — limited writer for the OneSync write-back jobs.
GRANT INSERT, UPDATE, SELECT ON \`${DB_NAME}\`.onesync_writeback   TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT INSERT, UPDATE, SELECT ON \`${DB_NAME}\`.account_sync_status TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT INSERT, SELECT, DELETE ON \`${DB_NAME}\`.account_sync_event  TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT SELECT, UPDATE ON \`${DB_NAME}\`.person TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT INSERT ON \`${DB_NAME}\`.audit_log       TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT INSERT ON \`${DB_NAME}\`.lifecycle_event TO '${WB_USER}'@'${DB_HOST_GRANT}';

-- 4) OneSync reader — READ-ONLY on the source views, nothing else.
GRANT SELECT ON \`${DB_NAME}\`.v_onesync_source         TO '${OS_USER}'@'${DB_HOST_GRANT}';
GRANT SELECT ON \`${DB_NAME}\`.v_onesync_student_source TO '${OS_USER}'@'${DB_HOST_GRANT}';

FLUSH PRIVILEGES;
SQL
else
    warn "RUN_MIGRATE=0 — skipping migrations AND the writeback/OneSync table grants."
    warn "Run 'php bin/migrate.php' then re-run this script (or apply those grants by hand)."
fi

if [ "${RUN_SEED}" = "1" ]; then
    log "Seeding reference data (placeholder CSVs — replace with real data later)"
    run_as_app php bin/seed.php
fi

if [ "${SEED_DEMO}" = "1" ]; then
    log "Seeding DEV demo people (so the People list/detail render)"
    run_as_app php bin/seed_demo.php
fi

# ----------------------------------------------------------------------------
if [ "${INSTALL_WEBSERVER}" = "1" ]; then
    log "Configuring nginx + php-fpm site (web root: ${REPO_DIR}/public)"
    apt-get install -y --no-install-recommends nginx
    FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
    cat > /etc/nginx/sites-available/tcs-identity <<NGINX
server {
    listen 80;
    server_name ${SERVER_NAME};
    root ${REPO_DIR}/public;
    index index.php;

    # Security headers (HTTPS/HSTS is added when TLS is terminated in front).
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
    }
    # Never serve dotfiles (.env etc.).
    location ~ /\. { deny all; }
}
NGINX
    ln -sf /etc/nginx/sites-available/tcs-identity /etc/nginx/sites-enabled/tcs-identity
    rm -f /etc/nginx/sites-enabled/default
    # Let php-fpm (www-data) read .env without world-exposing secrets.
    if [ -f "${REPO_DIR}/.env" ]; then
        chgrp www-data "${REPO_DIR}/.env" 2>/dev/null || true
        chmod 640 "${REPO_DIR}/.env"
    fi
    systemctl enable --now "php${PHP_VERSION}-fpm"
    nginx -t && systemctl reload nginx
    warn "This dev site is HTTP only and has NO authentication yet (SAML/RBAC = M7)."
    warn "Keep it on the dev network; terminate TLS (or add certbot) before any real PII."
fi

# ----------------------------------------------------------------------------
log "Done."
# ----------------------------------------------------------------------------
cat <<SUMMARY

  Repo:        ${REPO_DIR}
  Database:    ${DB_NAME} on 127.0.0.1:3306
  Env file:    ${REPO_DIR}/.env  (generated passwords; gitignored)
  DB admin:    MariaDB root via unix_socket — run 'sudo mariadb' for a root shell
  Users:       ${APP_USER_DB} (app), ${MIG_USER} (migrate),
               ${WB_USER} (writeback), ${OS_USER} (onesync, read-only view)
$( [ "${INSTALL_WEBSERVER}" = "1" ] && echo "  Web:         http://${SERVER_NAME}/people  -> ${REPO_DIR}/public (php-fpm ${PHP_VERSION})" )

  Verify:      php bin/migrate.php --status
  Re-seed:     php bin/seed.php          (after editing db/seeds/*.csv)
  Demo data:   php bin/seed_demo.php     (dev only — sample people for the UI)
  Local run:   php -S 127.0.0.1:8000 -t public   (then open /people)
  Tests:       composer test

  Reminders:
   - Replace db/seeds/*.csv with real school + ALSDE ethnicity data, then re-seed.
   - Hand ONLY the '${OS_USER}' credentials to OneSync's ODBC source.
   - Add TLS before exposing this beyond the dev network (PII + SAML come later).

SUMMARY
