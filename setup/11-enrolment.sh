#!/usr/bin/env bash
# 11-enrolment.sh: enrolment (milestone 3, M-059).
#   Step 1, the signing service: aio-signer.socket on /run/aio-signer.sock,
#   one aio-signer@ copy per request as the system user aio-signer, the only
#   non-root reader of the agent CA key (MS-SEC-02, M-037).
#   Checks: key and socket permissions, who can and can't reach them, the
#   openssl version, and real signing through the socket as aio-ingest: a good
#   CSR is signed with the §2.3 profile; an RSA key, a tampered CSR and a CN
#   that isn't the agent ID are refused.
#
# Needs sudo: yes (run as aioadmin with sudo). Reboots: no.
# Safe to re-run: yes. Run after a git pull and 09-app.sh (vendor/).
# Usage: sudo bash setup/11-enrolment.sh
# Requirements: MS-SEC-02, MS-ING-06, M-002, M-037, M-059.

source "$(dirname "$0")/lib.sh"
need_root
banner "Enrolment: the agent CA signing service" "yes" "no"

CA_DIR="$AIO_ETC/pki/agent-ca"
SOCK=/run/aio-signer.sock

[[ -f "$CA_DIR/ca.key" ]] || die "$CA_DIR/ca.key missing; run 07-pki.sh first"
getent passwd aio-signer >/dev/null || die "user aio-signer missing; run 07-pki.sh first"
getent group aio-ingest >/dev/null || die "group aio-ingest missing; run 08-apache-php.sh first"
[[ -f "$AIO_REPO/vendor/autoload.php" ]] || die "vendor/ missing; run 09-app.sh first"

# -not_before needs OpenSSL 3.4 or later (Ubuntu 26.04 ships 3.5).
openssl_ok() {
    local v; v=$(openssl version | awk '{print $2}')
    [[ "$(printf '%s\n3.4.0\n' "$v" | sort -V | head -n1)" == "3.4.0" ]]
}
openssl_ok || die "openssl $(openssl version | awk '{print $2}') is too old for the signer (needs 3.4 or later)"

# --- The signing service ----------------------------------------------------------
CHANGED=0
render aio-signer.socket /etc/systemd/system/aio-signer.socket && CHANGED=1 || true
render aio-signer@.service /etc/systemd/system/aio-signer@.service && CHANGED=1 || true
if [[ $CHANGED -eq 1 ]]; then
    systemctl daemon-reload
    systemctl restart aio-signer.socket 2>/dev/null || true
fi
systemctl enable --quiet --now aio-signer.socket

# --- Checks ---------------------------------------------------------------------------
as() { local u=$1; shift; runuser -u "$u" -- "$@"; }
sock_mode_ok() { [[ "$(stat -c '%F %a %U %G' "$SOCK" 2>/dev/null)" == "socket 660 root aio-ingest" ]]; }
can_connect() { as "$1" php -r '$c = @stream_socket_client("unix:///run/aio-signer.sock", $n, $s, 3); exit($c === false ? 1 : 0);'; }
signer_check() { as aio-ingest php "$AIO_REPO/tools/signer-check.php" "$1"; }
log_clean() {
    # The signer logs agent ID, serial and outcome; never a CSR or a key (MS-SEC-10).
    local out
    out=$(journalctl --since "$1" --no-pager -o cat -u 'aio-signer@*' 2>/dev/null)
    grep -q 'aio-signer: signed agent_id=' <<<"$out" && ! grep -qE 'BEGIN|PRIVATE' <<<"$out"
}
refuses_root() { local rc=0; php "$AIO_REPO/bin/signer.php" </dev/null >/dev/null 2>&1 || rc=$?; [[ $rc -eq 2 ]]; }
STARTED=$(date '+%Y-%m-%d %H:%M:%S')

check "openssl is 3.4 or later ($(openssl version | awk '{print $2}'))" openssl_ok
check "agent CA key: 440 root:aio-signer (MS-SEC-02)" file_mode_is "$CA_DIR/ca.key" 440 root aio-signer
check "agent CA dir: 750 root:aio-signer" file_mode_is "$CA_DIR" 750 root aio-signer
check_not "the ingest pool user can't read the CA key" as aio-ingest test -r "$CA_DIR/ca.key"
check_not "the dashboard pool user can't read the CA key" as aio-dashboard test -r "$CA_DIR/ca.key"
check_not "www-data can't read the CA key" as www-data test -r "$CA_DIR/ca.key"
check "aio-signer can read the CA key" as aio-signer test -r "$CA_DIR/ca.key"
check "signer socket is listening" systemctl is-active --quiet aio-signer.socket
check "signer socket: 660 root:aio-ingest" sock_mode_ok
check "the ingest pool user can reach the signer" can_connect aio-ingest
check_not "the dashboard pool user can't reach the signer" can_connect aio-dashboard
check_not "www-data can't reach the signer" can_connect www-data
check "the signer refuses to run as root" refuses_root
check "a good CSR is signed with the §2.3 profile, chained to the agent CA" signer_check ok
check "an RSA key is refused (csr_invalid, key_type)" signer_check rsa
check "a tampered CSR is refused (csr_invalid, signature)" signer_check tampered
check "a CN that isn't the agent ID is refused (csr_invalid, cn)" signer_check wrong-cn
check "the signer's log has the signing and no CSR or key" log_clean "$STARTED"

echo
info "The signer's log: journalctl -u 'aio-signer@*' --since today"
finish
