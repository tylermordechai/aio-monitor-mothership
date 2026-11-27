<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Dashboard\AgentStatus;
use PHPUnit\Framework\TestCase;

/** The fleet's agent state (MS-UI-01, MS-ING-08, MS-ALR-05; gate "silent agent flagged", M-067). */
final class AgentStatusTest extends TestCase
{
    public function testEachState(): void
    {
        $now = 1_800_000_000;
        $of = static fn (?string $st, ?int $in, ?int $ing, ?int $q): string => AgentStatus::of($st, $in, $ing, $q, $now, 300);
        self::assertSame('no_agent', $of(null, null, null, null));
        self::assertSame('revoked', $of('revoked', $now, $now, 0), 'revoked wins over a recent check-in');
        self::assertSame('stale', $of('active', null, null, null), 'never checked in');
        self::assertSame('stale', $of('active', $now - 301, $now, 0));
        self::assertSame('ok', $of('active', $now - 300, null, 0));
        self::assertSame('ok', $of('active', $now - 10, null, null), 'nothing queued');
        self::assertSame('behind', $of('active', $now - 10, $now - 301, 120));
        self::assertSame('behind', $of('active', $now - 10, null, 5), 'queued and never stored');
        self::assertSame('ok', $of('active', $now - 10, $now - 30, 120), 'queued but ingest is working');
        self::assertSame('silent', AgentStatus::label('stale'));
    }
}
