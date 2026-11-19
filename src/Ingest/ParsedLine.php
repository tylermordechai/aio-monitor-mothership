<?php

declare(strict_types=1);

namespace Aio\Ingest;

/** One line that passed the envelope checks (ingest-api.md §3.4 rules 1 to 8). */
final class ParsedLine
{
    public function __construct(
        public readonly int $index,
        public readonly string $raw,
        public readonly string $recordType,
        public readonly int $seq,
        public readonly string $ts,
        public readonly int $tsEpoch,
        public readonly string $hostname,
        public readonly string $agentVersion,
        public readonly \stdClass $data,
    ) {
    }
}
