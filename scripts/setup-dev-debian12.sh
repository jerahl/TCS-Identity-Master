#!/usr/bin/env bash
#
# TCS Identity Master — dev server provisioning for Debian 12 (Bookworm).
#
# Installs PHP 8.2 + extensions, Composer, and MySQL 8 (Oracle APT repo, to honor
# the "MySQL 8+" requirement — Debian ships MariaDB by default), then:
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

ROOT_CNF="/root/.idm-mysql-root.cnf"         # root creds for non-interactive admin

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

mysql_admin() { mysql --defaults-extra-file="${ROOT_CNF}" "$@"; }

# ----------------------------------------------------------------------------
log "Installing base packages"
# ----------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
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
log "Installing MySQL 8 (Oracle APT repo)"
# ----------------------------------------------------------------------------
if ! command -v mysql >/dev/null 2>&1; then
    # Add MySQL's signing key + apt source for this Debian codename.
    CODENAME="$(lsb_release -cs)"
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 \
        | gpg --dearmor -o /etc/apt/keyrings/mysql.gpg
    echo "deb [signed-by=/etc/apt/keyrings/mysql.gpg] http://repo.mysql.com/apt/debian/ ${CODENAME} mysql-8.0" \
        > /etc/apt/sources.list.d/mysql.list
    apt-get update -y

    # Generate + stash a root password so re-runs can administer non-interactively.
    MYSQL_ROOT_PASS="$(gen_pass)"
    debconf-set-selections <<EOF
mysql-community-server mysql-community-server/root-pass password ${MYSQL_ROOT_PASS}
mysql-community-server mysql-community-server/re-root-pass password ${MYSQL_ROOT_PASS}
mysql-community-server mysql-server/default-auth-override select Use Strong Password Encryption (RECOMMENDED)
EOF
    apt-get install -y mysql-community-server mysql-community-client

    umask 077
    cat > "${ROOT_CNF}" <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASS}
host=127.0.0.1
EOF
    chmod 600 "${ROOT_CNF}"
else
    warn "MySQL already installed — skipping install."
    [ -f "${ROOT_CNF}" ] || warn "No ${ROOT_CNF}; will try socket auth for admin steps."
fi

systemctl enable --now mysql 2>/dev/null || systemctl enable --now mysqld 2>/dev/null || true

# Pick how we talk to MySQL as admin.
if [ -f "${ROOT_CNF}" ] && mysql_admin -e 'SELECT 1' >/dev/null 2>&1; then
    : # ROOT_CNF works
elif mysql -e 'SELECT 1' >/dev/null 2>&1; then
    mysql_admin() { mysql "$@"; }   # local socket as root (some setups)
    warn "Using local socket for MySQL admin (no ${ROOT_CNF})."
else
    die "Cannot connect to MySQL as admin. Provide ${ROOT_CNF} ([client] user/password) and re-run."
fi

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
log "Creating database '${DB_NAME}' + least-privilege users"
# ----------------------------------------------------------------------------
# DB name is operator config; validate before interpolating into DDL.
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || die "Unsafe DB_NAME: ${DB_NAME}"

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

-- 3) Write-back importer — limited writer for the OneSync write-back jobs.
CREATE USER IF NOT EXISTS '${WB_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${WB_PASS}';
ALTER USER '${WB_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${WB_PASS}';
GRANT INSERT, UPDATE, SELECT ON \`${DB_NAME}\`.onesync_writeback   TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT INSERT, UPDATE, SELECT ON \`${DB_NAME}\`.account_sync_status TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT INSERT, UPDATE, SELECT ON \`${DB_NAME}\`.account_sync_event  TO '${WB_USER}'@'${DB_HOST_GRANT}';
GRANT SELECT, UPDATE ON \`${DB_NAME}\`.person TO '${WB_USER}'@'${DB_HOST_GRANT}';

-- 4) OneSync reader — READ-ONLY on the single view, nothing else.
CREATE USER IF NOT EXISTS '${OS_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${OS_PASS}';
ALTER USER '${OS_USER}'@'${DB_HOST_GRANT}' IDENTIFIED BY '${OS_PASS}';
GRANT SELECT ON \`${DB_NAME}\`.v_onesync_source TO '${OS_USER}'@'${DB_HOST_GRANT}';

FLUSH PRIVILEGES;
SQL
log "Database + users ready."
# NOTE: the GRANT on v_onesync_source must be (re)run AFTER migrations create the
# view; we re-issue it post-migrate below so the OneSync reader works on first run.

# ----------------------------------------------------------------------------
log "composer install"
# ----------------------------------------------------------------------------
run_as_app composer install --no-interaction --prefer-dist

# ----------------------------------------------------------------------------
if [ "${RUN_MIGRATE}" = "1" ]; then
    log "Running migrations"
    run_as_app php bin/migrate.php
    # The view now exists — (re)grant the OneSync reader against it.
    mysql_admin -e "GRANT SELECT ON \`${DB_NAME}\`.v_onesync_source TO '${OS_USER}'@'${DB_HOST_GRANT}'; FLUSH PRIVILEGES;" || \
        warn "Could not grant on v_onesync_source (will exist after first migrate)."
fi

if [ "${RUN_SEED}" = "1" ]; then
    log "Seeding reference data (placeholder CSVs — replace with real data later)"
    run_as_app php bin/seed.php
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
    warn "public/ has no index.php until Milestone 2 — nginx will 404 until then."
    warn "This dev site is HTTP only. Terminate TLS (or add certbot) before any real PII."
fi

# ----------------------------------------------------------------------------
log "Done."
# ----------------------------------------------------------------------------
cat <<SUMMARY

  Repo:        ${REPO_DIR}
  Database:    ${DB_NAME} on 127.0.0.1:3306
  Env file:    ${REPO_DIR}/.env  (generated passwords; gitignored)
  MySQL root:  ${ROOT_CNF}  (root-only)
  Users:       ${APP_USER_DB} (app), ${MIG_USER} (migrate),
               ${WB_USER} (writeback), ${OS_USER} (onesync, read-only view)
$( [ "${INSTALL_WEBSERVER}" = "1" ] && echo "  Web:         http://${SERVER_NAME}/  -> ${REPO_DIR}/public (php-fpm ${PHP_VERSION})" )

  Verify:      php bin/migrate.php --status
  Re-seed:     php bin/seed.php          (after editing db/seeds/*.csv)
  Tests:       composer test

  Reminders:
   - Replace db/seeds/*.csv with real school + ALSDE ethnicity data, then re-seed.
   - Hand ONLY the '${OS_USER}' credentials to OneSync's ODBC source.
   - Add TLS before exposing this beyond the dev network (PII + SAML come later).

SUMMARY
