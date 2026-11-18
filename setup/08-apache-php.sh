#!/usr/bin/env bash
# 08-apache-php.sh: Apache 2.4 (mpm_event) and PHP 8.5-FPM from Ubuntu, two
# PHP-FPM pools with their own system users, and two vhosts that serve only a
# health page for now:
#   - 8443 agent API: TLS 1.3 only, client certificate optional (agent CA);
#   - 443 dashboard: TLS 1.2 or 1.3, web CA certificate.
# Nothing listens on port 80.
#
# Needs sudo: yes. Reboots: no (reloads Apache and PHP-FPM).
# Safe to re-run: yes. Run 07-pki.sh first.
# Requirements: MS-SEC-01, MS-SEC-04, MS-SEC-05, MS-ING-01, M-001, M-019, M-038.

source "$(dirname "$0")/lib.sh"
need_root
banner "Apache + PHP $AIO_PHP_VERSION-FPM, vhosts 8443 and 443 with health pages" "yes" "no (reloads Apache, PHP-FPM)"

PKI="$AIO_ETC/pki"
for f in "$PKI/server/agent-api.crt" "$PKI/server/dashboard.crt" "$PKI/public/agent-ca.crt" "$PKI/public/web-ca.crt"; do
    [[ -f "$f" ]] || die "$f missing; run 07-pki.sh first"
done

# --- Packages (Ubuntu archive only) --------------------------------------------
apt_get update -q
apt_install apache2 php-fpm php-cli php-mysql curl
PHPV=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
[[ "$PHPV" == "$AIO_PHP_VERSION" ]] || die "PHP $PHPV installed, expected $AIO_PHP_VERSION (M-029)"
FPM="php$PHPV-fpm"
PHP_ETC=/etc/php/$PHPV

# --- System users for the two pools ------------------------------------------------
for u in aio-ingest aio-dashboard; do
    if ! getent passwd "$u" >/dev/null; then
        useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --user-group "$u"
        info "created system user $u"
    fi
done
install -d -m 0700 -o aio-dashboard -g aio-dashboard /var/lib/php/sessions-aio-dashboard

# --- PHP --------------------------------------------------------------------------
CHANGED_FPM=0
render php-90-aio-mothership.ini "$PHP_ETC/fpm/conf.d/90-aio-mothership.ini" && CHANGED_FPM=1 || true
render php-90-aio-mothership.ini "$PHP_ETC/cli/conf.d/90-aio-mothership.ini" || true
render php-fpm-aio-ingest.conf "$PHP_ETC/fpm/pool.d/aio-ingest.conf" && CHANGED_FPM=1 || true
render php-fpm-aio-dashboard.conf "$PHP_ETC/fpm/pool.d/aio-dashboard.conf" && CHANGED_FPM=1 || true
if [[ -f "$PHP_ETC/fpm/pool.d/www.conf" ]]; then
    mv "$PHP_ETC/fpm/pool.d/www.conf" "$PHP_ETC/fpm/pool.d/www.conf.disabled"
    info "default www pool disabled"
    CHANGED_FPM=1
fi
"php-fpm$PHPV" -t 2>&1 | tail -n 2
systemctl enable --quiet "$FPM"
if [[ $CHANGED_FPM -eq 1 ]]; then systemctl restart "$FPM"; else systemctl start "$FPM"; fi

# --- Apache -----------------------------------------------------------------------
a2dismod -q -f mpm_prefork "php$PHPV" >/dev/null 2>&1 || true
a2dismod -q -f status autoindex >/dev/null 2>&1 || true
a2enmod -q mpm_event ssl proxy proxy_fcgi setenvif headers socache_shmcb >/dev/null
a2dissite -q 000-default default-ssl >/dev/null 2>&1 || true
# Ubuntu's security.conf (ServerTokens OS, ServerSignature On) loads after
# aio-mothership.conf and would override it; aio-mothership.conf sets all of
# what it sets.
a2disconf -q serve-cgi-bin "php$PHPV-fpm" security >/dev/null 2>&1 || true

render apache-ports.conf /etc/apache2/ports.conf || true
render apache-aio-mothership.conf /etc/apache2/conf-available/aio-mothership.conf || true
render apache-aio-agent-api.conf /etc/apache2/sites-available/aio-agent-api.conf || true
render apache-aio-dashboard.conf /etc/apache2/sites-available/aio-dashboard.conf || true
a2enconf -q aio-mothership >/dev/null
a2ensite -q aio-agent-api aio-dashboard >/dev/null

apache2ctl configtest || die "Apache config test failed; nothing reloaded"
systemctl enable --quiet apache2
systemctl restart apache2

# --- Checks -----------------------------------------------------------------------
URL_DASH="https://$AIO_IP/health.php"
URL_AGENT="https://$AIO_IP:8443/health.php"
WEBCA="$PKI/public/web-ca.crt"
AGENTCA="$PKI/public/agent-ca.crt"
WORK=$(umask 077; mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# A throwaway client certificate from the agent CA, valid 1 day, CN that no
# real agent can have (not a UUID). Key and cert are deleted on exit.
(umask 077
 openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$WORK/c.key" 2>/dev/null
 openssl req -new -key "$WORK/c.key" -subj "/CN=setup-selftest" -out "$WORK/c.csr" 2>/dev/null
 printf '[c]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n' > "$WORK/c.cnf"
 openssl x509 -req -in "$WORK/c.csr" -CA "$AIO_ETC/pki/agent-ca/ca.crt" -CAkey "$AIO_ETC/pki/agent-ca/ca.key" \
     -set_serial "0x$(openssl rand -hex 16)" -days 1 -sha384 -extfile "$WORK/c.cnf" -extensions c -out "$WORK/c.crt" 2>/dev/null)

body_has() { local out; out=$(curl -sS --max-time 10 "${@:2}") && grep -q "$1" <<<"$out"; }
listens_on() { ss -Hltn | awk '{print $4}' | grep -Eq "(^|:)$1\$"; }
not_listens_on() { ! listens_on "$1"; }
php_version_hidden() { ! curl -sS -o /dev/null -D - --max-time 10 --cacert "$WEBCA" "$URL_DASH" | grep -qi '^x-powered-by'; }
server_header_short() { curl -sS -o /dev/null -D - --max-time 10 --cacert "$WEBCA" "$URL_DASH" | tr -d '\r' | grep -i '^server:' | grep -qix 'server: apache'; }

echo
info "Health pages"
curl -sS --max-time 10 --cacert "$WEBCA" "$URL_DASH" || true
curl -sS --max-time 10 --cacert "$AGENTCA" "$URL_AGENT" || true
curl -sS --max-time 10 --cacert "$AGENTCA" --cert "$WORK/c.crt" --key "$WORK/c.key" "$URL_AGENT" || true
echo

check "apache2 running" systemctl is-active --quiet apache2
check "$FPM running" systemctl is-active --quiet "$FPM"
check "PHP is $AIO_PHP_VERSION" test "$PHPV" = "$AIO_PHP_VERSION"
check "mpm_event in use" bash -c "apache2ctl -M 2>/dev/null | grep -q mpm_event_module"
check "mod_php not loaded" bash -c "! apache2ctl -M 2>/dev/null | grep -q php"
check "pool sockets exist" test -S /run/php/aio-ingest.sock -a -S /run/php/aio-dashboard.sock
check "pools run as aio-ingest and aio-dashboard" bash -c "ps -o user:32= -C php-fpm$PHPV | grep -q aio-ingest && ps -o user:32= -C php-fpm$PHPV | grep -q aio-dashboard"
check "listening on 443" listens_on 443
check "listening on 8443" listens_on 8443
check "NOT listening on 80" not_listens_on 80
check "443: health page over HTTPS with the web CA" body_has '"service":"dashboard"' --cacert "$WEBCA" "$URL_DASH"
check "443: TLS 1.2 accepted" body_has '"tls_protocol":"TLSv1.2"' --cacert "$WEBCA" --tls-max 1.2 "$URL_DASH"
check "443: TLS 1.3 accepted" body_has '"tls_protocol":"TLSv1.3"' --cacert "$WEBCA" "$URL_DASH"
check "443: health page reports reboot_required (M-039)" body_has '"reboot_required":' --cacert "$WEBCA" "$URL_DASH"
check_not "443: certificate NOT trusted via the agent CA" curl -sS --max-time 10 --cacert "$AGENTCA" "$URL_DASH"
check "443: no PHP version header" php_version_hidden
check "443: Server header is just 'Apache'" server_header_short
check "8443: health page without a client cert (verify NONE)" body_has '"client_verify":"NONE"' --cacert "$AGENTCA" "$URL_AGENT"
check "8443: client cert from the agent CA verified (SUCCESS)" body_has '"client_verify":"SUCCESS"' --cacert "$AGENTCA" --cert "$WORK/c.crt" --key "$WORK/c.key" "$URL_AGENT"
check "8443: TLS 1.3 in use" body_has '"tls_protocol":"TLSv1.3"' --cacert "$AGENTCA" "$URL_AGENT"
check_not "8443: TLS 1.2 refused (M-019)" curl -sS --max-time 10 --cacert "$AGENTCA" --tls-max 1.2 "$URL_AGENT"
check_not "8443: certificate NOT trusted via the web CA" curl -sS --max-time 10 --cacert "$WEBCA" "$URL_AGENT"
check_not "8443: plain HTTP refused" curl -sS --max-time 10 "http://$AIO_IP:8443/health.php" -o /dev/null -f
check_not "port 80: nothing answers" curl -sS --max-time 5 "http://$AIO_IP/" -o /dev/null
finish
