#!/usr/bin/env bash
# 03-os-updates.sh: install all pending updates, and turn on automatic
# security updates (unattended-upgrades) without automatic reboots.
#
# Needs sudo: yes. Reboots: no. It prints whether a reboot is needed; then
# reboot by hand (sudo reboot) and run it again to confirm.
# Safe to re-run: yes.
# Requirements: MS-SEC-12, MS-OPS-05, M-035, M-039.

source "$(dirname "$0")/lib.sh"
need_root
banner "OS updates now, then automatic security updates (no auto reboot)" "yes" "no (tells you if one is needed)"

. /etc/os-release
info "OS: $PRETTY_NAME"
[[ "$VERSION_ID" == "26.04" ]] || warn "expected Ubuntu 26.04 (M-029), found $VERSION_ID"

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
info "apt-get update"
apt-get update -q
info "apt-get full-upgrade"
apt-get full-upgrade -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold
apt_install unattended-upgrades
apt-get autoremove -y -q

render apt-20auto-upgrades /etc/apt/apt.conf.d/20auto-upgrades || info "20auto-upgrades already up to date"
render apt-52aio-unattended-upgrades /etc/apt/apt.conf.d/52aio-unattended-upgrades || info "52aio-unattended-upgrades already up to date"
systemctl enable --now apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true

APTCONF=$(apt-config dump)
apt_is() { grep -qF "$1 \"$2\";" <<<"$APTCONF"; }
# Phased updates (Ubuntu rolls some out gradually) show as upgradable but are
# not installed yet by design, so count what apt would actually install.
no_pending() { [[ $(apt-get -s full-upgrade 2>/dev/null | grep -c '^Inst ') -eq 0 ]]; }

echo
check "no packages left to upgrade" no_pending
check "unattended-upgrades installed" dpkg -s unattended-upgrades
check "APT::Periodic::Update-Package-Lists is 1" apt_is APT::Periodic::Update-Package-Lists 1
check "APT::Periodic::Unattended-Upgrade is 1" apt_is APT::Periodic::Unattended-Upgrade 1
check "Unattended-Upgrade::Automatic-Reboot is false" apt_is Unattended-Upgrade::Automatic-Reboot false
check "apt-daily-upgrade.timer enabled" systemctl is-enabled --quiet apt-daily-upgrade.timer
check "unattended-upgrade dry run succeeds" unattended-upgrade --dry-run
if [[ -f /var/run/reboot-required ]]; then
    fail "reboot required ($(tr '\n' ' ' < /var/run/reboot-required.pkgs 2>/dev/null || echo 'see /var/run/reboot-required')). Run: sudo reboot, then run this script again"
else
    pass "no reboot required"
fi
finish
