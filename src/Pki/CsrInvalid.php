<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * A CSR the mothership won't sign (ingest-api.md §2.3, §7 `csr_invalid`).
 * The reason is a fixed code, never CSR content (MS-SEC-10).
 */
final class CsrInvalid extends \RuntimeException
{
    public const REASONS = [
        'pem' => 'not a single PEM certificate request',
        'agent_id' => 'agent_id is not a lower-case UUID version 4',
        'subject' => 'the subject has no single CN',
        'cn' => 'the CN is not the agent_id',
        'key_type' => 'the key is not ECDSA',
        'curve' => 'the curve is not P-256 or P-384',
        'signature' => 'the CSR signature does not verify',
        // Renewal only (§2.6, M-064): a renewal must bring a new key.
        'same_key' => 'the CSR reuses the key of the certificate in use',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::REASONS[$reason] ?? 'invalid CSR');
    }
}
