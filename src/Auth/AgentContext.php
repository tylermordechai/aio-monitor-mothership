<?php

declare(strict_types=1);

namespace Aio\Auth;

/** The agent a request belongs to, from its client certificate (MS-ING-02). */
final class AgentContext
{
    public function __construct(
        public readonly int $agentPk,
        public readonly string $agentId,
        public readonly int $hostPk,
        public readonly string $hostGuid,
        public readonly string $hostname,
        public readonly int $groupId,
        public readonly string $certNotAfter,
        public readonly ?string $version,
        public readonly ?string $sourceIp,
    ) {
    }
}
