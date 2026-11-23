<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * The agent client certificate profile (ingest-api.md §2.3, M-002, M-018).
 * Fixed in code on purpose: the signer is a security boundary, so nothing a
 * caller sends and no settings file can change what it signs (MS-SEC-02).
 */
final class CertProfile
{
    /** M-018: 365 days, renewed 30 days before expiry. */
    public const LIFETIME_DAYS = 365;
    public const RENEW_BEFORE_DAYS = 30;
    /** notBefore is set 5 minutes back, for clock skew. */
    public const BACKDATE_S = 300;
    public const ORGANISATION = 'AIO Monitor Agent';

    /** `/CN=<agent_id>/O=AIO Monitor Agent`. The agent ID is checked by CsrCheck first. */
    public static function subject(string $agentId): string
    {
        self::assertId($agentId);
        return '/CN=' . $agentId . '/O=' . self::ORGANISATION;
    }

    /** The openssl extension section `v3_client`; nothing is taken from the CSR. */
    public static function extensions(string $agentId): string
    {
        self::assertId($agentId);
        return "[v3_client]\n"
            . "basicConstraints = critical, CA:FALSE\n"
            . "keyUsage = critical, digitalSignature\n"
            . "extendedKeyUsage = clientAuth\n"
            . "subjectAltName = URI:urn:aio-monitor:agent:$agentId\n"
            . "subjectKeyIdentifier = hash\n"
            . "authorityKeyIdentifier = keyid:always\n";
    }

    /**
     * A random serial as 32 hex digits. The top bit is cleared and the next
     * one set, so the serial is positive and always 16 bytes long (126 random
     * bits; §2.3 asks for 128, which a positive fixed-length serial can't have).
     */
    public static function newSerialHex(): string
    {
        $b = random_bytes(16);
        $b[0] = chr((ord($b[0]) & 0x7f) | 0x40);
        return strtoupper(bin2hex($b));
    }

    /** @return array{0: int, 1: int} notBefore and notAfter as Unix times */
    public static function validity(int $now): array
    {
        return [$now - self::BACKDATE_S, $now + self::LIFETIME_DAYS * 86_400];
    }

    /** `renew_after` for a certificate: 30 days before notAfter (§2.2). */
    public static function renewAfter(int $notAfter): int
    {
        return $notAfter - self::RENEW_BEFORE_DAYS * 86_400;
    }

    private static function assertId(string $agentId): void
    {
        if (!preg_match(CsrCheck::UUID_V4, $agentId)) {
            throw new CsrInvalid('agent_id');
        }
    }
}
