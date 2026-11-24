<?php

declare(strict_types=1);

namespace Aio\Enrol;

/**
 * The one-time enrolment token (ingest-api.md §2.1, §2.2; MS-SEC-03, M-021):
 * `aioe_` followed by 32 random bytes as 43 base64url characters, no padding.
 * Only its SHA-256 is stored (over the whole string, prefix included), so a
 * database copy can't be used to enrol. Never logged (MS-SEC-10).
 */
final class EnrolToken
{
    public const PREFIX = 'aioe_';
    private const FORMAT = '/^aioe_[A-Za-z0-9_-]{43}$/D';

    public static function generate(): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return (bool) preg_match(self::FORMAT, $token);
    }

    /** The stored form: 32 raw bytes (enrollment_tokens.token_sha256). */
    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }
}
