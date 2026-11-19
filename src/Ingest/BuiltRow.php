<?php

declare(strict_types=1);

namespace Aio\Ingest;

/** A validated line as a row for its table. */
final class BuiltRow
{
    /** @param array<string, int|string|null> $row column => value */
    public function __construct(
        public readonly ParsedLine $line,
        public readonly string $table,
        public readonly string $kind,
        public readonly array $row,
        public readonly ?string $commandLine = null,
    ) {
    }
}
