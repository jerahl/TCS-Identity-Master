#!/usr/bin/env bash
#
# TCS Identity Master — set up the ODBC connection to PowerSchool's Oracle DB.
#
# The PowerSchool importer reads USERS/TEACHERS straight from PowerSchool's Oracle
# database over ODBC (see src/Import/PowerSchoolOdbcReader.php). That needs three
# things on this host, which this script installs and wires together:
#
#   1. unixODBC + PHP's pdo_odbc extension (apt).
#   2. Oracle Instant Client (Basic + ODBC) — Oracle's driver, NOT in apt. If the
#      host already has one (e.g. the instantclient_19_12 OneSync uses) the script
#      detects and REUSES it; otherwise it downloads the public packages or uses
#      zips you downloaded yourself (offline / air-gapped hosts).
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
set -euo pipefail

# ----------------------------------------------------------------------------
# Tunables (override via environment)
# ----------------------------------------------------------------------------
PS_HOST="${PS_HOST:-}"                         # REQUIRED: PowerSchool Oracle host
PS_PORT="${PS_PORT:-1521}"                     # Oracle listener port
PS_SERVICE="${PS_SERVICE:-}"                   # Oracle service name (e.g. PSPROD) …
PS_SID="${PS_SID:-}"                           # … OR an Oracle SID (one of the two)
PS_DSN_NAME="${PS_DSN_NAME:-PowerSchool}"      # name of the DSN written to odbc.ini / .env
PS_ODBC_USER="${PS_ODBC_USER:-}"               # optional: written to .env + used to test
PS_ODBC_PASS="${PS_ODBC_PASS:-}"               # optional: written to .env + used to test

INSTANTCLIENT_DIR="${INSTANTCLIENT_DIR:-}"     # explicit path to an existing Instant Client (skips detect/download)
INSTANTCLIENT_ZIP_DIR="${INSTANTCLIENT_ZIP_DIR:-}"  # dir with pre-downloaded basic+odbc zips (offline)
IC_VERSION="${IC_VERSION:-19.12.0.0.0}"        # Instant Client version to auto-download (fallback only)
IC_DIR_BASE="${IC_DIR_BASE:-/opt/oracle}"      # where Instant Client is unpacked (download fallback)
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
[ -n "${PS_HOST}" ] || die "PS_HOST is required (the PowerSchool Oracle host)."
if [ -z "${PS_SERVICE}" ] && [ -z "${PS_SID}" ]; then
    die "Set PS_SERVICE=<service name> (recommended) or PS_SID=<sid>."
fi
[[ "${PS_PORT}" =~ ^[0-9]+$ ]] || die "PS_PORT must be numeric (got '${PS_PORT}')."

# EZConnect connect string the Oracle ODBC driver resolves without tnsnames.ora.
#   service: //host:port/service     SID: //host:port:sid
if [ -n "${PS_SERVICE}" ]; then
    CONNECT_STR="//${PS_HOST}:${PS_PORT}/${PS_SERVICE}"
else
    CONNECT_STR="//${PS_HOST}:${PS_PORT}:${PS_SID}"
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
# 2) Oracle Instant Client (Basic + ODBC) — reuse an existing one if present
# ----------------------------------------------------------------------------
# Prefer a client already on the host (e.g. the instantclient_19_12 OneSync uses)
# over downloading a second copy. Search order: explicit INSTANTCLIENT_DIR, then
# common locations, then (last resort) download.
find_driver_in() { find "$1" -maxdepth 4 -name 'libsqora.so*' 2>/dev/null | sort | tail -n1; }

detect_existing_driver() {
    if [ -n "${INSTANTCLIENT_DIR}" ]; then
        [ -d "${INSTANTCLIENT_DIR}" ] || die "INSTANTCLIENT_DIR '${INSTANTCLIENT_DIR}' not found."
        find_driver_in "${INSTANTCLIENT_DIR}"
        return
    fi
    local base hit
    for base in "${IC_DIR_BASE}" /opt/oracle /opt /usr/lib/oracle /usr/local/oracle /opt/instantclient*; do
        [ -d "${base}" ] || continue
        hit="$(find_driver_in "${base}")"
        [ -n "${hit}" ] && { printf '%s\n' "${hit}"; return; }
    done
}

DRIVER_LIB="$(detect_existing_driver || true)"
if [ -n "${DRIVER_LIB}" ]; then
    log "Reusing the Oracle Instant Client already on this host (e.g. OneSync's): ${DRIVER_LIB}"
else
    warn "No existing Oracle Instant Client found — acquiring one (set INSTANTCLIENT_DIR to reuse OneSync's)."
    mkdir -p "${IC_DIR_BASE}"
    TMP_ZIPS="$(mktemp -d)"
    trap 'rm -rf "${TMP_ZIPS}"' EXIT

    if [ -n "${INSTANTCLIENT_ZIP_DIR}" ]; then
        log "Using pre-downloaded Instant Client zips from ${INSTANTCLIENT_ZIP_DIR}"
        [ -d "${INSTANTCLIENT_ZIP_DIR}" ] || die "INSTANTCLIENT_ZIP_DIR '${INSTANTCLIENT_ZIP_DIR}' not found."
        basic_zip="$(find "${INSTANTCLIENT_ZIP_DIR}" -iname 'instantclient-basic-*.zip' | head -n1)"
        odbc_zip="$(find "${INSTANTCLIENT_ZIP_DIR}" -iname 'instantclient-odbc-*.zip' | head -n1)"
        [ -n "${basic_zip}" ] || die "No instantclient-basic-*.zip in ${INSTANTCLIENT_ZIP_DIR}."
        [ -n "${odbc_zip}" ]  || die "No instantclient-odbc-*.zip in ${INSTANTCLIENT_ZIP_DIR}."
    else
        # Public Instant Client downloads (no login). IC_REL is the version with dots
        # stripped, e.g. 21.13.0.0.0 -> 2113000.
        IC_REL="${IC_VERSION//./}"
        base_url="https://download.oracle.com/otn_software/linux/instantclient/${IC_REL}"
        log "Downloading Oracle Instant Client ${IC_VERSION} (Basic + ODBC)"
        warn "If this fails (network blocked / version retired), download the Basic and"
        warn "ODBC zips for Linux x86-64 from oracle.com and re-run with INSTANTCLIENT_ZIP_DIR=<dir>."
        basic_zip="${TMP_ZIPS}/basic.zip"
        odbc_zip="${TMP_ZIPS}/odbc.zip"
        curl -fSL "${base_url}/instantclient-basic-linux.x64-${IC_VERSION}dbru.zip" -o "${basic_zip}" \
            || die "Could not download Instant Client Basic — see INSTANTCLIENT_ZIP_DIR note above."
        curl -fSL "${base_url}/instantclient-odbc-linux.x64-${IC_VERSION}dbru.zip" -o "${odbc_zip}" \
            || die "Could not download Instant Client ODBC — see INSTANTCLIENT_ZIP_DIR note above."
    fi

    log "Unpacking Instant Client into ${IC_DIR_BASE}"
    unzip -oq "${basic_zip}" -d "${IC_DIR_BASE}"
    unzip -oq "${odbc_zip}"  -d "${IC_DIR_BASE}"
    DRIVER_LIB="$(find_driver_in "${IC_DIR_BASE}" || true)"
    [ -n "${DRIVER_LIB}" ] || die "Instant Client unpacked but libsqora.so* not found under ${IC_DIR_BASE}."
fi

IC_HOME="$(dirname "${DRIVER_LIB}")"
# Display version: from an instantclient_XX_YY dir name when present, else IC_VERSION.
DESC_VERSION="$(basename "${IC_HOME}" | sed -n 's/^instantclient_\([0-9]\{1,\}\)_\([0-9]\{1,\}\)$/\1.\2/p')"
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
else
    log "Skipping .env (WRITE_ENV=0). Set these yourself:"
    printf '  PS_ODBC_DSN=%s\n  PS_ODBC_USER=…\n  PS_ODBC_PASS=…\n' "${PS_DSN_NAME}"
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
echo "  .env:    PS_ODBC_DSN=${PS_DSN_NAME}$([ -n "${PS_ODBC_USER}" ] && echo " · PS_ODBC_USER set" )"
echo
echo "  Next:  php bin/import_powerschool.php --dry-run     # verify the import end-to-end"
