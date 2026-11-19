<?php

declare(strict_types=1);

namespace Aio\Ingest;

/** A line names another agent: the whole request is refused with 403 (MS-ING-02, §3.2 step 3). */
final class AgentIdMismatch extends \RuntimeException
{
}
