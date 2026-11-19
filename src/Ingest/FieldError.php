<?php

declare(strict_types=1);

namespace Aio\Ingest;

/** A record field is missing or of the wrong type: quarantined (§3.4 rule 9). */
final class FieldError extends \RuntimeException
{
    public static function missing(string $field): self
    {
        return new self("missing_field:$field");
    }

    public static function invalid(string $field): self
    {
        return new self("invalid_field:$field");
    }
}
