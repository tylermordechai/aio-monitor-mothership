<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * The checks on an agent's CSR that PHP can make (ingest-api.md §2.3, M-002;
 * MS-ING-06). Used by the enrol and renew endpoints before they call the
 * signer, and by the signer itself again, which also verifies the CSR's own
 * signature with the openssl tool (PHP has no call for that).
 *
 * Only the key and the CN are taken from a CSR. Every other subject field and
 * every requested extension is ignored: the signer sets them (CertProfile).
 */
final class CsrCheck
{
    public const MAX_PEM_BYTES = 8192;
    /** The agent's D-004 format. */
    public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    public const CURVES = ['prime256v1', 'secp384r1'];
    private const PEM = '/^-----BEGIN CERTIFICATE REQUEST-----\r?\n[A-Za-z0-9+\/=\r\n]{16,8000}-----END CERTIFICATE REQUEST-----\r?\n?$/D';

    /** @throws CsrInvalid */
    public static function check(string $pem, string $agentId): void
    {
        if (!preg_match(self::UUID_V4, $agentId)) {
            throw new CsrInvalid('agent_id');
        }
        if (strlen($pem) > self::MAX_PEM_BYTES || !preg_match(self::PEM, $pem)) {
            throw new CsrInvalid('pem');
        }
        $subject = @openssl_csr_get_subject($pem, false);
        $key = @openssl_csr_get_public_key($pem);
        if ($subject === false || $key === false) {
            throw new CsrInvalid('pem');
        }
        $cn = $subject['commonName'] ?? null;
        if (!is_string($cn)) {
            // Missing, or several CNs (an array).
            throw new CsrInvalid('subject');
        }
        if ($cn !== $agentId) {
            throw new CsrInvalid('cn');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC) {
            throw new CsrInvalid('key_type');
        }
        if (!in_array($details['ec']['curve_name'] ?? '', self::CURVES, true)) {
            throw new CsrInvalid('curve');
        }
    }

    /** The CSR's public key as PEM, to check the signed certificate carries it. */
    public static function publicKeyPem(string $pem): string
    {
        $key = @openssl_csr_get_public_key($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false) {
            throw new CsrInvalid('pem');
        }
        return (string) $details['key'];
    }
}
