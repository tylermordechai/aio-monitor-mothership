#!/usr/bin/env bash
# 05-firewall.sh: ufw allows 22, 443 and 8443 from 192.168.0.0/24 only; all
# other inbound traffic is blocked. Outbound stays open (updates, threat lists).
#
# Needs sudo: yes. Reboots: no. Safe to re-run: yes; broad rules such as
# "22/tcp from anywhere" are removed each run.
# It refuses to start if your SSH session comes from outside 192.168.0.0/24,
# so it can't lock you out.
# Requirements: MS-SEC-01, M-025, CLAUDE.md "no internet-facing ports".

source "$(dirname "$0")/lib.sh"
need_root
banner "firewall: 22, 443, 8443 from $AIO_LAN only" "yes" "no"

# Every established SSH session must come from the LAN.
mapfile -t PEERS < <(ss -Htn state established '( sport = :22 )' | awk '{print $4}' | sed -E 's/^\[?(::ffff:)?//; s/\]?:[0-9]+$//' | sort -u)
for p in "${PEERS[@]}"; do
    [[ "$p" == "$AIO_LAN_PREFIX"* ]] || die "an SSH session comes from $p, outside $AIO_LAN; enabling the firewall would cut it off"
done
info "SSH sessions from: ${PEERS[*]:-none}"

command -v ufw >/dev/null || apt_install ufw

ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw default deny routed >/dev/null

# Remove broad rules that a default install or a package profile may add.
for rule in 22 22/tcp OpenSSH 80 80/tcp 443 443/tcp 8443 8443/tcp "Apache" "Apache Full" "Apache Secure"; do
    ufw --force delete allow "$rule" >/dev/null 2>&1 || true
done

ufw allow from "$AIO_LAN" to any port 22 proto tcp comment 'aio: SSH (M-025)' >/dev/null
ufw allow from "$AIO_LAN" to any port 443 proto tcp comment 'aio: dashboard (M-025)' >/dev/null
ufw allow from "$AIO_LAN" to any port 8443 proto tcp comment 'aio: agent ingest, mTLS (M-025)' >/dev/null
ufw logging low >/dev/null
ufw --force enable >/dev/null
systemctl enable --quiet ufw

STATUS=$(ufw status verbose)
echo
echo "$STATUS"
echo

has_rule() { grep -Eq "^$1/tcp +ALLOW IN +${AIO_LAN//./\.}( |$)" <<<"$STATUS"; }
only_lan_rules() {
    # Every ALLOW rule must be from the LAN, and only for 22, 443 or 8443.
    local bad
    bad=$(grep -E 'ALLOW' <<<"$STATUS" | grep -Ev "^(22|443|8443)/tcp +ALLOW IN +${AIO_LAN//./\.}( |$)" || true)
    [[ -z "$bad" ]]
}

check "ufw is active" grep -q '^Status: active' <<<"$STATUS"
check "default: deny incoming" grep -q 'deny (incoming)' <<<"$STATUS"
check "default: allow outgoing" grep -q 'allow (outgoing)' <<<"$STATUS"
check "22/tcp allowed from $AIO_LAN" has_rule 22
check "443/tcp allowed from $AIO_LAN" has_rule 443
check "8443/tcp allowed from $AIO_LAN" has_rule 8443
check "no other allow rules (no 'Anywhere', no IPv6 allows)" only_lan_rules
check "ufw enabled at boot" systemctl is-enabled --quiet ufw
finish
