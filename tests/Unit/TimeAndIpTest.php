<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Util\Ip;
use Aio\Util\Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** M-010: UTC DATETIME(6), the 7th digit cut; IP text to INET6_ATON's form. */
final class TimeAndIpTest extends TestCase
{
    public function testSevenDigitsAreCutNotRounded(): void
    {
        $t = Time::parse('2026-11-14T10:00:00.1234567Z', true);
        self::assertSame('2026-11-14 10:00:00.123456', $t['db']);
        $t = Time::parse('2026-11-14T10:00:00.9999999Z', true);
        self::assertSame('2026-11-14 10:00:00.999999', $t['db']);
    }

    public function testNoFractionAndShortFraction(): void
    {
        self::assertSame('2026-11-14 10:00:00.000000', Time::parse('2026-11-14T10:00:00Z')['db']);
        self::assertSame('2026-11-14 10:00:00.500000', Time::parse('2026-11-14T10:00:00.5Z')['db']);
    }

    public function testOffsetIsConvertedToUtcButNotAllowedInEnvelope(): void
    {
        self::assertSame('2026-11-14 08:00:00.000000', Time::parse('2026-11-14T10:00:00+02:00')['db']);
        self::assertNull(Time::parse('2026-11-14T10:00:00+02:00', true));
    }

    public function testGoZeroTimeIsFlagged(): void
    {
        $t = Time::parse('0001-01-01T00:00:00Z');
        self::assertTrue($t['zero']);
    }

    /** @return iterable<array{string}> */
    public static function badTimes(): iterable
    {
        yield ['2026-13-01T00:00:00Z'];
        yield ['2026-02-30T00:00:00Z'];
        yield ['2026-11-14 10:00:00Z'];
        yield ['2026-11-14T24:00:00Z'];
        yield ['2026-11-14T10:00:00'];
        yield ['yesterday'];
    }

    #[DataProvider('badTimes')]
    public function testBadTimesAreRefused(string $s): void
    {
        self::assertNull(Time::parse($s));
    }

    public function testIpForms(): void
    {
        self::assertSame(inet_pton('10.1.2.3'), Ip::toBinary('10.1.2.3'));
        self::assertSame(inet_pton('fe80::1'), Ip::toBinary('fe80::1%12'));
        self::assertSame(inet_pton('::1'), Ip::toBinary('[::1]'));
        self::assertNull(Ip::toBinary('not an ip'));
        self::assertNull(Ip::toBinary(''));
    }
}
