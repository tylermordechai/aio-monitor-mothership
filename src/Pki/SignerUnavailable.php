<?php

declare(strict_types=1);

namespace Aio\Pki;

/** The signing service couldn't be reached or failed; the API answers 503 (MS-SEC-02). */
final class SignerUnavailable extends \RuntimeException
{
}
