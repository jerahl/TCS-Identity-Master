#!/usr/bin/env bash
#
# TCS Identity Master — allow a trusted host (e.g. the OneSync server) to reach
# the MariaDB port, scoped to specific IPs only.
#
# Background: by default the IDM database listens on 127.0.0.1 and the firewall
# denies 3306 — exactly what you want for an internet-facing box. OneSync, though,
# runs on a SEPARATE server and pulls from our DB over ODBC as the read-only
# 'onesync_ro' user (SELECT on the v_onesync_source view only). This script opens
# that one path, narrowly:
#
#   1. Firewall: allow DB_PORT (3306) ONLY from the IP(s)/CIDR(s) you name.
#      Never 0.0.0.0/0 — the script refuses to open the DB to the whole internet.
#   2. MariaDB: bind to a network address so remote connections are possible
#      (default 0.0.0.0, gated by the firewall above; override with BIND_ADDRESS
#      to pin it to one internal NIC for defense in depth).
#   3. (Optional) DB grants: pin 'onesync_ro' to the trusted IP(s) at the MySQL
#      level too, so the account can only authenticate from those hosts.
#
# Idempotent — safe to re-run. Re-run to add more IPs.
#
# USAGE (run as root from a checkout of this repo):
#   sudo TRUSTED_IPS="203.0.113.10" bash scripts/allow-db-access.sh
#   sudo TRUSTED_IPS="203.0.113.10,10.20.0.0/24" bash scripts/allow-db-access.sh
#
#   # bind MariaDB to a specific internal interface instead of 0.0.0.0:
#   sudo TRUSTED_IPS="10.20.0.5" BIND_ADDRESS="10.20.0.4" bash scripts/allow-db-access.sh
#
#   # also remove the wildcard onesync_ro@'%' grant once IP grants are in place:
#   sudo TRUSTED_IPS="10.20.0.5" DROP_WILDCARD_GRANT=1 bash scripts/allow-db-access.sh
#
set -euo pipefail

# ----------------------------------------------------------------------------
# Tunables
# ----------------------------------------------------------------------------
TRUSTED_IPS="${TRUSTED_IPS:-}"                 # REQUIRED: comma/space-separated IPs or CIDRs
DB_PORT="${DB_PORT:-}"                         # default: from .env, else 3306
BIND_ADDRESS="${BIND_ADDRESS:-0.0.0.0}"        # MariaDB listen address (firewall is the gate)
TIGHTEN_DB_GRANTS="${TIGHTEN_DB_GRANTS:-1}"    # 1 = pin onesync_ro to trusted IPs in MySQL too
DROP_WILDCARD_GRANT="${DROP_WILDCARD_GRANT:-0}" # 1 = drop onesync_ro@'%' after pinning to IPs
RESTART_MARIADB="${RESTART_MARIADB:-1}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${REPO_DIR}/.env"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash scripts/allow-db-access.sh"
[ -n "${TRUSTED_IPS}" ] || die "TRUSTED_IPS is required, e.g. TRUSTED_IPS=\"203.0.113.10\" (the OneSync server)."

# Read a value from .env (used for DB_PORT and the onesync grant).
get_env() { [ -f "${ENV_FILE}" ] && grep -E "^$1=" "${ENV_FILE}" | head -n1 | cut -d= -f2- | sed 's/[[:space:]]*#.*$//' | tr -d '[:space:]' || true; }

[ -z "${DB_PORT}" ] && DB_PORT="$(get_env DB_PORT)"
[ -z "${DB_PORT}" ] && DB_PORT="3306"
[[ "${DB_PORT}" =~ ^[0-9]+$ ]] && [ "${DB_PORT}" -ge 1 ] && [ "${DB_PORT}" -le 65535 ] \
    || die "Invalid DB_PORT: ${DB_PORT}"

# ----------------------------------------------------------------------------
# Parse + validate the trusted IP list. Refuse anything that opens 3306 widely.
# ----------------------------------------------------------------------------
# Accept commas or whitespace as separators.
IFS=', ' read -r -a IP_LIST <<< "${TRUSTED_IPS}"
CLEAN_IPS=()
valid_ipv4_cidr() {
    local ip="${1%/*}" cidr="${1#*/}" o
    [ "${1}" = "${ip}" ] && cidr=""           # no slash -> single host
    # 4 octets, each 0-255
    local IFS='.'; read -r -a oct <<< "${ip}"
    [ "${#oct[@]}" -eq 4 ] || return 1
    for o in "${oct[@]}"; do
        [[ "${o}" =~ ^[0-9]+$ ]] && [ "${o}" -ge 0 ] && [ "${o}" -le 255 ] || return 1
    done
    if [ -n "${cidr}" ]; then
        [[ "${cidr}" =~ ^[0-9]+$ ]] && [ "${cidr}" -ge 1 ] && [ "${cidr}" -le 32 ] || return 1
    fi
    return 0
}
for raw in "${IP_LIST[@]}"; do
    [ -z "${raw}" ] && continue
    case "${raw}" in
        0.0.0.0|0.0.0.0/0|*/0)
            die "Refusing to open the DB port to '${raw}'. Name specific OneSync host IP(s)." ;;
    esac
    valid_ipv4_cidr "${raw}" || die "Not a valid IPv4 address or CIDR: '${raw}'"
    CLEAN_IPS+=("${raw}")
done
[ "${#CLEAN_IPS[@]}" -gt 0 ] || die "No valid trusted IPs after parsing TRUSTED_IPS."

log "Trusted source IP(s) for MariaDB port ${DB_PORT}: ${CLEAN_IPS[*]}"

# ----------------------------------------------------------------------------
log "Firewall (ufw): allow ${DB_PORT}/tcp from trusted IPs only"
# ----------------------------------------------------------------------------
command -v ufw >/dev/null 2>&1 || die "ufw not installed. Run scripts/harden-debian12.sh first."
ufw status | grep -q "Status: active" || warn "ufw is not active — rules added but not enforced until 'ufw enable'."
for ip in "${CLEAN_IPS[@]}"; do
    # ufw skips duplicates automatically, so this is idempotent.
    ufw allow from "${ip}" to any port "${DB_PORT}" proto tcp comment 'OneSync DB access'
done
log "Current ufw rules for ${DB_PORT}:"
ufw status | grep -E "(^|\s)${DB_PORT}(/| )" || true

# ----------------------------------------------------------------------------
log "MariaDB bind-address (drop-in: 99-idm-remote.cnf)"
# ----------------------------------------------------------------------------
MARIADB_CONF_DIR="/etc/mysql/mariadb.conf.d"
if [ ! -d "${MARIADB_CONF_DIR}" ]; then
    # Fall back to the generic MySQL conf dir if that's what's installed.
    [ -d /etc/mysql/mysql.conf.d ] && MARIADB_CONF_DIR=/etc/mysql/mysql.conf.d
fi
[ -d "${MARIADB_CONF_DIR}" ] || die "MariaDB conf.d not found — is the DB installed on this host?"

cat > "${MARIADB_CONF_DIR}/99-idm-remote.cnf" <<EOF
# Managed by allow-db-access.sh — lets the OneSync server reach the DB.
# Access is restricted to trusted IPs by ufw (see 'ufw status') AND, when
# enabled, by per-host MySQL grants for onesync_ro. Do NOT widen casually.
[mysqld]
bind-address = ${BIND_ADDRESS}
EOF
log "Set bind-address = ${BIND_ADDRESS} (loaded after 50-server.cnf)."

if [ "${RESTART_MARIADB}" = "1" ]; then
    systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null \
        || warn "Could not restart MariaDB — restart it manually to apply bind-address."
    sleep 1
    if ss -ltn 2>/dev/null | grep -qE "[:.]${DB_PORT}\b"; then
        log "MariaDB is listening on port ${DB_PORT}:"
        ss -ltn 2>/dev/null | grep -E "[:.]${DB_PORT}\b" || true
    else
        warn "MariaDB does not appear to be listening on ${DB_PORT} yet — check 'systemctl status mariadb'."
    fi
else
    warn "RESTART_MARIADB=0 — restart MariaDB yourself to apply the new bind-address."
fi

# ----------------------------------------------------------------------------
if [ "${TIGHTEN_DB_GRANTS}" = "1" ]; then
    log "Pinning 'onesync_ro' MySQL grants to the trusted IP(s)"
    # Admin to MariaDB as root via unix_socket (no password on Debian).
    if ! mariadb -e 'SELECT 1' >/dev/null 2>&1; then
        warn "Can't connect to MariaDB as root via socket — skipping grant tightening."
        warn "Firewall rules are still in place; onesync_ro@'%' (from setup) will be used."
    else
        OS_USER="$(get_env DB_ONESYNC_USER)"; [ -n "${OS_USER}" ] || OS_USER="onesync_ro"
        OS_PASS="$(get_env DB_ONESYNC_PASS)"
        DB_NAME="$(get_env DB_NAME)";        [ -n "${DB_NAME}" ] || DB_NAME="tcs_identity"
        [[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || die "Unsafe DB_NAME from .env: ${DB_NAME}"

        if [ -z "${OS_PASS}" ] || [ "${OS_PASS}" = "change-me-onesync" ]; then
            warn "DB_ONESYNC_PASS not set to a real value in .env — skipping grant tightening."
            warn "Set it (and create the user via setup-dev-debian12.sh) then re-run."
        else
            for ip in "${CLEAN_IPS[@]}"; do
                # A CIDR can't be a MySQL host literal; use it as-is for single
                # hosts, and warn for CIDRs (firewall still scopes those).
                host="${ip}"
                if [[ "${ip}" == */* ]]; then
                    warn "MySQL grants can't use CIDR '${ip}'; relying on the firewall for that range."
                    continue
                fi
                mariadb <<SQL
CREATE USER IF NOT EXISTS '${OS_USER}'@'${host}' IDENTIFIED BY '${OS_PASS}';
ALTER USER '${OS_USER}'@'${host}' IDENTIFIED BY '${OS_PASS}';
GRANT SELECT ON \`${DB_NAME}\`.v_onesync_source TO '${OS_USER}'@'${host}';
FLUSH PRIVILEGES;
SQL
                log "Granted ${OS_USER}@'${host}' SELECT on ${DB_NAME}.v_onesync_source"
            done

            if [ "${DROP_WILDCARD_GRANT}" = "1" ]; then
                mariadb <<SQL
DROP USER IF EXISTS '${OS_USER}'@'%';
FLUSH PRIVILEGES;
SQL
                log "Dropped wildcard ${OS_USER}@'%' — account now only authenticates from the named IP(s)."
            else
                warn "Wildcard ${OS_USER}@'%' (from setup) is still present. The firewall limits"
                warn "where it can connect from. For belt-and-suspenders, re-run with DROP_WILDCARD_GRANT=1."
            fi
        fi
    fi
else
    warn "TIGHTEN_DB_GRANTS=0 — MySQL-level host restriction skipped (firewall still applies)."
fi

# ----------------------------------------------------------------------------
log "Done."
# ----------------------------------------------------------------------------
cat <<SUMMARY

  DB access opened for: ${CLEAN_IPS[*]}
  Port:        ${DB_PORT}/tcp (ufw: allowed from those IPs only)
  bind-address ${BIND_ADDRESS}  (${MARIADB_CONF_DIR}/99-idm-remote.cnf)
$( [ "${TIGHTEN_DB_GRANTS}" = 1 ] && echo "  DB grants:   onesync_ro pinned to the trusted IP(s)" )

  Verify from the OneSync server (it should connect; everyone else should not):
    mysql -h <this-host> -P ${DB_PORT} -u onesync_ro -p -e "SELECT 1"

  Hand OneSync's ODBC source ONLY the onesync_ro credentials. It can read exactly
  one object — the v_onesync_source view — and nothing else.

  To revoke later: 'ufw delete allow from <ip> to any port ${DB_PORT} proto tcp'
  and drop the matching onesync_ro@'<ip>' user.

SUMMARY
