#!/usr/bin/env bash
# 11-enrolment.sh: enrolment (milestone 3, M-059).
#   Step 1, the signing service: aio-signer.socket on /run/aio-signer.sock,
#   one aio-signer@ copy per request as the system user aio-signer, the only
#   non-root reader of the agent CA key (MS-SEC-02, M-037).
#   Checks: key and socket permissions, who can and can't reach them, the
#   openssl version, and real signing through the socket as aio-ingest: a good
#   CSR is signed with the §2.3 profile; an RSA key, a tampered CSR and a CN
#   that isn't the agent ID are refused.
#   Step 2 (M-061): bin/agent-ca.php and bin/token-create.php, with one
#   throwaway token made, checked and cancelled (never printed).
#
# Needs sudo: yes (run as aioadmin with sudo). Reboots: no.
# Safe to re-run: yes. Run after a git pull and 09-app.sh (vendor/).
# Usage: sudo bash setup/11-enrolment.sh
# Requirements: MS-SEC-02, MS-SEC-03, MS-SEC-08, MS-SEC-10, MS-ING-06, M-002, M-021, M-037, M-059, M-061.
# Run 06-mariadb.sh first after a pull that adds a migration (0005).

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

# --- Step 2: tokens and the CA export (MS-SEC-03, M-021, M-061) ---------------------
# One throwaway token, made and cancelled here; it is never printed.
TOK=""; TOK_ID=""
TOKCLI=(php "$AIO_REPO/bin/token-create.php")
sha_hex() { printf '%s' "$1" | sha256sum | awk '{print $1}'; }
db() { mariadb -N -B "$AIO_DB_NAME" -e "$1"; }
mig5() { [[ $(db "SELECT COUNT(*) FROM schema_migrations WHERE version='0005_token_cancel'") == 1 ]]; }
ca_fp_matches() {
    local want got
    want=$(awk -F': ' '/^agent-ca SHA-256/{print $2}' "$AIO_ETC/pki/public/fingerprints.txt" | tr -d ':' | tr 'A-F' 'a-f')
    got=$(php "$AIO_REPO/bin/agent-ca.php" --sha256)
    [[ -n "$want" && "$got" == "$want" ]]
}
ca_pem_matches() { [[ "$(php "$AIO_REPO/bin/agent-ca.php" --pem | openssl x509 -noout -fingerprint -sha256)" == "$(openssl x509 -in "$AIO_ETC/pki/public/agent-ca.crt" -noout -fingerprint -sha256)" ]]; }
ca_as_user() { as aio-dashboard php "$AIO_REPO/bin/agent-ca.php" --sha256; }
tok_create() {
    local err; err=$(mktemp)
    TOK=$("${TOKCLI[@]}" create --note="11-enrolment.sh check, cancelled at once" 2>"$err") || { rm -f "$err"; return 1; }
    TOK_ID=$(sed -n 's/^Token ID \([0-9]*\):.*/\1/p' "$err"); rm -f "$err"
    [[ "$TOK" =~ ^aioe_[A-Za-z0-9_-]{43}$ && -n "$TOK_ID" ]]
}
tok_hash_only() {
    [[ $(db "SELECT COUNT(*) FROM enrollment_tokens WHERE id=$TOK_ID AND token_sha256=UNHEX('$(sha_hex "$TOK")') AND token_kind='manual' AND created_by_name='cli:${SUDO_USER:-root}' AND TIMESTAMPDIFF(HOUR, created_at, expires_at)=24") == 1 ]]
}
tok_not_in_audit() { ! db "SELECT CONCAT_WS(' ', username, target_id, details) FROM audit_log WHERE action LIKE 'enrollment_token%'" | grep -qF "${TOK:5}"; }
tok_audited() { [[ $(db "SELECT COUNT(*) FROM audit_log WHERE action='enrollment_token_created' AND target_id='$TOK_ID'") == 1 ]]; }
tok_listed() { "${TOKCLI[@]}" list | grep -qE "^$TOK_ID +manual +open "; }
tok_cancel() { "${TOKCLI[@]}" cancel "$TOK_ID"; }
tok_cancelled() { "${TOKCLI[@]}" list --all | grep -qE "^$TOK_ID +manual +cancelled " && [[ $(db "SELECT COUNT(*) FROM audit_log WHERE action='enrollment_token_cancelled' AND target_id='$TOK_ID'") == 1 ]]; }
tok_cancel_again() { "${TOKCLI[@]}" cancel "$TOK_ID"; }
tok_days_refused() { local n; n=$(db "SELECT COUNT(*) FROM enrollment_tokens"); ! "${TOKCLI[@]}" create --kind=deployment --days=8 && [[ $(db "SELECT COUNT(*) FROM enrollment_tokens") == "$n" ]]; }
tok_needs_root() { as aio-ingest "${TOKCLI[@]}" list; }

check "migration 0005 applied (re-run 06-mariadb.sh if not)" mig5
check "bin/agent-ca.php: SHA-256 equals fingerprints.txt" ca_fp_matches
check "bin/agent-ca.php: the PEM is the public agent CA" ca_pem_matches
check "bin/agent-ca.php works without sudo (public)" ca_as_user
check "token create: aioe_ plus 43 characters, only the token on stdout" tok_create
check "the database holds its SHA-256, manual, 24 h, made by cli:${SUDO_USER:-root}" tok_hash_only
check "the creation is audited" tok_audited
check "the token is not in the audit log (MS-SEC-10)" tok_not_in_audit
check "token list shows it open" tok_listed
check "token cancel" tok_cancel
check "it is listed as cancelled, and the cancel is audited" tok_cancelled
check_not "a second cancel is refused" tok_cancel_again
check "a deployment token of 8 days is refused, nothing written (M-021)" tok_days_refused
check_not "the token CLI doesn't work without root (aio_maint, M-036)" tok_needs_root
TOK=""

echo
info "The signer's log: journalctl -u 'aio-signer@*' --since today"
info "Tokens: sudo php bin/token-create.php list --all"
finish
