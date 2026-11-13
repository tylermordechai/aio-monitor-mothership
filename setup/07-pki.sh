#!/usr/bin/env bash
# 07-pki.sh: the two private CAs and the two server certificates.
#   - agent CA (ECDSA P-384, 10 years): agent client certificates and the 8443
#     server certificate; agents pin it (M-002). Key readable by root and the
#     aio-signer group only (the signing service, milestone 3; MS-SEC-02).
#   - web CA (ECDSA P-384, 10 years, name-constrained to 192.168.0.0/24): only
#     the 443 dashboard certificate; imported on the owner's PC (M-024). Key
#     root-only.
#   - server certificates (ECDSA P-256) with the IP as their only SAN.
#
# Needs sudo: yes. Reboots: no. Safe to re-run: yes. A CA that exists is
# NEVER replaced (that would cut off every enrolled agent). A server
# certificate is reissued only if it is missing, expires within 30 days, has
# another IP, or no longer chains to its CA.
# Private keys are never printed (MS-SEC-10).
# Requirements: MS-SEC-01, MS-SEC-02, MS-ING-06, M-002, M-019, M-024, M-037.

source "$(dirname "$0")/lib.sh"
need_root
banner "agent CA, web CA, server certificates for $AIO_IP" "yes" "no"

PKI="$AIO_ETC/pki"
AGENT_CA="$PKI/agent-ca"
WEB_CA="$PKI/web-ca"
SERVER="$PKI/server"
PUBLIC="$PKI/public"
WORK=$(umask 077; mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# --- Users and directories ----------------------------------------------------
if ! getent group aio-signer >/dev/null; then
    useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --user-group aio-signer
    info "created system user aio-signer (the CA signing service, milestone 3)"
fi
install -d -m 0755 -o root -g root "$AIO_ETC" "$PKI" "$PUBLIC"
install -d -m 0750 -o root -g aio-signer "$AGENT_CA"
install -d -m 0700 -o root -g root "$WEB_CA" "$SERVER"

serial() { echo "0x$(openssl rand -hex 16)"; }   # 128-bit random serial (M-002)

# make_ca dir "Common Name" [nameConstraints]
make_ca() {
    local dir=$1 cn=$2 nc=${3:-}
    if [[ -f "$dir/ca.key" || -f "$dir/ca.crt" ]]; then
        info "$cn exists; kept (a CA is never replaced by this script)"
        return 0
    fi
    info "creating $cn"
    {
        echo "[req]"
        echo "distinguished_name = dn"
        echo "prompt = no"
        echo "[dn]"
        echo "O = AIO Monitor"
        echo "CN = $cn"
        echo "[ext]"
        echo "basicConstraints = critical, CA:TRUE, pathlen:0"
        echo "keyUsage = critical, keyCertSign, cRLSign"
        echo "subjectKeyIdentifier = hash"
        if [[ -n "$nc" ]]; then echo "nameConstraints = $nc"; fi
    } > "$WORK/ca.cnf"
    (umask 077; openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-384 -out "$WORK/ca.key")
    openssl req -new -x509 -config "$WORK/ca.cnf" -extensions ext -key "$WORK/ca.key" \
        -sha384 -days 3650 -set_serial "$(serial)" -out "$WORK/ca.crt"
    install -m 0644 -o root -g root "$WORK/ca.crt" "$dir/ca.crt"
    install -m 0400 -o root -g root "$WORK/ca.key" "$dir/ca.key"
    rm -f "$WORK/ca.key"
}

# leaf_ok crt cafile -> 0 if present, chains to the CA, has our IP, lasts 30+ days
leaf_ok() {
    local crt=$1 ca=$2
    [[ -f "$crt" && -f "${crt%.crt}.key" ]] || return 1
    openssl verify -CAfile "$ca" "$crt" >/dev/null 2>&1 || return 1
    openssl x509 -in "$crt" -noout -checkend $((30 * 86400)) >/dev/null || return 1
    openssl x509 -in "$crt" -noout -ext subjectAltName 2>/dev/null | grep -q "IP Address:$AIO_IP\$" || return 1
}

# make_leaf name "Common Name" cadir days eku san
make_leaf() {
    local name=$1 cn=$2 cadir=$3 days=$4 eku=$5 san=$6
    if leaf_ok "$SERVER/$name.crt" "$cadir/ca.crt"; then
        info "$name certificate is current; kept"
        return 0
    fi
    info "issuing $name certificate ($san, $days days)"
    {
        echo "[leaf]"
        echo "basicConstraints = critical, CA:FALSE"
        echo "keyUsage = critical, digitalSignature"
        echo "extendedKeyUsage = $eku"
        echo "subjectAltName = $san"
        echo "subjectKeyIdentifier = hash"
        echo "authorityKeyIdentifier = keyid:always"
    } > "$WORK/leaf.cnf"
    (umask 077; openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$WORK/$name.key")
    openssl req -new -key "$WORK/$name.key" -subj "/O=AIO Monitor/CN=$cn" -out "$WORK/$name.csr"
    openssl x509 -req -in "$WORK/$name.csr" -CA "$cadir/ca.crt" -CAkey "$cadir/ca.key" \
        -set_serial "$(serial)" -days "$days" -sha384 \
        -extfile "$WORK/leaf.cnf" -extensions leaf -out "$WORK/$name.crt" 2>/dev/null
    install -m 0400 -o root -g root "$WORK/$name.key" "$SERVER/$name.key"
    install -m 0644 -o root -g root "$WORK/$name.crt" "$SERVER/$name.crt"
    rm -f "$WORK/$name.key"
}

# --- The two CAs --------------------------------------------------------------
make_ca "$AGENT_CA" "AIO Monitor Agent CA"
# The web CA can only sign for IPs in the LAN. DNS:invalid and email:invalid
# as the only permitted names mean it can't sign for any real DNS name or
# address either; an IPv6 SAN fails the IPv4-only IP constraint.
LAN_NET=${AIO_LAN%/*}
make_ca "$WEB_CA" "AIO Monitor Web CA (192.168.0.0/24 only)" \
    "critical, permitted;IP:$LAN_NET/255.255.255.0, permitted;DNS:invalid, permitted;email:invalid"
# Only the signing service (milestone 3) may read the agent CA key.
chown root:aio-signer "$AGENT_CA/ca.key"; chmod 0440 "$AGENT_CA/ca.key"

# --- Server certificates --------------------------------------------------------
make_leaf agent-api "AIO Mothership agent API" "$AGENT_CA" 365 serverAuth "IP:$AIO_IP"
make_leaf dashboard "AIO Mothership dashboard" "$WEB_CA" 397 serverAuth "IP:$AIO_IP"

# --- Public copies and fingerprints ------------------------------------------
install -m 0644 -o root -g root "$AGENT_CA/ca.crt" "$PUBLIC/agent-ca.crt"
install -m 0644 -o root -g root "$WEB_CA/ca.crt" "$PUBLIC/web-ca.crt"
fp() { openssl x509 -in "$1" -noout -fingerprint "-$2" | cut -d= -f2; }
{
    echo "# Public fingerprints (not secret). Written by setup/07-pki.sh."
    echo "agent-ca SHA-256 (MSI property, M-002): $(fp "$PUBLIC/agent-ca.crt" sha256)"
    echo "web-ca   SHA-256: $(fp "$PUBLIC/web-ca.crt" sha256)"
    echo "web-ca   SHA-1 thumbprint (Windows shows this): $(fp "$PUBLIC/web-ca.crt" sha1 | tr -d ':')"
} > "$PUBLIC/fingerprints.txt"
chmod 0644 "$PUBLIC/fingerprints.txt"

echo
cat "$PUBLIC/fingerprints.txt"
echo
for c in "$PUBLIC/agent-ca.crt" "$PUBLIC/web-ca.crt" "$SERVER/agent-api.crt" "$SERVER/dashboard.crt"; do
    echo "$(basename "$c"): $(openssl x509 -in "$c" -noout -subject -enddate | tr '\n' ' ')"
done

# --- Checks ---------------------------------------------------------------------
# A forbidden certificate from the web CA must fail verification: proof the
# name constraint works.
forbidden_rejected() {
    local san=$1
    (umask 077; openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$WORK/t.key")
    openssl req -new -key "$WORK/t.key" -subj "/CN=constraint test" -out "$WORK/t.csr"
    printf '[t]\nbasicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=%s\n' "$san" > "$WORK/t.cnf"
    openssl x509 -req -in "$WORK/t.csr" -CA "$WEB_CA/ca.crt" -CAkey "$WEB_CA/ca.key" -set_serial "$(serial)" \
        -days 1 -extfile "$WORK/t.cnf" -extensions t -out "$WORK/t.crt" 2>/dev/null
    rm -f "$WORK/t.key"
    # The test cert must exist (signing never checks constraints) and must
    # then fail verification; a missing cert is a FAIL, not a pass.
    [[ -s "$WORK/t.crt" ]] || return 1
    ! openssl verify -CAfile "$WEB_CA/ca.crt" "$WORK/t.crt" >/dev/null 2>&1 || return 1
    rm -f "$WORK/t.crt"
}
ip_matches() { openssl verify -CAfile "$1" -verify_ip "$AIO_IP" "$2" >/dev/null 2>&1; }
is_p() { openssl pkey -in "$1" -noout -text_pub 2>/dev/null | grep -q "NIST CURVE: $2"; }
nc_present() { openssl x509 -in "$WEB_CA/ca.crt" -noout -ext nameConstraints | grep -q "IP:$LAN_NET/255.255.255.0"; }

echo
check "agent CA key: 440 root:aio-signer (MS-SEC-02)" file_mode_is "$AGENT_CA/ca.key" 440 root aio-signer
check "agent CA dir: 750 root:aio-signer" file_mode_is "$AGENT_CA" 750 root aio-signer
check "web CA key: 400 root:root" file_mode_is "$WEB_CA/ca.key" 400 root root
check "web CA dir: 700 root:root" file_mode_is "$WEB_CA" 700 root root
check "server keys: 400 root:root" bash -c "[[ \$(stat -c '%a %U %G' '$SERVER/agent-api.key') == '400 root root' && \$(stat -c '%a %U %G' '$SERVER/dashboard.key') == '400 root root' ]]"
check "server key dir: 700 root:root" file_mode_is "$SERVER" 700 root root
check "agent CA key is P-384" is_p "$AGENT_CA/ca.key" P-384
check "web CA key is P-384" is_p "$WEB_CA/ca.key" P-384
check "server keys are P-256" bash -c "openssl pkey -in '$SERVER/agent-api.key' -noout -text_pub | grep -q 'NIST CURVE: P-256' && openssl pkey -in '$SERVER/dashboard.key' -noout -text_pub | grep -q 'NIST CURVE: P-256'"
check "agent-api cert chains to the agent CA, valid for $AIO_IP" ip_matches "$AGENT_CA/ca.crt" "$SERVER/agent-api.crt"
check "dashboard cert chains to the web CA, valid for $AIO_IP" ip_matches "$WEB_CA/ca.crt" "$SERVER/dashboard.crt"
check "dashboard cert does NOT chain to the agent CA" bash -c "! openssl verify -CAfile '$AGENT_CA/ca.crt' '$SERVER/dashboard.crt'"
check "server certs valid for 30+ days" bash -c "openssl x509 -in '$SERVER/agent-api.crt' -noout -checkend 2592000 && openssl x509 -in '$SERVER/dashboard.crt' -noout -checkend 2592000"
check "web CA carries the name constraint (critical, $AIO_LAN)" nc_present
check "web CA refuses a cert for DNS:example.com" forbidden_rejected "DNS:example.com"
check "web CA refuses a cert for IP:10.0.0.1" forbidden_rejected "IP:10.0.0.1"
check "public copies match the CA certificates" bash -c "cmp -s '$AGENT_CA/ca.crt' '$PUBLIC/agent-ca.crt' && cmp -s '$WEB_CA/ca.crt' '$PUBLIC/web-ca.crt'"
finish
echo
echo "Next: copy $PUBLIC/web-ca.crt to the PC and import it (docs/setup.md, web CA)."
