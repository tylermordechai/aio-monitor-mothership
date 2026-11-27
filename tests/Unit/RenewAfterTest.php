<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Heartbeat\HeartbeatService;
use PHPUnit\Framework\TestCase;

/** The heartbeat's renew_after (ingest-api.md §4, §2.6; MS-ING-11, M-064). */
final class RenewAfterTest extends TestCase
{
    public function testNullUntilThirtyDaysBeforeExpiryThenTheDueTime(): void
    {
        $notAfter = '2027-01-01 00:00:00.000000';
        self::assertNull(HeartbeatService::renewAfter($notAfter, (float) strtotime('2026-12-01T23:59:59Z')));
        self::assertSame('2026-12-02T00:00:00Z', HeartbeatService::renewAfter($notAfter, (float) strtotime('2026-12-02T00:00:00Z')));
        self::assertSame('2026-12-02T00:00:00Z', HeartbeatService::renewAfter($notAfter, (float) strtotime('2027-02-01T00:00:00Z')));
        self::assertNull(HeartbeatService::renewAfter('not a time', 0.0));
    }
}
