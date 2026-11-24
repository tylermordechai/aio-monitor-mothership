<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * Something that signs an agent CSR with the §2.3 profile: the signing
 * service through its socket in production (SignerClient), the Signer itself
 * in tests (M-060).
 */
interface CertSigner
{
    /**
     * @return array{certificate: string, serial_hex: string}
     * @throws CsrInvalid the CSR is refused
     * @throws \RuntimeException the signer can't sign
     */
    public function sign(string $csrPem, string $agentId): array;
}
