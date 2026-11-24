<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * Signs agent CSRs with the agent CA (ingest-api.md §2.1 step 6, §2.3;
 * MS-SEC-02, MS-ING-06, MS-ING-11). Runs only inside the signing service
 * (bin/signer.php, system user aio-signer), the only reader of the CA key.
 * It has no database access and trusts nothing its caller sends: it checks
 * the CSR again, verifies the CSR's own signature, and sets every field of
 * the certificate itself (CertProfile). It then checks its own output.
 */
final class Signer implements CertSigner
{
    /** @param \Closure(): int|null $clock */
    public function __construct(
        private readonly string $caCertPath,
        private readonly string $caKeyPath,
        private readonly OpensslTool $openssl,
        private readonly string $tmpDir,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @return array{certificate: string, serial_hex: string}
     * @throws CsrInvalid the CSR is refused (the caller answers 400 csr_invalid)
     * @throws \RuntimeException the signer can't sign (the caller answers 503)
     */
    public function sign(string $csrPem, string $agentId): array
    {
        CsrCheck::check($csrPem, $agentId);
        $csrKey = CsrCheck::publicKeyPem($csrPem);
        $caCert = @file_get_contents($this->caCertPath);
        if ($caCert === false || !is_readable($this->caKeyPath)) {
            throw new \RuntimeException('the agent CA is not readable');
        }

        $dir = $this->tmpDir . '/aio-sign-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            throw new \RuntimeException('could not make a work folder');
        }
        try {
            file_put_contents("$dir/req.csr", $csrPem);
            file_put_contents("$dir/ext.cnf", CertProfile::extensions($agentId));

            [$code] = $this->openssl->run(['req', '-verify', '-noout', '-in', "$dir/req.csr"]);
            if ($code !== 0) {
                throw new CsrInvalid('signature');
            }

            $serial = CertProfile::newSerialHex();
            [$from, $to] = CertProfile::validity($this->clock !== null ? ($this->clock)() : time());
            [$code, $out] = $this->openssl->run([
                'x509', '-req', '-in', "$dir/req.csr",
                '-CA', $this->caCertPath, '-CAkey', $this->caKeyPath,
                '-set_serial', '0x' . $serial,
                '-not_before', gmdate('YmdHis', $from) . 'Z',
                '-not_after', gmdate('YmdHis', $to) . 'Z',
                '-sha384',
                '-set_subject', CertProfile::subject($agentId),
                '-clrext', '-extfile', "$dir/ext.cnf", '-extensions', 'v3_client',
            ]);
            if ($code !== 0 || !str_contains($out, '-----BEGIN CERTIFICATE-----')) {
                throw new \RuntimeException('openssl could not sign');
            }
            $pem = substr($out, (int) strpos($out, '-----BEGIN CERTIFICATE-----'));
            $this->checkOutput($pem, $caCert, $csrKey, $agentId, $serial);
            return ['certificate' => $pem, 'serial_hex' => $serial];
        } finally {
            foreach (['req.csr', 'ext.cnf'] as $f) {
                @unlink("$dir/$f");
            }
            @rmdir($dir);
        }
    }

    /** The certificate must be exactly what §2.3 says, or nothing is returned. */
    private function checkOutput(string $pem, string $caCert, string $csrKey, string $agentId, string $serial): void
    {
        $info = @openssl_x509_parse($pem);
        $key = @openssl_pkey_get_public($pem);
        $keyDetails = $key === false ? false : openssl_pkey_get_details($key);
        $ok = $info !== false
            && $keyDetails !== false
            && openssl_x509_verify($pem, $caCert) === 1
            && ($info['subject']['CN'] ?? null) === $agentId
            && ($info['subject']['O'] ?? null) === CertProfile::ORGANISATION
            && count($info['subject']) === 2
            && strtoupper($info['serialNumberHex'] ?? '') === $serial
            && $keyDetails['key'] === $csrKey
            && ($info['extensions']['basicConstraints'] ?? '') === 'CA:FALSE'
            && ($info['extensions']['extendedKeyUsage'] ?? '') === 'TLS Web Client Authentication'
            && ($info['extensions']['subjectAltName'] ?? '') === 'URI:urn:aio-monitor:agent:' . $agentId;
        if (!$ok) {
            throw new \RuntimeException('the signed certificate failed its own check');
        }
    }
}
