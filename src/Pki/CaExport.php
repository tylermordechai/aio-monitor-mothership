<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * The agent CA as the installer takes it: the PEM and its SHA-256
 * fingerprint, the hash of the certificate's DER bytes (ingest-api.md §2.1
 * step 2; M-002, M-059, agent PD-59). Public, not secret.
 */
final class CaExport
{
    /** @return array{pem: string, sha256: string, sha256_colons: string, subject: string, not_after: string} */
    public static function read(string $pem): array
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----/', $pem) !== 1) {
            throw new \RuntimeException('the agent CA file must hold exactly one certificate');
        }
        $x = openssl_x509_read($pem);
        $i = $x === false ? false : openssl_x509_parse($x);
        if ($x === false || $i === false) {
            throw new \RuntimeException('the agent CA file is not a readable certificate');
        }
        if (!str_starts_with((string) ($i['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')) {
            throw new \RuntimeException('the agent CA file is not a CA certificate');
        }
        $hex = (string) openssl_x509_fingerprint($x, 'sha256');
        openssl_x509_export($x, $clean);
        return [
            'pem' => $clean,
            'sha256' => strtolower($hex),
            'sha256_colons' => implode(':', str_split(strtoupper($hex), 2)),
            'subject' => (string) ($i['name'] ?? ''),
            'not_after' => gmdate('Y-m-d', (int) $i['validTo_time_t']),
        ];
    }
}
