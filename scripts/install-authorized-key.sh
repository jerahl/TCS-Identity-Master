#!/usr/bin/env bash
#
# TCS Identity Master — install an SSH public key on the SERVER.
#
# Use this on the IDM server when you generated your key with the MobaKeyGen GUI
# (or anywhere else) and just need to paste the PUBLIC key into authorized_keys
# with correct ownership/permissions. For the fully scripted path from Windows,
# use scripts/setup-key-auth-mobaxterm.sh in MobaXterm's local terminal instead.
#
# In MobaKeyGen: Generate -> copy the box labeled "Public key for pasting into
# OpenSSH authorized_keys file" (the single 'ssh-ed25519 AAAA... comment' line —
# NOT the multi-line .pub file, and NOT the .ppk).
#
# USAGE (run as the target user, or as root with --user):
#   # paste the key as an argument (quote it):
#   bash install-authorized-key.sh "ssh-ed25519 AAAA...== moba@pc"
#
#   # or pipe / paste interactively:
#   bash install-authorized-key.sh            # then paste the line and press Enter
#
#   # install for another user (root only):
#   sudo bash install-authorized-key.sh --user deploy "ssh-ed25519 AAAA...=="
#
set -euo pipefail

die() { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

TARGET_USER=""
PUBKEY=""
while [ $# -gt 0 ]; do
    case "$1" in
        --user) TARGET_USER="${2:-}"; shift 2 ;;
        --user=*) TARGET_USER="${1#*=}"; shift ;;
        -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) PUBKEY="$1"; shift ;;
    esac
done

# Resolve which user's authorized_keys we're writing to.
if [ -n "${TARGET_USER}" ]; then
    [ "$(id -u)" -eq 0 ] || die "--user requires root (sudo)."
    id "${TARGET_USER}" >/dev/null 2>&1 || die "No such user: ${TARGET_USER}"
    HOME_DIR="$(getent passwd "${TARGET_USER}" | cut -d: -f6)"
else
    TARGET_USER="$(id -un)"
    HOME_DIR="${HOME}"
fi
[ -n "${HOME_DIR}" ] && [ -d "${HOME_DIR}" ] || die "Cannot resolve home dir for ${TARGET_USER}"

# Get the key (arg, or prompt).
if [ -z "${PUBKEY}" ]; then
    echo "Paste the OpenSSH public key line (ssh-ed25519/ssh-rsa ...), then Enter:"
    read -r PUBKEY
fi
PUBKEY="$(printf '%s' "${PUBKEY}" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
[ -n "${PUBKEY}" ] || die "No key provided."
# Sanity check: must look like an OpenSSH public key line.
case "${PUBKEY}" in
    ssh-ed25519\ *|ssh-rsa\ *|ecdsa-sha2-*\ *|sk-ssh-ed25519@openssh.com\ *) : ;;
    *) die "That doesn't look like an OpenSSH public key. Did you paste the .ppk or the multi-line key by mistake? Use the single 'ssh-ed25519 AAAA...' line." ;;
esac
# Validate with ssh-keygen if available (rejects truncated/garbled keys).
if command -v ssh-keygen >/dev/null 2>&1; then
    printf '%s\n' "${PUBKEY}" | ssh-keygen -l -f /dev/stdin >/dev/null 2>&1 \
        || die "ssh-keygen rejected the key — it looks malformed/incomplete."
fi

SSH_DIR="${HOME_DIR}/.ssh"
AUTH="${SSH_DIR}/authorized_keys"
install -d -m 700 "${SSH_DIR}"
touch "${AUTH}"; chmod 600 "${AUTH}"

if grep -qxF "${PUBKEY}" "${AUTH}" 2>/dev/null; then
    echo "Key already present in ${AUTH} — nothing to do."
else
    printf '%s\n' "${PUBKEY}" >> "${AUTH}"
    echo "Installed key into ${AUTH}"
fi

# Fix ownership when running as root for another user.
if [ "$(id -u)" -eq 0 ]; then
    chown -R "${TARGET_USER}:$(id -gn "${TARGET_USER}")" "${SSH_DIR}"
fi

echo
echo "Done. Fingerprint:"
ssh-keygen -lf <(printf '%s\n' "${PUBKEY}") 2>/dev/null || true
echo
echo "Test from your client BEFORE disabling password auth:"
echo "  ssh ${TARGET_USER}@<this-host>"
