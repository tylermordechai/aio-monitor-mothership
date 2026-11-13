#!/usr/bin/env bash
# 04-time.sh: set the clock to UTC and make sure it is kept in sync (NTP).
# Certificates, the ingest timestamp window and the heartbeat skew all depend
# on a correct clock.
#
# Needs sudo: yes. Reboots: no. Safe to re-run: yes.
# Requirements: MS-ING-05 (timestamp window, M-007), MS-ING-08, M-035.

source "$(dirname "$0")/lib.sh"
need_root
banner "timezone UTC, NTP time sync on" "yes" "no"

if [[ "$(timedatectl show -p Timezone --value)" != "Etc/UTC" && "$(timedatectl show -p Timezone --value)" != "UTC" ]]; then
    timedatectl set-timezone Etc/UTC
    info "timezone set to Etc/UTC"
fi
timedatectl set-ntp true
# Either chrony or systemd-timesyncd is fine; whichever Ubuntu installed.
if systemctl list-unit-files chrony.service >/dev/null 2>&1 && systemctl is-enabled --quiet chrony.service 2>/dev/null; then
    TIMESVC=chrony.service
else
    TIMESVC=systemd-timesyncd.service
fi
info "time service: $TIMESVC"

synced() {
    local i
    for i in $(seq 1 30); do
        [[ "$(timedatectl show -p NTPSynchronized --value)" == "yes" ]] && return 0
        sleep 2
    done
    return 1
}
tz_utc() { [[ "$(timedatectl show -p Timezone --value)" =~ ^(Etc/)?UTC$ ]]; }

echo
timedatectl
echo
check "timezone is UTC" tz_utc
check "NTP is on" test "$(timedatectl show -p NTP --value)" = yes
check "$TIMESVC is running" systemctl is-active --quiet "$TIMESVC"
check "clock is synchronised (waited up to 60 s)" synced
finish
