<?php

declare(strict_types=1);

namespace Aio\Ingest;

/** A line that goes to the quarantine table, with its one reason (MS-ING-05, M-007). */
final class QuarantinedLine
{
    public function __construct(
        public readonly int $index,
        public readonly string $raw,
        public readonly string $reason,
        public readonly ?int $seq = null,
        public readonly ?string $recordType = null,
        public readonly ?int $schemaVersion = null,
    ) {
    }

    public function withReason(string $reason): self
    {
        return new self($this->index, $this->raw, $reason, $this->seq, $this->recordType, $this->schemaVersion);
    }
}
