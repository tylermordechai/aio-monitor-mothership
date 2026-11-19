#!/usr/bin/env bash
# 09-app.sh: the mothership's PHP application (milestone 2: ingest API).
#   - PHP extensions and Composer from Ubuntu's archive (M-029, M-044);
#   - composer install (PHPUnit too, unless --production);
#   - /etc/aio-mothership/app.ini, written once and never overwritten;
#   - /run/aio-ingest for the ingest back-pressure slots (§8.2);
#   - the aio_test database and user for the integration tests (dev VM);
#   - checks: the agent API answers with the contract's JSON errors, and the
#     unit and integration tests pass.
#
# Needs sudo: yes (run as aioadmin with sudo). Reboots: no.
# Safe to re-run: yes. Run 06, 07 and 08 first (after a git pull, re-run 06
# for new migrations and grants, and 08 for vhost changes).
# Usage: sudo bash setup/09-app.sh [--production]
# Requirements: MS-ING-01, 02, 09, MS-SEC-04, MS-OPS-07, M-043..M-048.

source "$(dirname "$0")/lib.sh"
need_root
banner "PHP app: Composer packages, app.ini, ingest state dir, test database, API smoke test" "yes" "no"

PRODUCTION=0
[[ "${1:-}" == "--production" ]] && PRODUCTION=1
OWNER=${SUDO_USER:-}
[[ -n "$OWNER" && "$OWNER" != root ]] || die "run it with sudo from the aioadmin account (SUDO_USER is needed)"
CRED_DIR="$AIO_ETC/db"
[[ -f "$CRED_DIR/ingest.cnf" ]] || die "$CRED_DIR/ingest.cnf missing; run 06-mariadb.sh first"
getent passwd aio-ingest >/dev/null || die "user aio-ingest missing; run 08-apache-php.sh first"

# --- Packages ---------------------------------------------------------------------
apt_get update -q
apt_install composer unzip "php$AIO_PHP_VERSION-xml" "php$AIO_PHP_VERSION-mbstring" "php$AIO_PHP_VERSION-curl"
systemctl restart "php$AIO_PHP_VERSION-fpm"

# --- Composer ---------------------------------------------------------------------
if [[ $PRODUCTION -eq 1 ]]; then COMPOSER_FLAGS=(--no-dev); else COMPOSER_FLAGS=(); fi
info "composer install ${COMPOSER_FLAGS[*]} (as $OWNER)"
sudo -u "$OWNER" -H env COMPOSER_NO_INTERACTION=1 composer install --working-dir="$AIO_REPO" \
    --no-progress --optimize-autoloader "${COMPOSER_FLAGS[@]}" </dev/null

# --- app.ini (never overwritten) -------------------------------------------------
if [[ ! -f "$AIO_ETC/app.ini" ]]; then
    install -m 0644 -o root -g root "$AIO_SETUP_DIR/templates/app.ini" "$AIO_ETC/app.ini"
    info "wrote $AIO_ETC/app.ini"
else
    info "$AIO_ETC/app.ini exists; left as it is"
fi

# --- Ingest state dir (tmpfs, recreated at boot) ---------------------------------
printf 'd /run/aio-ingest 0700 aio-ingest aio-ingest -\n' > /etc/tmpfiles.d/aio-mothership.conf
systemd-tmpfiles --create /etc/tmpfiles.d/aio-mothership.conf

# --- Test database (integration tests; dev VM) -----------------------------------
TEST_CNF="$CRED_DIR/test.cnf"
SOCKET=$(awk -F= '/^socket=/{print $2}' "$CRED_DIR/ingest.cnf")
pw=""
[[ -f "$TEST_CNF" ]] && pw=$(awk -F= '/^password=/{print $2}' "$TEST_CNF")
if [[ -z "$pw" ]]; then
    pw=$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9'); pw=${pw:0:40}
    info "generated a password for aio_test"
fi
tmp=$(umask 077; mktemp "$CRED_DIR/.tmp.XXXXXX")
printf '# aio_test: integration tests only, database aio_test (M-044). No real data.\n[client]\nuser=aio_test\npassword=%s\nsocket=%s\n' "$pw" "$SOCKET" > "$tmp"
chown "root:$OWNER" "$tmp"; chmod 0640 "$tmp"; mv -f "$tmp" "$TEST_CNF"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS aio_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'aio_test'@'localhost' IDENTIFIED BY '$pw';
ALTER USER 'aio_test'@'localhost' IDENTIFIED BY '$pw';
GRANT ALL PRIVILEGES ON aio_test.* TO 'aio_test'@'localhost';
SQL
unset pw

# --- Checks ---------------------------------------------------------------------------
URL="https://$AIO_IP:8443"
AGENTCA="$AIO_ETC/pki/public/agent-ca.crt"
WORK=$(umask 077; mktemp -d)
trap 'rm -rf "$WORK"' EXIT
# A throwaway client certificate from the agent CA that is NOT registered:
# the API must answer 403 certificate_unknown (MS-ING-02). Deleted on exit.
(umask 077
 openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$WORK/c.key" 2>/dev/null
 openssl req -new -key "$WORK/c.key" -subj "/CN=setup-selftest" -out "$WORK/c.csr" 2>/dev/null
 printf '[c]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n' > "$WORK/c.cnf"
 openssl x509 -req -in "$WORK/c.csr" -CA "$AIO_ETC/pki/agent-ca/ca.crt" -CAkey "$AIO_ETC/pki/agent-ca/ca.key" \
     -set_serial "0x$(openssl rand -hex 16)" -days 1 -sha384 -extfile "$WORK/c.cnf" -extensions c -out "$WORK/c.crt" 2>/dev/null)
printf '{"x":1}\n' | gzip -c > "$WORK/b.gz"

# api_is STATUS CODE curl-args... : the answer has that HTTP status and error code.
api_is() {
    local want_status=$1 want_code=$2; shift 2
    local out status
    out=$(curl -sS --max-time 10 --cacert "$AGENTCA" -o "$WORK/resp" -w '%{http_code}' "$@") || return 1
    status=$out
    [[ "$status" == "$want_status" ]] && grep -q "\"code\":\"$want_code\"" "$WORK/resp"
}
POST_BATCH=(-X POST -H 'Content-Type: application/x-ndjson' -H 'Content-Encoding: gzip' --data-binary "@$WORK/b.gz")
pool_reads_cnf() { sudo -u aio-ingest test -r "$CRED_DIR/ingest.cnf"; }
pool_cant_read_maint() { ! sudo -u aio-ingest test -r "$CRED_DIR/maint.cnf"; }
mig_applied() { [[ $(mariadb -N -e "SELECT COUNT(*) FROM $AIO_DB_NAME.schema_migrations WHERE version='0002_lifecycle_link_index_keys'") == 1 ]]; }
ingest_can_update_batches() { mariadb -N -e "SHOW GRANTS FOR 'aio_ingest'@'localhost'" | grep -q 'UPDATE.*`ingest_batches`'; }
unit_tests() { sudo -u "$OWNER" -H php "$AIO_REPO/vendor/bin/phpunit" -c "$AIO_REPO/phpunit.xml" --testsuite unit; }
integration_tests() { sudo -u "$OWNER" -H env AIO_TEST_CNF="$TEST_CNF" php "$AIO_REPO/vendor/bin/phpunit" -c "$AIO_REPO/phpunit.xml" --testsuite integration; }

echo
check "composer installed" command -v composer
check "vendor/autoload.php present" test -f "$AIO_REPO/vendor/autoload.php"
check "PHP has pdo_mysql, openssl, zlib, curl, mbstring" bash -c "php -m | grep -qx pdo_mysql && php -m | grep -qx openssl && php -m | grep -qx zlib && php -m | grep -qx curl && php -m | grep -qx mbstring"
check "migration 0002 applied (re-run 06 if not)" mig_applied
check "aio_ingest may update ingest_batches (re-run 06 if not)" ingest_can_update_batches
check "ingest pool can read ingest.cnf (re-run 06 if not)" pool_reads_cnf
check "ingest pool can NOT read maint.cnf" pool_cant_read_maint
check "/run/aio-ingest is 700 aio-ingest" file_mode_is /run/aio-ingest 700 aio-ingest aio-ingest
check "app.ini present" test -f "$AIO_ETC/app.ini"
check "API: no client certificate -> 401 client_certificate_required" api_is 401 client_certificate_required "${POST_BATCH[@]}" "$URL/api/v1/ingest"
check "API: unregistered certificate -> 403 certificate_unknown" api_is 403 certificate_unknown --cert "$WORK/c.crt" --key "$WORK/c.key" "${POST_BATCH[@]}" "$URL/api/v1/ingest"
check "API: GET on ingest -> 405 method_not_allowed" api_is 405 method_not_allowed --cert "$WORK/c.crt" --key "$WORK/c.key" "$URL/api/v1/ingest"
check "API: unknown path -> 404 not_found" api_is 404 not_found "$URL/api/v1/nothing-here"
check "API: heartbeat without a certificate -> 401" api_is 401 client_certificate_required -X POST -H 'Content-Type: application/json' --data '{}' "$URL/api/v1/heartbeat"
check "health page still served" bash -c "curl -sS --max-time 10 --cacert '$AGENTCA' '$URL/health.php' | grep -q '\"service\":\"agent-api\"'"
if [[ $PRODUCTION -eq 0 ]]; then
    echo; info "Unit tests"
    unit_tests 2>&1 | tail -n 4
    check "unit tests pass" unit_tests
    echo; info "Integration tests (database aio_test)"
    integration_tests 2>&1 | tail -n 12
    check "integration tests pass" integration_tests
fi
finish
