#!/usr/bin/env bash
#
# TCS Identity Master — set up the ODBC connection to PowerSchool's Oracle DB.
#
# The PowerSchool importer reads USERS/TEACHERS straight from PowerSchool's Oracle
# database over ODBC (see src/Import/PowerSchoolOdbcReader.php). That needs three
# things on this host, which this script installs and wires together:
#
#   1. unixODBC + PHP's pdo_odbc extension (apt).
#   2. Oracle Instant Client (Basic + ODBC) — Oracle's driver, NOT in apt. By
#      default the script downloads the latest client (zips, unpacked under
#      /opt/oracle). To reuse one already on the host (e.g. the instantclient_19_12
#      OneSync uses) pass INSTANTCLIENT_DIR=<path>; for offline installs point
#      INSTANTCLIENT_ZIP_DIR at a folder of downloaded Basic+ODBC zips or rpms.
#   3. The driver registered in /etc/odbcinst.ini and a DSN in /etc/odbc.ini that
#      points at your PowerSchool host, then PS_ODBC_* written into .env.
#
# It finishes by opening a real connection (SELECT 1 FROM dual) through the exact
# same code path the app uses, so a green run means the importer will connect.
#
# Idempotent — safe to re-run (re-run to change the host, bump the driver, etc.).
#
# USAGE (run as root from a checkout of this repo):
#
#   # Auto-download the Oracle Instant Client and point at your PS host:
#   sudo PS_HOST=psprod.example.org PS_SERVICE=PSPROD \
#        PS_ODBC_USER=idm_ro PS_ODBC_PASS='…' \
#        bash scripts/setup-powerschool-odbc.sh
#
#   # Reuse a specific Instant Client already on the host (e.g. OneSync's):
#   sudo PS_HOST=psprod.example.org PS_SERVICE=PSPROD \
#        INSTANTCLIENT_DIR=/opt/oracle/instantclient_19_12 \
#        bash scripts/setup-powerschool-odbc.sh
#
#   # Offline: use Instant Client zips you already downloaded to a folder:
#   sudo PS_HOST=psprod.example.org PS_SERVICE=PSPROD \
#        INSTANTCLIENT_ZIP_DIR=/root/oracle-zips \
#        bash scripts/setup-powerschool-odbc.sh
#
#   # Use an Oracle SID instead of a service name:
#   sudo PS_HOST=psprod.example.org PS_SID=PSPROD bash scripts/setup-powerschool-odbc.sh
#
#   # Non-default port and a table-owner schema prefix:
#   sudo PS_HOST=psprod.example.org PS_PORT=1522 PS_SERVICE=PSPROD \
#        PS_ODBC_SCHEMA=PSNAVIGATOR bash scripts/setup-powerschool-odbc.sh
#
#   # EZConnect rejected (ORA-12514)? Pass a full TNS descriptor verbatim — e.g.
#   # copy the one OneSync already uses from its tnsnames.ora:
#   sudo PS_SERVERNAME='(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=172.23.169.131)(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=psprod.tcs)))' \
#        PS_ODBC_USER=idm_ro PS_ODBC_PASS='…' bash scripts/setup-powerschool-odbc.sh
#
set -euo pipefail

# ----------------------------------------------------------------------------
# Tunables (override via environment)
# ----------------------------------------------------------------------------
PS_HOST="${PS_HOST:-}"                         # REQUIRED: PowerSchool Oracle host
PS_PORT="${PS_PORT:-1521}"                     # Oracle listener port
PS_SERVICE="${PS_SERVICE:-}"                   # Oracle service name (e.g. PSPROD) …
PS_SID="${PS_SID:-}"                           # … OR an Oracle SID (one of the two)
PS_SERVERNAME="${PS_SERVERNAME:-}"             # … OR a full TNS alias/descriptor used verbatim (overrides the above)
PS_DSN_NAME="${PS_DSN_NAME:-PowerSchool}"      # name of the DSN written to odbc.ini / .env
PS_ODBC_USER="${PS_ODBC_USER:-}"               # optional: written to .env + used to test
PS_ODBC_PASS="${PS_ODBC_PASS:-}"               # optional: written to .env + used to test
PS_ODBC_SCHEMA="${PS_ODBC_SCHEMA:-}"           # optional: PS table owner/schema prefix (e.g. PSNAVIGATOR)

# Instant Client acquisition. Default: download the latest client as zips (plain
# unzip — simplest on Debian). Override to reuse one already on the host, or to
# install from local files (zip or rpm).
INSTANTCLIENT_DIR="${INSTANTCLIENT_DIR:-}"     # reuse an existing Instant Client at this path (skip download)
INSTANTCLIENT_ZIP_DIR="${INSTANTCLIENT_ZIP_DIR:-}"  # offline: dir with pre-downloaded basic+odbc .zip OR .rpm files
IC_VERSION="${IC_VERSION:-23.26.2.0.0}"        # Instant Client version to download
IC_BASE_URL="${IC_BASE_URL:-https://download.oracle.com/otn_software/linux/instantclient/2326200v2}"
IC_BASIC_URL="${IC_BASIC_URL:-${IC_BASE_URL}/instantclient-basic-linux.x64-${IC_VERSION}.zip}"
IC_ODBC_URL="${IC_ODBC_URL:-${IC_BASE_URL}/instantclient-odbc-linux.x64-${IC_VERSION}.zip}"
IC_DIR_BASE="${IC_DIR_BASE:-/opt/oracle}"      # where downloaded/zip clients are unpacked
PHP_VERSION="${PHP_VERSION:-8.2}"              # for the phpX.Y-odbc package name

WRITE_ENV="${WRITE_ENV:-1}"                    # 1 = write PS_ODBC_* into .env
TEST_CONN="${TEST_CONN:-1}"                    # 1 = open a real connection at the end

ODBCINST_INI="/etc/odbcinst.ini"
ODBC_INI="/etc/odbc.ini"
DRIVER_NAME="Oracle ODBC"                      # the [stanza] name shared by both ini files

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${REPO_DIR}/.env"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# ----------------------------------------------------------------------------
# Preflight
# ----------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash scripts/setup-powerschool-odbc.sh"

# The DSN's ServerName can be a full connect string (PS_SERVERNAME) — a TNS alias
# or descriptor, e.g. when EZConnect by service/SID is rejected (ORA-12514). When
# given it wins and PS_HOST/PS_SERVICE/PS_SID/PS_PORT are not required.
if [ -n "${PS_SERVERNAME}" ]; then
    CONNECT_STR="${PS_SERVERNAME}"
else
    [ -n "${PS_HOST}" ] || die "PS_HOST is required (the PowerSchool Oracle host)."
    if [ -z "${PS_SERVICE}" ] && [ -z "${PS_SID}" ]; then
        die "Set PS_SERVICE=<service name> (recommended) or PS_SID=<sid>, or PS_SERVERNAME=<TNS descriptor>."
    fi
    [[ "${PS_PORT}" =~ ^[0-9]+$ ]] || die "PS_PORT must be numeric (got '${PS_PORT}')."
    if [ -n "${PS_SERVICE}" ]; then
        # EZConnect — the Oracle Net resolver handles this without tnsnames.ora.
        CONNECT_STR="//${PS_HOST}:${PS_PORT}/${PS_SERVICE}"
    else
        # SID has no EZConnect slot (//host:port:sid is JDBC-thin syntax, which the
        # ODBC driver's Net resolver rejects) — use a full descriptor with SID.
        CONNECT_STR="(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=${PS_HOST})(PORT=${PS_PORT}))(CONNECT_DATA=(SID=${PS_SID})))"
    fi
fi

# ----------------------------------------------------------------------------
# 1) unixODBC + PHP pdo_odbc
# ----------------------------------------------------------------------------
log "Installing unixODBC + php${PHP_VERSION}-odbc"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
    unixodbc unixodbc-dev odbcinst ca-certificates curl unzip libaio1 \
    "php${PHP_VERSION}-odbc" \
    || die "apt install failed (is php${PHP_VERSION}-odbc available? set PHP_VERSION to your installed PHP)."

php -m | grep -qi '^pdo_odbc$' || die "pdo_odbc still missing after install — check your PHP CLI/FPM build."

# ----------------------------------------------------------------------------
# 2) Oracle Instant Client (Basic + ODBC)
# ----------------------------------------------------------------------------
# Default: download the latest client. Oracle ships these as RPMs (built for
# Oracle Linux); on Debian we extract the RPM payload to its canonical location
# (/usr/lib/oracle/...) rather than dpkg-installing. Reuse an existing client with
# INSTANTCLIENT_DIR, or install from local .rpm/.zip files with INSTANTCLIENT_ZIP_DIR.
find_driver_in() { find "$1" -maxdepth 6 -name 'libsqora.so*' 2>/dev/null | sort | tail -n1; }

# Extract an Oracle Instant Client .rpm to / (so files land in /usr/lib/oracle/...).
extract_rpm() {
    command -v rpm2cpio >/dev/null || apt-get install -y --no-install-recommends rpm cpio \
        || die "Need rpm2cpio + cpio to unpack the RPM (apt install rpm cpio)."
    ( cd / && rpm2cpio "$1" | cpio -idmu --quiet ) || die "Failed to extract $(basename "$1")."
}

# Locate the ODBC driver after install (RPM -> /usr/lib/oracle; zip -> IC_DIR_BASE).
locate_driver() {
    local b hit
    for b in /usr/lib/oracle "${IC_DIR_BASE}" /opt/oracle; do
        [ -d "${b}" ] || continue
        hit="$(find_driver_in "${b}")"
        [ -n "${hit}" ] && { printf '%s\n' "${hit}"; return; }
    done
}

DRIVER_LIB=""
if [ -n "${INSTANTCLIENT_DIR}" ]; then
    log "Reusing the Oracle Instant Client at ${INSTANTCLIENT_DIR}"
    [ -d "${INSTANTCLIENT_DIR}" ] || die "INSTANTCLIENT_DIR '${INSTANTCLIENT_DIR}' not found."
    DRIVER_LIB="$(find_driver_in "${INSTANTCLIENT_DIR}" || true)"
    [ -n "${DRIVER_LIB}" ] || die "No libsqora.so* under ${INSTANTCLIENT_DIR} (is the ODBC package present there?)."

elif [ -n "${INSTANTCLIENT_ZIP_DIR}" ]; then
    log "Installing Instant Client from local files in ${INSTANTCLIENT_ZIP_DIR}"
    [ -d "${INSTANTCLIENT_ZIP_DIR}" ] || die "INSTANTCLIENT_ZIP_DIR '${INSTANTCLIENT_ZIP_DIR}' not found."
    mkdir -p "${IC_DIR_BASE}"
    found_any=0
    for f in "${INSTANTCLIENT_ZIP_DIR}"/*instantclient*basic*.rpm "${INSTANTCLIENT_ZIP_DIR}"/*instantclient*odbc*.rpm; do
        [ -e "${f}" ] || continue
        log "  extracting $(basename "${f}")"; extract_rpm "${f}"; found_any=1
    done
    for f in "${INSTANTCLIENT_ZIP_DIR}"/instantclient-basic-*.zip "${INSTANTCLIENT_ZIP_DIR}"/instantclient-odbc-*.zip; do
        [ -e "${f}" ] || continue
        log "  unzipping $(basename "${f}")"; unzip -oq "${f}" -d "${IC_DIR_BASE}"; found_any=1
    done
    [ "${found_any}" = "1" ] || die "No instantclient basic/odbc .rpm or .zip files in ${INSTANTCLIENT_ZIP_DIR}."
    DRIVER_LIB="$(locate_driver || true)"
    [ -n "${DRIVER_LIB}" ] || die "Installed local files but libsqora.so* not found."

else
    log "Downloading Oracle Instant Client ${IC_VERSION} (Basic + ODBC zips)"
    warn "If this fails (network blocked / version moved), download the Basic + ODBC"
    warn "packages for Linux x86-64 from oracle.com and re-run with INSTANTCLIENT_ZIP_DIR=<dir>,"
    warn "or reuse an existing client with INSTANTCLIENT_DIR=<dir>."
    mkdir -p "${IC_DIR_BASE}"
    TMP_DL="$(mktemp -d)"
    trap 'rm -rf "${TMP_DL}"' EXIT
    curl -fSL "${IC_BASIC_URL}" -o "${TMP_DL}/basic.zip" \
        || die "Could not download Instant Client Basic zip (${IC_BASIC_URL})."
    curl -fSL "${IC_ODBC_URL}" -o "${TMP_DL}/odbc.zip" \
        || die "Could not download Instant Client ODBC zip (${IC_ODBC_URL})."
    log "Unpacking into ${IC_DIR_BASE}"
    unzip -oq "${TMP_DL}/basic.zip" -d "${IC_DIR_BASE}"
    unzip -oq "${TMP_DL}/odbc.zip"  -d "${IC_DIR_BASE}"
    DRIVER_LIB="$(locate_driver || true)"
    [ -n "${DRIVER_LIB}" ] || die "Unpacked zips but libsqora.so* not found under ${IC_DIR_BASE}."
fi

IC_HOME="$(dirname "${DRIVER_LIB}")"
# Display version: from an instantclient_XX_YY dir name (zip layout) when present,
# else from the driver soname (libsqora.so.23.1 -> 23), else IC_VERSION.
DESC_VERSION="$(basename "${IC_HOME}" | sed -n 's/^instantclient_\([0-9]\{1,\}\)_\([0-9]\{1,\}\)$/\1.\2/p')"
DESC_VERSION="${DESC_VERSION:-$(printf '%s' "${DRIVER_LIB}" | sed -n 's/.*libsqora\.so\.\([0-9.]\{1,\}\)$/\1/p')}"
DESC_VERSION="${DESC_VERSION:-${IC_VERSION}}"
log "Oracle ODBC driver: ${DRIVER_LIB} (Instant Client ${DESC_VERSION})"

# Make the Instant Client libraries discoverable by the dynamic linker.
echo "${IC_HOME}" > /etc/ld.so.conf.d/oracle-instantclient.conf
ldconfig

# ----------------------------------------------------------------------------
# 3) Register the driver (odbcinst.ini) + create the DSN (odbc.ini)
# ----------------------------------------------------------------------------
# Idempotent ini editing: drop any existing [stanza] for our names, then append a
# fresh one. Keeps unrelated drivers/DSNs untouched.
strip_stanza() { # <file> <stanza-name>
    local f="$1" name="$2"
    [ -f "${f}" ] || { touch "${f}"; return; }
    awk -v target="[${name}]" '
        BEGIN { keep = 1 }                       # preserve any leading/global lines
        /^\[/ { keep = ($0 != target) }
        { if (keep) print }
    ' "${f}" > "${f}.tmp" && mv "${f}.tmp" "${f}"
}

log "Registering driver in ${ODBCINST_INI}"
strip_stanza "${ODBCINST_INI}" "${DRIVER_NAME}"
cat >> "${ODBCINST_INI}" <<EOF
[${DRIVER_NAME}]
Description = Oracle ODBC driver for Instant Client ${DESC_VERSION}
Driver      = ${DRIVER_LIB}
FileUsage   = 1
EOF

log "Creating DSN [${PS_DSN_NAME}] in ${ODBC_INI}"
strip_stanza "${ODBC_INI}" "${PS_DSN_NAME}"
cat >> "${ODBC_INI}" <<EOF
[${PS_DSN_NAME}]
Description = PowerSchool (Oracle) — TCS Identity Master import
Driver      = ${DRIVER_NAME}
ServerName  = ${CONNECT_STR}
EOF
chmod 644 "${ODBCINST_INI}" "${ODBC_INI}"

# ----------------------------------------------------------------------------
# 4) Write PS_ODBC_* into .env
# ----------------------------------------------------------------------------
set_env() { # <key> <value> — replace existing line or append
    local key="$1" val="$2"
    if grep -qE "^${key}=" "${ENV_FILE}" 2>/dev/null; then
        # Use a non-/ delimiter so values with slashes (DSN connect strings) are safe.
        sed -i "s|^${key}=.*|${key}=${val}|" "${ENV_FILE}"
    else
        printf '%s=%s\n' "${key}" "${val}" >> "${ENV_FILE}"
    fi
}

if [ "${WRITE_ENV}" = "1" ]; then
    [ -f "${ENV_FILE}" ] || { warn "No .env yet — creating one (copy .env.example for the rest)."; touch "${ENV_FILE}"; chmod 600 "${ENV_FILE}"; }
    log "Writing PS_ODBC_* into ${ENV_FILE}"
    set_env PS_ODBC_DSN "${PS_DSN_NAME}"
    [ -n "${PS_ODBC_USER}" ] && set_env PS_ODBC_USER "${PS_ODBC_USER}"
    [ -n "${PS_ODBC_PASS}" ] && set_env PS_ODBC_PASS "${PS_ODBC_PASS}"
    # Schema/owner prefix the reader prepends to the PS table names. Write it when
    # given; clear any stale value when explicitly set empty so .env matches intent.
    set_env PS_ODBC_SCHEMA "${PS_ODBC_SCHEMA}"
else
    log "Skipping .env (WRITE_ENV=0). Set these yourself:"
    printf '  PS_ODBC_DSN=%s\n  PS_ODBC_USER=…\n  PS_ODBC_PASS=…\n  PS_ODBC_SCHEMA=%s\n' "${PS_DSN_NAME}" "${PS_ODBC_SCHEMA}"
fi

# ----------------------------------------------------------------------------
# 5) Test the connection (same code path as the importer)
# ----------------------------------------------------------------------------
if [ "${TEST_CONN}" = "1" ]; then
    if [ -z "${PS_ODBC_USER}" ] || [ -z "${PS_ODBC_PASS}" ]; then
        warn "PS_ODBC_USER/PS_ODBC_PASS not given — skipping the live connection test."
        warn "Test by hand once set: php bin/import_powerschool.php --dry-run"
    else
        log "Testing the connection (SELECT 1 FROM dual via PDO ODBC)"
        DSN="${PS_DSN_NAME}" U="${PS_ODBC_USER}" P="${PS_ODBC_PASS}" php -r '
            try {
                $pdo = new PDO("odbc:".getenv("DSN"), getenv("U"), getenv("P"),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $v = $pdo->query("SELECT 1 FROM dual")->fetchColumn();
                fwrite(STDOUT, "  connection OK (dual returned {$v})\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "  connection FAILED: ".$e->getMessage()."\n");
                exit(1);
            }
        ' || die "Connection test failed. Check PS_HOST/PS_SERVICE, credentials, and the firewall to ${PS_HOST}:${PS_PORT}."
    fi
fi

log "Done."
echo "  Driver:  ${DRIVER_NAME} -> ${DRIVER_LIB}"
echo "  DSN:     ${PS_DSN_NAME} -> ${CONNECT_STR}   (${ODBC_INI})"
echo "  .env:    PS_ODBC_DSN=${PS_DSN_NAME}$([ -n "${PS_ODBC_USER}" ] && echo " · PS_ODBC_USER set")$([ -n "${PS_ODBC_SCHEMA}" ] && echo " · PS_ODBC_SCHEMA=${PS_ODBC_SCHEMA}")"
echo
echo "  Next:  php bin/import_powerschool.php --dry-run     # verify the import end-to-end"
