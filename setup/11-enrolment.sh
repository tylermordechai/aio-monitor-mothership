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
#   Step 3 (M-062): the ingest user's audit grant, and tools/enrol-check.php:
#   a throwaway agent enrolled over HTTPS on 8443, used, refused, revoked
#   and removed.
#   Step 9 (M-071): the agent CA's CRL: aio-crl.service (the root job
#   tools/jobs/crl-build.php), aio-crl.timer (daily), aio-crl.path (on every
#   revocation), the request file the dashboard may write, and the 8443
#   include that makes Apache check it. Checked live by enrol-check.php: a
#   revoked agent's certificates are refused in the TLS handshake.
#
# Needs sudo: yes (run as aioadmin with sudo). Reboots: no.
# Safe to re-run: yes. Run after a git pull and 09-app.sh (vendor/).
# Usage: sudo bash setup/11-enrolment.sh
# Requirements: MS-SEC-02, MS-SEC-03, MS-SEC-08, MS-SEC-10, MS-ING-06, M-002, M-021, M-037, M-059, M-061, M-071.
# Run 06-mariadb.sh first after a pull that adds a migration (0005), and
# 08-apache-php.sh after one that changes the 8443 vhost (M-071).

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

# --- Step 9: the agent CA's CRL (MS-SEC-02, M-071, M-068) ---------------------------
CRL="$AIO_ETC/pki/public/agent-ca.crl"
CRL_STATE_DIR=/var/lib/aio-mothership/crl
REQ_DIR=/var/lib/aio-mothership/crl-request
REQ="$REQ_DIR/rebuild"
CRL_INC="$AIO_ETC/apache/agent-crl.conf"
grep -q 'IncludeOptional /etc/aio-mothership/apache/agent-crl.conf' /etc/apache2/sites-available/aio-agent-api.conf \
    || die "the 8443 vhost has no CRL include yet; run 08-apache-php.sh first, then this again"
install -d -m 0755 -o root -g root /var/lib/aio-mothership "$CRL_STATE_DIR" "$AIO_ETC/apache"
# The dashboard pool may write the request file, and nothing else here (M-071).
install -d -m 0750 -o root -g aio-dashboard "$REQ_DIR"
[[ -f "$REQ" ]] || : > "$REQ"
chown root:aio-dashboard "$REQ"; chmod 0660 "$REQ"
CHANGED=0
render aio-crl.service /etc/systemd/system/aio-crl.service && CHANGED=1 || true
render aio-crl.timer /etc/systemd/system/aio-crl.timer && CHANGED=1 || true
render aio-crl.path /etc/systemd/system/aio-crl.path && CHANGED=1 || true
[[ $CHANGED -eq 1 ]] && systemctl daemon-reload
info "building the CRL (a second or two)"
if systemctl start aio-crl.service; then
    journalctl -u aio-crl.service -n 2 --no-pager -o cat 2>/dev/null || true
else
    warn "aio-crl.service failed; see: journalctl -u aio-crl.service -n 20"
fi
# Apache checks the CRL only once one exists: an include naming a missing
# file would stop Apache from starting.
if [[ -s "$CRL" ]] && openssl crl -in "$CRL" -CAfile "$AIO_ETC/pki/public/agent-ca.crt" -noout 2>/dev/null; then
    if render apache-aio-agent-crl.conf "$CRL_INC"; then
        if apache2ctl configtest; then
            systemctl reload apache2
            info "8443 now checks agent certificates against the CRL"
        else
            rm -f "$CRL_INC"
            warn "Apache's config test failed with the CRL include; removed it again, nothing reloaded"
        fi
    fi
fi
systemctl enable --quiet --now aio-crl.timer aio-crl.path

crl_verifies() { openssl crl -in "$CRL" -CAfile "$AIO_ETC/pki/public/agent-ca.crt" -noout 2>&1 | grep -q 'verify OK'; }
crl_days() {
    local last next
    last=$(date -d "$(openssl crl -in "$CRL" -noout -lastupdate | cut -d= -f2)" +%s)
    next=$(date -d "$(openssl crl -in "$CRL" -noout -nextupdate | cut -d= -f2)" +%s)
    (( (next - last) / 86400 == $(php -r 'require "'"$AIO_REPO"'/vendor/autoload.php"; echo Aio\Config::load()->int("crl", "valid_days");') ))
}
crl_state_ok() { php -r 'require "'"$AIO_REPO"'/vendor/autoload.php"; $s = Aio\Pki\CrlTrigger::fromConfig(Aio\Config::load())->status(time()); echo $s["message"], "\n"; exit($s["state"] === "ok" ? 0 : 1);'; }
apache_loads_crl() { apache2ctl -t -D DUMP_INCLUDES 2>/dev/null | grep -q 'agent-crl.conf'; }
pool_writes_req() { as aio-dashboard test -w "$REQ"; }
pool_cant_add_files() { ! as aio-dashboard touch "$REQ_DIR/x" 2>/dev/null; }
pool_cant_write_crl() { ! as aio-dashboard test -w "$CRL" && ! as aio-dashboard test -w "$CRL_STATE_DIR/state.json"; }
build_ms() { php -r '$s = json_decode((string) @file_get_contents("/var/lib/aio-mothership/crl/state.json"), true); echo (int) ($s["build_ms"] ?? 0);'; }
request_rebuilds() {
    local before i
    before=$(build_ms)
    as aio-dashboard php -r 'require "'"$AIO_REPO"'/vendor/autoload.php"; Aio\Pki\CrlTrigger::fromConfig(Aio\Config::load())->request();' || return 1
    for i in $(seq 1 60); do
        [[ $(build_ms) -gt $before ]] && return 0
        sleep 1
    done
    return 1
}
crl_log_clean() { ! journalctl -u aio-crl.service --since "$STARTED" --no-pager -o cat 2>/dev/null | grep -qE 'BEGIN|PRIVATE'; }

echo
check "the CRL verifies against the agent CA (M-071)" crl_verifies
check "the CRL is valid for crl.valid_days (30, confirm, M-068)" crl_days
check "the CRL's state is ok (dashboard and health page)" crl_state_ok
check "CRL: 644 root:root" file_mode_is "$CRL" 644 root root
check "8443 loads the CRL (Apache include)" apache_loads_crl
check "aio-crl.timer (daily) is active" systemctl is-active --quiet aio-crl.timer
check "aio-crl.path (on every revocation) is active" systemctl is-active --quiet aio-crl.path
check "the dashboard pool may write the request file" pool_writes_req
check "the dashboard pool can't add files beside it" pool_cant_add_files
check "the dashboard pool can't write the CRL or its state" pool_cant_write_crl
info "a rebuild request from the dashboard pool (up to 60 s)"
check "a request from the dashboard pool starts a rebuild (aio-crl.path)" request_rebuilds
check "the CRL job's log has no key (MS-SEC-10)" crl_log_clean

# --- Step 3: POST /api/v1/enroll (MS-ING-06, M-062) ---------------------------------
grants() { mariadb -N -e "SHOW GRANTS FOR 'aio_ingest'@'localhost'"; }
ingest_audit_insert_only() { local g; g=$(grants | grep '`audit_log`'); [[ "$g" == *"GRANT INSERT ON"* ]] && [[ "$g" != *SELECT* && "$g" != *UPDATE* && "$g" != *DELETE* ]]; }
check "aio_ingest may only INSERT into audit_log (M-062; re-run 06 if not)" ingest_audit_insert_only
mig6() { [[ $(db "SELECT COUNT(*) FROM schema_migrations WHERE version='0006_undeliverable'") == 1 ]]; }
check "migration 0006 applied: the undeliverable count (M-063; re-run 06 if not)" mig6
echo
info "Enrolment through the agent API on 8443 (tools/enrol-check.php: one throwaway agent, removed after)"
while IFS= read -r line; do
    case "$line" in
        "PASS: "*) pass "${line#PASS: }" ;;
        "FAIL: "*) fail "${line#FAIL: }" ;;
        "PHP_EXIT: 0") ;;
        "PHP_EXIT: "*) fail "tools/enrol-check.php ended with exit code ${line#PHP_EXIT: }" ;;
        *) echo "  $line" ;;
    esac
done < <(php "$AIO_REPO/tools/enrol-check.php" "https://$AIO_IP:8443" 2>&1; echo "PHP_EXIT: $?")

echo
info "The signer's log: journalctl -u 'aio-signer@*' --since today"
info "Tokens: sudo php bin/token-create.php list --all"
info "The CRL: journalctl -u aio-crl.service -n 5; rebuild now: sudo systemctl start aio-crl.service"
finish
