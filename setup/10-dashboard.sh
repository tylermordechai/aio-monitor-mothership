#!/usr/bin/env bash
# 10-dashboard.sh: the dashboard on 443 (milestone 5, M-053, M-054).
#   - the connections-per-hour job and its timer (aio-flow-rollup);
#   - checks: grants and credential files, sessions directory, the vendored
#     Cytoscape.js (SHA-256), and the dashboard over HTTPS: sign-in page,
#     redirects when signed out, CSRF refusal, cookie flags, security headers.
#
# Needs sudo: yes (run as aioadmin with sudo). Reboots: no.
# Safe to re-run: yes. Run after a git pull and, in this order: 06 (migration
# 0003, analyst user, grants), 08 (vhost), 09 (packages, tests), then this.
# Usage: sudo bash setup/10-dashboard.sh
# Requirements: MS-SEC-04..09, MS-UI-01, MS-UI-02, MS-UI-08, M-053, M-054, M-056.

source "$(dirname "$0")/lib.sh"
need_root
banner "Dashboard: connections-per-hour timer, sign-in and security checks" "yes" "no"

CRED_DIR="$AIO_ETC/db"
CY_DIR="$AIO_REPO/public/dashboard/assets/vendor/cytoscape-3.34.4"
CY_SHA256=081591ab735b2b6a3b31a3c60f8f3a3e2fee8c92939e0c7820ad1208571b6cd8   # M-054
URL="https://$AIO_IP"
WEBCA="$AIO_ETC/pki/public/web-ca.crt"

[[ -f "$CRED_DIR/analyst.cnf" ]] || die "$CRED_DIR/analyst.cnf missing; re-run 06-mariadb.sh first"
[[ -f "$CRED_DIR/admin.cnf" ]] || die "$CRED_DIR/admin.cnf missing (M-070); re-run 06-mariadb.sh first"
[[ -f "$AIO_REPO/vendor/autoload.php" ]] || die "vendor/ missing; run 09-app.sh first"
grep -q FallbackResource /etc/apache2/sites-available/aio-dashboard.conf || die "the dashboard vhost is the milestone 1 one; re-run 08-apache-php.sh first"

# --- The connections-per-hour job (M-054) -----------------------------------------
CHANGED=0
render aio-flow-rollup.service /etc/systemd/system/aio-flow-rollup.service && CHANGED=1 || true
render aio-flow-rollup.timer /etc/systemd/system/aio-flow-rollup.timer && CHANGED=1 || true
[[ $CHANGED -eq 1 ]] && systemctl daemon-reload
systemctl enable --quiet --now aio-flow-rollup.timer
info "running the job once now"
if systemctl start aio-flow-rollup.service; then
    journalctl -u aio-flow-rollup.service -n 3 --no-pager -o cat 2>/dev/null | tail -n 2 || true
else
    warn "aio-flow-rollup.service failed; see: journalctl -u aio-flow-rollup.service -n 30"
fi

# --- Checks ---------------------------------------------------------------------------
WORK=$(umask 077; mktemp -d)
trap 'rm -rf "$WORK"' EXIT
get() { curl -sS --max-time 15 --cacert "$WEBCA" -D "$WORK/h" -o "$WORK/b" "$@"; }
status_is() { local want=$1; shift; [[ $(get -w '%{http_code}' "$@") == "$want" ]]; }
header_has() { local re=$1; shift; get "$@" >/dev/null && tr -d '\r' < "$WORK/h" | grep -qiE "$re"; }
body_has() { local s=$1; shift; get "$@" >/dev/null && grep -qF "$s" "$WORK/b"; }
cookie_ok() {
    get -c "$WORK/jar" "$URL/login" >/dev/null || return 1
    local c; c=$(tr -d '\r' < "$WORK/h" | grep -i '^set-cookie: __Host-aio=') || return 1
    grep -qi 'secure' <<<"$c" && grep -qi 'httponly' <<<"$c" && grep -qi 'samesite=strict' <<<"$c" && grep -qi 'path=/' <<<"$c" && ! grep -qi 'domain=' <<<"$c"
}
csrf_refused() {
    # A sign-in post without the form's token goes back to the sign-in page
    # and signs no one in, whatever the user name and password.
    local before after
    before=$(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.audit_log WHERE action='sign_in'")
    [[ $(get -w '%{http_code}' -c "$WORK/jar2" -b "$WORK/jar2" -X POST --data 'username=x&password=y' "$URL/login") == 303 ]] || return 1
    tr -d '\r' < "$WORK/h" | grep -qi '^location: /login$' || return 1
    after=$(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.audit_log WHERE action='sign_in'")
    [[ "$before" == "$after" ]]
}
edge_post() {
    # What Edge sent under the old no-referrer policy: the form's token,
    # Origin null, Sec-Fetch-Site same-origin. A made-up user, so the answer
    # must be "Sign-in failed", not "had expired".
    get -c "$WORK/jar3" -b "$WORK/jar3" "$URL/login" >/dev/null || return 1
    local t; t=$(grep -o 'name="_csrf" value="[^"]*"' "$WORK/b" | cut -d'"' -f4)
    get -c "$WORK/jar3" -b "$WORK/jar3" -H 'Origin: null' -H 'Sec-Fetch-Site: same-origin' --data-urlencode "_csrf=$t" --data 'username=nobody-m055&password=wrong-password-1' "$URL/login" >/dev/null || return 1
    body_has 'Sign-in failed' -c "$WORK/jar3" -b "$WORK/jar3" "$URL/login"
}
no_token_signed_out() {
    local before after
    before=$(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.enrollment_tokens")
    status_is 303 -X POST --data 'kind=manual&group=default' "$URL/admin/tokens" || return 1
    after=$(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.enrollment_tokens")
    [[ "$before" == "$after" ]]
}
pool_reads() { sudo -u aio-dashboard test -r "$1"; }
pool_cant_read() { ! sudo -u aio-dashboard test -r "$1"; }
mig3() { [[ $(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.schema_migrations WHERE version='0003_flow_conn_hourly'") == 1 ]]; }
mig4() { [[ $(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.schema_migrations WHERE version='0004_host_page_indexes'") == 1 ]]; }
argon2() { php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'; }
cy_sha() { [[ $(sha256sum "$CY_DIR/cytoscape.min.js" | cut -c1-64) == "$CY_SHA256" ]]; }
job_ran() { [[ -n $(mariadb -N -e "SELECT value FROM $AIO_DB_NAME.job_state WHERE name='flow_rollup.batch_pk'") ]]; }
not_in_docroot() { [[ ! -e "$AIO_REPO/public/dashboard/templates" && -d "$AIO_REPO/templates" ]]; }

echo
check "migration 0003 applied (re-run 06 if not)" mig3
check "migration 0004 applied: host page indexes (re-run 06 if not, M-057)" mig4
check "PHP has Argon2id password hashing (MS-SEC-06)" argon2
check "dashboard pool reads dashboard.cnf" pool_reads "$CRED_DIR/dashboard.cnf"
check "dashboard pool reads analyst.cnf (M-053)" pool_reads "$CRED_DIR/analyst.cnf"
check "dashboard pool reads admin.cnf (M-070)" pool_reads "$CRED_DIR/admin.cnf"
check "dashboard pool can NOT read maint.cnf" pool_cant_read "$CRED_DIR/maint.cnf"
check "dashboard pool can NOT read ingest.cnf" pool_cant_read "$CRED_DIR/ingest.cnf"
check "analyst.cnf is 640 root:aio-dashboard" file_mode_is "$CRED_DIR/analyst.cnf" 640 root aio-dashboard
check "admin.cnf is 640 root:aio-dashboard" file_mode_is "$CRED_DIR/admin.cnf" 640 root aio-dashboard
check "sessions directory is 700 aio-dashboard" file_mode_is /var/lib/php/sessions-aio-dashboard 700 aio-dashboard aio-dashboard
check "templates are outside the web root" not_in_docroot
check "Cytoscape.js 3.34.4 matches its SHA-256 (M-054)" cy_sha
check "Cytoscape.js licence file present" test -s "$CY_DIR/LICENSE"
check "flow-rollup timer is active" systemctl is-active --quiet aio-flow-rollup.timer
check "flow-rollup job has run" job_ran
check "443: sign-in page" body_has 'name="password"' "$URL/login"
check "443: signed out, / redirects to /login" bash -c "[[ \$(curl -sS --max-time 15 --cacert '$WEBCA' -o /dev/null -w '%{http_code} %{redirect_url}' '$URL/') == '303 $URL/login' ]]"
check "443: signed out, /api/map answers 401" status_is 401 "$URL/api/map"
check "443: signed out, a host page redirects" status_is 303 "$URL/hosts/1"
check "443: signed out, a host tab redirects" status_is 303 "$URL/hosts/1?tab=processes"
check "443: signed out, a command-line request goes to sign-in, nothing shown (MS-SEC-07)" status_is 303 -X POST --data 'agent_pk=1&seq=1&ts=x' "$URL/hosts/1/command-line"
check "443: signed out, the enrolment page redirects (M-070)" status_is 303 "$URL/admin/enrolment"
check "443: signed out, making a token goes to sign-in, no token made (M-070)" no_token_signed_out
check "443: signed out, a revoke request goes to sign-in (M-070)" status_is 303 -X POST --data 'agent_id=x&reason=x' "$URL/hosts/1/revoke"
check "443: session cookie __Host-aio is Secure, HttpOnly, SameSite=Strict (MS-SEC-05)" cookie_ok
check "443: sign-in without the CSRF token is refused (MS-SEC-05)" csrf_refused
check "443: CSP allows scripts only from this server" header_has "^content-security-policy: .*script-src 'self';" "$URL/login"
check "443: CSP forbids framing" header_has "^content-security-policy: .*frame-ancestors 'none'" "$URL/login"
check "443: referrer only to the dashboard itself (M-055)" header_has '^referrer-policy: same-origin' "$URL/login"
check "443: Edge-style sign-in post (Origin null, same-origin) passes the CSRF check (M-055)" edge_post
check "443: nothing cached" header_has '^cache-control: no-store' "$URL/login"
check "443: stylesheet served" status_is 200 "$URL/assets/app.css"
check "443: map script served" status_is 200 "$URL/assets/map.js"
check "443: Cytoscape.js served" status_is 200 "$URL/assets/vendor/cytoscape-3.34.4/cytoscape.min.js"
check "443: health page still served" body_has '"service":"dashboard"' "$URL/health.php"
check "443: unknown path when signed out goes to sign-in, not an error" status_is 303 "$URL/no-such-page"

echo
admins=$(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.users WHERE role='admin' AND disabled_at IS NULL")
if [[ "$admins" == 0 ]]; then
    info "No admin account yet. Make yours (the password is asked twice, hidden):"
    info "  sudo php $AIO_REPO/tools/users.php add <your-name> --role=admin --display=\"Your Name\""
else
    info "admin accounts: $admins (list them: sudo php $AIO_REPO/tools/users.php list)"
fi
finish
