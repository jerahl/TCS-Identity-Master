#!/usr/bin/env bash
#
# TCS Identity Master — install a wildcard TLS certificate and enable HTTPS.
#
# For when you already HAVE a wildcard certificate (e.g. *.tuscaloosacityschools.com)
# from your CA — NOT Let's Encrypt/ACME. It validates the cert+key, installs them
# with safe permissions, and (re)writes the nginx 'tcs-identity' vhost to serve
# the app over HTTPS with modern TLS, security headers, and an HTTP->HTTPS
# redirect. Idempotent and safe to re-run (it backs up the existing site file).
#
# WHAT YOU PROVIDE
#   CERT_FILE   the certificate (leaf, or leaf+intermediates). PEM.
#   KEY_FILE    the matching private key. PEM (encrypted key OK — see KEY_PASSPHRASE).
#   CHAIN_FILE  (optional) intermediate/CA bundle, if your CA ships it separately.
#
# USAGE (run as root from a checkout of this repo):
#   sudo CERT_FILE=/path/star_tcs.crt KEY_FILE=/path/star_tcs.key \
#        CHAIN_FILE=/path/ca-bundle.crt \
#        bash scripts/install-wildcard-cert.sh
#
#   # encrypted key:
#   sudo CERT_FILE=... KEY_FILE=... KEY_PASSPHRASE='secret' bash scripts/install-wildcard-cert.sh
#
#   # override the hostname (default: from .env APP_BASE_URL):
#   sudo SERVER_NAME=identity.tuscaloosacityschools.com CERT_FILE=... KEY_FILE=... \
#        bash scripts/install-wildcard-cert.sh
#
set -euo pipefail

# ----------------------------------------------------------------------------
# Tunables
# ----------------------------------------------------------------------------
CERT_FILE="${CERT_FILE:-}"                 # REQUIRED
KEY_FILE="${KEY_FILE:-}"                   # REQUIRED
CHAIN_FILE="${CHAIN_FILE:-}"               # optional intermediate bundle
KEY_PASSPHRASE="${KEY_PASSPHRASE:-}"       # optional, if KEY_FILE is encrypted

SERVER_NAME="${SERVER_NAME:-}"             # default: derived from .env APP_BASE_URL
SITE_NAME="${SITE_NAME:-tcs-identity}"     # nginx site (matches setup-dev-debian12.sh)
SSL_DIR="${SSL_DIR:-/etc/ssl/idm}"         # where the installed cert/key live
PHP_VERSION="${PHP_VERSION:-8.2}"
REDIRECT_HTTP="${REDIRECT_HTTP:-1}"        # add an 80 -> 443 redirect server
WEBROOT="${WEBROOT:-}"                     # default: existing site root, else REPO_DIR/public

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${REPO_DIR}/.env"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash scripts/install-wildcard-cert.sh"
command -v nginx   >/dev/null 2>&1 || die "nginx not installed. Run scripts/setup-dev-debian12.sh first."
command -v openssl >/dev/null 2>&1 || die "openssl not found."
[ -n "${CERT_FILE}" ] && [ -r "${CERT_FILE}" ] || die "CERT_FILE not set or unreadable: '${CERT_FILE}'"
[ -n "${KEY_FILE}"  ] && [ -r "${KEY_FILE}"  ] || die "KEY_FILE not set or unreadable: '${KEY_FILE}'"
[ -z "${CHAIN_FILE}" ] || [ -r "${CHAIN_FILE}" ] || die "CHAIN_FILE set but unreadable: '${CHAIN_FILE}'"

get_env() { [ -f "${ENV_FILE}" ] && grep -E "^$1=" "${ENV_FILE}" | head -n1 | cut -d= -f2- | sed 's/[[:space:]]*#.*$//' | tr -d '[:space:]' || true; }

# Derive SERVER_NAME from APP_BASE_URL (https://host/...) if not provided.
if [ -z "${SERVER_NAME}" ]; then
    base="$(get_env APP_BASE_URL)"
    SERVER_NAME="${base#*://}"; SERVER_NAME="${SERVER_NAME%%/*}"
fi
[ -n "${SERVER_NAME}" ] || die "Could not determine SERVER_NAME — pass SERVER_NAME=host.example.com"
log "Server name: ${SERVER_NAME}"

# openssl passphrase args (only when an encrypted key is supplied).
PASSIN=()
[ -n "${KEY_PASSPHRASE}" ] && PASSIN=(-passin "pass:${KEY_PASSPHRASE}")

# ----------------------------------------------------------------------------
log "Validating certificate and key"
# ----------------------------------------------------------------------------
openssl x509 -in "${CERT_FILE}" -noout >/dev/null 2>&1 || die "CERT_FILE is not a valid PEM certificate."
openssl pkey -in "${KEY_FILE}" "${PASSIN[@]}" -noout >/dev/null 2>&1 \
    || die "KEY_FILE is not a valid private key (wrong passphrase? set KEY_PASSPHRASE)."

# Cert and key must share the same public key (works for RSA and EC).
cert_pub="$(openssl x509 -in "${CERT_FILE}" -noout -pubkey 2>/dev/null)"
key_pub="$(openssl pkey -in "${KEY_FILE}" "${PASSIN[@]}" -pubout 2>/dev/null)"
[ -n "${cert_pub}" ] && [ "${cert_pub}" = "${key_pub}" ] \
    || die "Certificate and private key do NOT match (public keys differ)."
log "Cert/key pair matches."

# Expiry check.
if ! openssl x509 -in "${CERT_FILE}" -checkend 0 >/dev/null 2>&1; then
    die "Certificate is ALREADY EXPIRED. Refusing to install."
fi
not_after="$(openssl x509 -in "${CERT_FILE}" -noout -enddate 2>/dev/null | cut -d= -f2)"
openssl x509 -in "${CERT_FILE}" -checkend 2592000 >/dev/null 2>&1 \
    || warn "Certificate expires within 30 days (${not_after}). Renew soon."
log "Certificate valid until: ${not_after}"

# Does the cert cover SERVER_NAME? (SAN, including wildcard.) Warn only.
sans="$(openssl x509 -in "${CERT_FILE}" -noout -ext subjectAltName 2>/dev/null \
        | tr ',' '\n' | sed -n 's/.*DNS:\([^ ]*\).*/\1/p' | tr -d ' ')"
covered=0
host_parent="${SERVER_NAME#*.}"
while IFS= read -r san; do
    [ -z "${san}" ] && continue
    if [ "${san}" = "${SERVER_NAME}" ]; then covered=1; break; fi
    # wildcard: *.example.com matches one-label-deeper host.example.com
    if [ "${san}" = "*.${host_parent}" ]; then covered=1; break; fi
done <<< "${sans}"
if [ "${covered}" = "1" ]; then
    log "Certificate SAN covers ${SERVER_NAME}."
else
    warn "Certificate SANs (${sans:-none}) don't obviously cover ${SERVER_NAME}."
    warn "Proceeding anyway — double-check this is the right cert for this host."
fi

# ----------------------------------------------------------------------------
log "Installing cert + key to ${SSL_DIR}"
# ----------------------------------------------------------------------------
install -d -m 700 -o root -g root "${SSL_DIR}"

# Build the chain nginx serves: leaf first, then intermediates. If CHAIN_FILE is
# given, append it; otherwise CERT_FILE is assumed to already contain the chain.
FULLCHAIN="${SSL_DIR}/fullchain.pem"
PRIVKEY="${SSL_DIR}/privkey.pem"
umask 077
if [ -n "${CHAIN_FILE}" ]; then
    cat "${CERT_FILE}" "${CHAIN_FILE}" > "${FULLCHAIN}"
else
    cat "${CERT_FILE}" > "${FULLCHAIN}"
    grep -q "BEGIN CERTIFICATE" "${FULLCHAIN}" || die "No certificate found in CERT_FILE."
    # Heuristic nudge: a single cert with no chain often means a missing bundle.
    if [ "$(grep -c 'BEGIN CERTIFICATE' "${FULLCHAIN}")" -eq 1 ]; then
        warn "CERT_FILE has only ONE certificate and no CHAIN_FILE given. If clients"
        warn "report an incomplete chain, re-run with CHAIN_FILE=<your CA intermediates>."
    fi
fi
# Normalize the key to unencrypted PEM so nginx can read it without a password.
openssl pkey -in "${KEY_FILE}" "${PASSIN[@]}" -out "${PRIVKEY}"

chmod 644 "${FULLCHAIN}"; chown root:root "${FULLCHAIN}"
chmod 600 "${PRIVKEY}";   chown root:root "${PRIVKEY}"
log "Installed ${FULLCHAIN} (644) and ${PRIVKEY} (600)."

# ----------------------------------------------------------------------------
log "Writing nginx vhost '${SITE_NAME}' (HTTPS + redirect)"
# ----------------------------------------------------------------------------
SITE_AVAIL="/etc/nginx/sites-available/${SITE_NAME}"
SITE_ENABLED="/etc/nginx/sites-enabled/${SITE_NAME}"

# Determine web root: reuse the existing site's root if present, else REPO/public.
if [ -z "${WEBROOT}" ]; then
    if [ -f "${SITE_AVAIL}" ]; then
        WEBROOT="$(sed -n 's/^[[:space:]]*root[[:space:]]\+\([^;]*\);.*/\1/p' "${SITE_AVAIL}" | head -n1)"
    fi
    [ -z "${WEBROOT}" ] && WEBROOT="${REPO_DIR}/public"
fi
[ -d "${WEBROOT}" ] || warn "Web root ${WEBROOT} does not exist — check the path."
log "Web root: ${WEBROOT}"

FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
[ -S "${FPM_SOCK}" ] || warn "php-fpm socket ${FPM_SOCK} not found yet (will work once php${PHP_VERSION}-fpm is up)."

# Back up any existing site file before overwriting.
if [ -f "${SITE_AVAIL}" ]; then
    BAK="${SITE_AVAIL}.bak.$(date +%Y%m%d%H%M%S)"
    cp -a "${SITE_AVAIL}" "${BAK}"
    log "Backed up existing vhost to ${BAK}"
fi

# Avoid duplicating http-level SSL directives. harden-debian12.sh sets
# ssl_protocols / ssl_prefer_server_ciphers in /etc/nginx/conf.d/99-hardening.conf;
# restating them in the server block makes nginx -t fail ("directive is
# duplicate"). Emit each TLS-tuning line in the vhost ONLY if it isn't already
# set at the http level. Session settings below aren't set globally, so they stay.
http_has() { grep -rqsE "^[[:space:]]*$1[[:space:]]" /etc/nginx/nginx.conf /etc/nginx/conf.d/ 2>/dev/null; }
SSL_TUNING=""
http_has 'ssl_protocols'             || SSL_TUNING+=$'    ssl_protocols TLSv1.2 TLSv1.3;\n'
http_has 'ssl_prefer_server_ciphers' || SSL_TUNING+=$'    ssl_prefer_server_ciphers off;\n'
http_has 'ssl_ciphers'               || SSL_TUNING+=$'    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;\n'

# Use the security-headers snippet from harden-debian12.sh if present; otherwise
# emit the essential headers inline (so HTTPS is still hardened on its own).
if [ -f /etc/nginx/snippets/security-headers.conf ]; then
    HEADERS_LINE="include snippets/security-headers.conf;"
else
    HEADERS_LINE='add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;'
fi

{
cat <<NGINX
# Managed by install-wildcard-cert.sh — re-run the script instead of hand-editing.
NGINX

if [ "${REDIRECT_HTTP}" = "1" ]; then
cat <<NGINX
server {
    listen 80;
    server_name ${SERVER_NAME};
    # Everything over HTTP redirects to HTTPS.
    location / { return 301 https://\$host\$request_uri; }
}

NGINX
fi

cat <<NGINX
server {
    listen 443 ssl http2;
    server_name ${SERVER_NAME};
    root ${WEBROOT};
    index index.php;

    ssl_certificate     ${FULLCHAIN};
    ssl_certificate_key ${PRIVKEY};

    # TLS protocol/cipher tuning is inherited from the http level (set by
    # harden-debian12.sh) when present; only emitted here otherwise.
${SSL_TUNING}    ssl_session_timeout 1d;
    ssl_session_cache shared:IDMSSL:10m;
    ssl_session_tickets off;

    ${HEADERS_LINE}

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param HTTPS on;
    }
    # Never serve dotfiles (.env etc.).
    location ~ /\\. { deny all; }
}
NGINX
} > "${SITE_AVAIL}"

ln -sf "${SITE_AVAIL}" "${SITE_ENABLED}"
# Drop the stock default site if it's still enabled (it would shadow port 80/443).
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# ----------------------------------------------------------------------------
log "Opening firewall for HTTPS (and HTTP redirect) if ufw is active"
# ----------------------------------------------------------------------------
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 443/tcp comment 'HTTPS (app)' >/dev/null 2>&1 || true
    [ "${REDIRECT_HTTP}" = "1" ] && ufw allow 80/tcp comment 'HTTP (redirect)' >/dev/null 2>&1 || true
fi

# ----------------------------------------------------------------------------
log "Testing and reloading nginx"
# ----------------------------------------------------------------------------
if nginx -t; then
    systemctl reload nginx 2>/dev/null || systemctl restart nginx
    log "nginx reloaded — HTTPS is live."
else
    die "nginx config test FAILED. The previous vhost backup is in ${SITE_AVAIL}.bak.* — restore it if needed."
fi

# ----------------------------------------------------------------------------
log "Done."
# ----------------------------------------------------------------------------
cat <<SUMMARY

  HTTPS enabled for https://${SERVER_NAME}/

   Cert:   ${FULLCHAIN}  (expires ${not_after})
   Key:    ${PRIVKEY}    (600 root:root)
   Vhost:  ${SITE_AVAIL}
   Root:   ${WEBROOT}
$( [ "${REDIRECT_HTTP}" = 1 ] && echo "   HTTP:   port 80 redirects to HTTPS" )

  Verify:
    curl -sS -I https://${SERVER_NAME}/ | head
    echo | openssl s_client -servername ${SERVER_NAME} -connect ${SERVER_NAME}:443 2>/dev/null \\
      | openssl x509 -noout -subject -enddate

  Reminders:
   - Confirm APP_BASE_URL in .env is https://${SERVER_NAME} so generated links use TLS.
   - Wildcard certs expire — set a calendar reminder before ${not_after} and
     re-run this script with the renewed cert (it's idempotent).

SUMMARY
