<?php
/**
 * Checks the signing service through its socket, the way the agent API will
 * use it (MS-SEC-02, ingest-api.md §2.3). Run by setup/11-enrolment.sh as the
 * aio-ingest user:
 *
 *   sudo runuser -u aio-ingest -- php tools/signer-check.php ok|rsa|tampered|wrong-cn
 *
 * Each mode makes a throwaway key and CSR in memory for a random agent ID and
 * asks the signer. "ok" expects a certificate that follows the profile and
 * chains to the public agent CA; the others expect csr_invalid with the right
 * reason. Nothing is written to the database, so a certificate signed here is
 * refused by the API (certificate_unknown) and is simply thrown away.
 * Exit code 0 when the answer is the expected one; prints one line either way.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Pki\CsrInvalid;
use Aio\Pki\SignerClient;
use Aio\Pki\SignerUnavailable;
use Aio\Util\Uuid;

const PUBLIC_CA = '/etc/aio-mothership/pki/public/agent-ca.crt';

$mode = $argv[1] ?? '';
$want = ['ok' => null, 'rsa' => 'key_type', 'tampered' => 'signature', 'wrong-cn' => 'cn'];
if (!array_key_exists($mode, $want)) {
    fwrite(STDERR, "usage: php tools/signer-check.php ok|rsa|tampered|wrong-cn\n");
    exit(2);
}

$id = Uuid::v4();
$keyOpts = $mode === 'rsa'
    ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
    : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
$key = openssl_pkey_new($keyOpts);
$csr = $key === false ? false : openssl_csr_new(['commonName' => $mode === 'wrong-cn' ? Uuid::v4() : $id], $key, ['digest_alg' => 'sha256']);
if ($csr === false || !openssl_csr_export($csr, $pem)) {
    echo "FAILED: could not make a test CSR\n";
    exit(1);
}
if ($mode === 'tampered') {
    $der = (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s/', '', $pem));
    $der[strlen($der) - 5] = chr(ord($der[strlen($der) - 5]) ^ 0x01);
    $pem = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";
}

try {
    $r = (new SignerClient())->sign($pem, $id);
} catch (CsrInvalid $e) {
    $ok = $want[$mode] === $e->reason;
    echo ($ok ? 'OK' : 'FAILED') . ": refused, csr_invalid ({$e->reason})" . ($ok ? '' : ", expected " . ($want[$mode] ?? 'a certificate')) . "\n";
    exit($ok ? 0 : 1);
} catch (SignerUnavailable $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

if ($want[$mode] !== null) {
    echo "FAILED: signed, expected csr_invalid ({$want[$mode]})\n";
    exit(1);
}
$i = openssl_x509_parse($r['certificate']);
$problems = [];
if ($i === false) {
    $problems[] = 'not a certificate';
} else {
    if (openssl_x509_verify($r['certificate'], (string) file_get_contents(PUBLIC_CA)) !== 1) {
        $problems[] = 'does not chain to the agent CA';
    }
    if ($i['subject'] !== ['CN' => $id, 'O' => 'AIO Monitor Agent']) {
        $problems[] = 'subject';
    }
    if (($i['extensions']['extendedKeyUsage'] ?? '') !== 'TLS Web Client Authentication') {
        $problems[] = 'extended key usage';
    }
    if (($i['extensions']['subjectAltName'] ?? '') !== "URI:urn:aio-monitor:agent:$id") {
        $problems[] = 'subject alternative name';
    }
    $days = ($i['validTo_time_t'] - $i['validFrom_time_t']) / 86_400;
    if (abs($days - 365 - 300 / 86_400) > 0.001) {
        $problems[] = "lifetime $days days";
    }
    if (($i['signatureTypeLN'] ?? '') !== 'ecdsa-with-SHA384') {
        $problems[] = 'signature algorithm';
    }
}
if ($problems !== []) {
    echo 'FAILED: signed, but ' . implode(', ', $problems) . "\n";
    exit(1);
}
echo "OK: signed agent_id=$id serial={$r['serial_hex']}, profile and chain checked\n";
exit(0);
