#!/usr/bin/env bash
# 01-ssh-key.sh: add the owner's SSH public key to aioadmin's authorized_keys.
#
# Needs sudo: no (run as aioadmin). Reboots: no. Safe to re-run: yes; a key
# already present is not added twice.
# Usage:  bash setup/01-ssh-key.sh ~/owner-key.pub
# Then test key login from the PC (docs/setup.md, step 1) BEFORE running 02.
# Requirements: MS-SEC-01 (hardened access), M-034.

source "$(dirname "$0")/lib.sh"
no_root
banner "add your PC's public key for aioadmin's SSH login" "no" "no"

PUB=${1:-}
[[ -n "$PUB" && -f "$PUB" ]] || die "give the public key file: bash $0 ~/owner-key.pub"

# Exactly one key line, of an accepted type.
mapfile -t LINES < <(grep -v -e '^[[:space:]]*$' -e '^#' "$PUB")
[[ ${#LINES[@]} -eq 1 ]] || die "$PUB must hold exactly one key line (found ${#LINES[@]})"
KEY_LINE=${LINES[0]//$'\r'/}
KEY_TYPE=$(awk '{print $1}' <<<"$KEY_LINE")
KEY_BLOB=$(awk '{print $2}' <<<"$KEY_LINE")
KEY_FILE=$(mktemp)
trap 'rm -f "$KEY_FILE"' EXIT
printf '%s\n' "$KEY_LINE" > "$KEY_FILE"
ME=$(id -un)
case "$KEY_TYPE" in
    ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|sk-ssh-ed25519@openssh.com) ;;
    ssh-rsa) BITS=$(ssh-keygen -l -f "$KEY_FILE" | awk '{print $1}')
             [[ $BITS -ge 3072 ]] || die "RSA key of $BITS bits; use ed25519 (or RSA 3072+)" ;;
    *) die "key type '$KEY_TYPE' not accepted; use ed25519" ;;
esac
ssh-keygen -l -f "$KEY_FILE" >/dev/null 2>&1 || die "$PUB is not a valid public key"

install -d -m 700 "$HOME/.ssh"
AK="$HOME/.ssh/authorized_keys"
touch "$AK"
chmod 600 "$AK"
if grep -qF -- "$KEY_BLOB" "$AK"; then
    info "key already present, not added again"
else
    printf '%s\n' "$KEY_LINE" >> "$AK"
    info "key added to $AK"
fi

echo
info "Fingerprint of the key now authorised (compare with the PC: ssh-keygen -lf <your .pub file>):"
ssh-keygen -l -f "$KEY_FILE"

echo
check "~/.ssh is mode 700 and owned by $ME" file_mode_is "$HOME/.ssh" 700 "$ME" "$(id -gn)"
check "authorized_keys is mode 600 and owned by $ME" file_mode_is "$AK" 600 "$ME" "$(id -gn)"
check "authorized_keys holds the key" grep -qF -- "$KEY_BLOB" "$AK"
check "authorized_keys holds the key exactly once" test "$(grep -cF -- "$KEY_BLOB" "$AK")" -eq 1
sshd_running() { systemctl is-active --quiet ssh.service || systemctl is-active --quiet ssh.socket; }
check "sshd is running" sshd_running
finish
echo
echo "Next: from the PC, test key-only login (docs/setup.md step 1). Don't run 02 until it works."
