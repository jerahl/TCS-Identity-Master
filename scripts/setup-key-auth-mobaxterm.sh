#!/usr/bin/env bash
#
# TCS Identity Master — set up SSH key authentication from MobaXterm (Windows).
#
# RUN THIS IN MOBAXTERM'S "LOCAL TERMINAL" (the bash shell on the Start tab),
# NOT on the server. It bootstraps key-based login to the IDM server so you can
# then run scripts/harden-debian12.sh with DISABLE_PASSWORD_AUTH=1 without
# locking yourself out.
#
# It will:
#   1. Generate an ed25519 keypair in your MobaXterm home (~/.ssh/id_ed25519)
#      if you don't already have one. (Skips if present — never overwrites.)
#   2. Copy the PUBLIC key to the server's ~/.ssh/authorized_keys (you'll be
#      asked for your server password ONCE). Idempotent — won't duplicate.
#   3. Test that key-only login works and print the MobaXterm session settings.
#
# Why MobaXterm's local terminal: it bundles OpenSSH (ssh, ssh-keygen), so this
# plain bash script runs as-is — no PuTTY/MobaKeyGen GUI steps needed. If you
# prefer the GUI (MobaKeyGen), see docs/server-hardening.md and use
# scripts/install-authorized-key.sh on the server instead.
#
# USAGE (in MobaXterm local terminal):
#   bash setup-key-auth-mobaxterm.sh user@server.example.com
#   bash setup-key-auth-mobaxterm.sh deploy@10.0.0.5 2222      # custom SSH port
#
# Or set them interactively — run with no args and you'll be prompted.
#
set -euo pipefail

KEY_TYPE="ed25519"
KEY_FILE="${HOME}/.ssh/id_${KEY_TYPE}"

info() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

command -v ssh        >/dev/null 2>&1 || die "ssh not found. Run this in MobaXterm's LOCAL terminal."
command -v ssh-keygen >/dev/null 2>&1 || die "ssh-keygen not found. Run this in MobaXterm's LOCAL terminal."

# --- Target ---------------------------------------------------------------
TARGET="${1:-}"
SSH_PORT="${2:-22}"
if [ -z "${TARGET}" ]; then
    read -r -p "Server (user@host): " TARGET
    read -r -p "SSH port [22]: " _p; SSH_PORT="${_p:-22}"
fi
[ -n "${TARGET}" ] || die "No target given. Usage: bash setup-key-auth-mobaxterm.sh user@host [port]"
[[ "${TARGET}" == *@* ]] || die "Target must be user@host (e.g. deploy@10.0.0.5)"
[[ "${SSH_PORT}" =~ ^[0-9]+$ ]] || die "Port must be numeric: ${SSH_PORT}"

# --- 1) Generate the keypair (if missing) ---------------------------------
mkdir -p "${HOME}/.ssh"
chmod 700 "${HOME}/.ssh" 2>/dev/null || true
if [ -f "${KEY_FILE}" ]; then
    info "Reusing existing key: ${KEY_FILE}"
else
    info "Generating a new ${KEY_TYPE} keypair: ${KEY_FILE}"
    echo "  (You'll be asked for a passphrase — strongly recommended. Press Enter twice for none.)"
    ssh-keygen -t "${KEY_TYPE}" -a 100 -f "${KEY_FILE}" -C "mobaxterm-$(whoami)-to-idm"
fi
[ -f "${KEY_FILE}.pub" ] || die "Public key ${KEY_FILE}.pub missing after keygen."
PUBKEY="$(cat "${KEY_FILE}.pub")"

# --- 2) Install the public key on the server ------------------------------
info "Copying your public key to ${TARGET} (port ${SSH_PORT})"
echo "  You'll be prompted for your SERVER PASSWORD once. After this, keys take over."
# Manual, ssh-copy-id-free install (MobaXterm doesn't always bundle ssh-copy-id).
# Single round trip; idempotent (grep -qxF guards against duplicate lines).
ssh -p "${SSH_PORT}" -o StrictHostKeyChecking=accept-new "${TARGET}" \
    "umask 077; mkdir -p ~/.ssh && \
     { grep -qxF '${PUBKEY}' ~/.ssh/authorized_keys 2>/dev/null || echo '${PUBKEY}' >> ~/.ssh/authorized_keys; } && \
     chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys && \
     echo INSTALLED_OK" \
    || die "Could not reach/authenticate to ${TARGET}. Check host, port, and password."

# --- 3) Verify key-only login ---------------------------------------------
info "Verifying key-based login (no password should be requested)"
if ssh -p "${SSH_PORT}" \
       -o BatchMode=yes \
       -o PreferredAuthentications=publickey \
       -o IdentitiesOnly=yes \
       -i "${KEY_FILE}" \
       "${TARGET}" 'echo KEY_LOGIN_OK; hostname' 2>/dev/null | grep -q KEY_LOGIN_OK; then
    info "SUCCESS — key authentication works."
else
    die "Key login test failed. Do NOT disable password auth yet. Re-run, or check the server's sshd / authorized_keys."
fi

# --- Next steps ------------------------------------------------------------
HOST_ONLY="${TARGET#*@}"; USER_ONLY="${TARGET%@*}"
cat <<NEXT

  Configure a MobaXterm session to use this key:
    1. MobaXterm -> Session -> SSH
         Remote host: ${HOST_ONLY}
         Specify username: ${USER_ONLY}
         Port: ${SSH_PORT}
    2. Advanced SSH settings -> tick "Use private key" -> select:
         ${KEY_FILE}
       (MobaXterm reads OpenSSH keys directly — no .ppk conversion needed.)
    3. Save. Connecting should now ask only for your key passphrase (if you set one).

  Once that session connects with the key, it is safe to lock down passwords:
    sudo DISABLE_PASSWORD_AUTH=1 bash scripts/harden-debian12.sh
$( [ "${SSH_PORT}" != 22 ] && echo "    (your hardening run should also pass SSH_PORT=${SSH_PORT})" )

  Keep this MobaXterm session open while you run the hardening script, and test a
  second new connection before closing it.

NEXT
