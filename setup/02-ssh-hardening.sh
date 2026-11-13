#!/usr/bin/env bash
# 02-ssh-hardening.sh: turn SSH password login off; keys only, no root login.
#
# Needs sudo: yes. Reboots: no (reloads sshd; open sessions stay connected).
# Safe to re-run: yes.
# Usage:  sudo bash setup/02-ssh-hardening.sh --key-login-confirmed
# Run it ONLY after key login from the PC works (docs/setup.md step 1), and keep
# this session open until a NEW key login works afterwards.
# Requirements: MS-SEC-01, M-034.

source "$(dirname "$0")/lib.sh"
need_root
banner "SSH: keys only, password and root login off" "yes" "no (reloads sshd)"

[[ "${1:-}" == "--key-login-confirmed" ]] || die "confirm key login works first, then run: sudo bash $0 --key-login-confirmed"

ADMIN=${SUDO_USER:-aioadmin}
ADMIN_HOME=$(getent passwd "$ADMIN" | cut -d: -f6)
AK="$ADMIN_HOME/.ssh/authorized_keys"
[[ -s "$AK" ]] || die "$AK is empty or missing; run 01-ssh-key.sh first"
grep -qE '^(ssh-ed25519|ecdsa-sha2|sk-ssh-ed25519|ssh-rsa) ' "$AK" || die "$AK holds no usable key"

# A drop-in named 10-… is read before cloud-init's 50-… file; for sshd the
# first value read wins, so these settings hold whatever later files say.
CONF=/etc/ssh/sshd_config.d/10-aio-mothership.conf
TMP=$(mktemp)
cat > "$TMP" <<'CONF'
# Managed by aio-mothership setup/02-ssh-hardening.sh (M-034). Don't edit here.
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
AuthenticationMethods publickey
PermitRootLogin no
PermitEmptyPasswords no
X11Forwarding no
AllowAgentForwarding no
MaxAuthTries 4
LoginGraceTime 30
CONF
if [[ -f "$CONF" ]] && cmp -s "$TMP" "$CONF"; then
    info "$CONF already up to date"
    rm -f "$TMP"
else
    install -m 0644 -o root -g root "$TMP" "$CONF"
    rm -f "$TMP"
    info "wrote $CONF"
fi

sshd -t || { rm -f "$CONF"; die "sshd rejected the config; the drop-in was removed and nothing changed"; }
systemctl try-reload-or-restart ssh.service
info "sshd reloaded"

EFFECTIVE=$(sshd -T -C user="$ADMIN",host=pc,addr="${AIO_LAN_PREFIX}10" 2>/dev/null)
eff_is() { grep -qix "$1 $2" <<<"$EFFECTIVE"; }

echo
check "sshd config is valid" sshd -t
check "effective: passwordauthentication no" eff_is passwordauthentication no
check "effective: kbdinteractiveauthentication no" eff_is kbdinteractiveauthentication no
check "effective: pubkeyauthentication yes" eff_is pubkeyauthentication yes
check "effective: authenticationmethods publickey" eff_is authenticationmethods publickey
check "effective: permitrootlogin no" eff_is permitrootlogin no
check "$AK still holds a key" test -s "$AK"
sshd_running() { systemctl is-active --quiet ssh.service || systemctl is-active --quiet ssh.socket; }
check "sshd is running" sshd_running
finish
echo
echo "Keep this session open. From the PC, open a NEW key login and check it works,"
echo "and that password login is refused (docs/setup.md step 2)."
