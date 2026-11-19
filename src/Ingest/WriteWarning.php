<?php

declare(strict_types=1);

namespace Aio\Ingest;

/** INSERT IGNORE raised a warning other than a duplicate key: the batch fails (M-005). */
final class WriteWarning extends \RuntimeException
{
}
