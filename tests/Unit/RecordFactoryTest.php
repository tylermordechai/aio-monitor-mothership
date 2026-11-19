<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Ingest\LineValidator;
use Aio\Ingest\ParsedLine;
use Aio\Ingest\RecordTypes;
use Aio\Ingest\RowBuilder;
use Aio\Sim\RecordFactory;
use PHPUnit\Framework\TestCase;

/**
 * The simulator's records are what the real agent would send: every type
 * passes validation, and no field lands in `extra` by a misspelt name.
 */
final class RecordFactoryTest extends TestCase
{
    public function testOneOfEachTypeBuildsCleanly(): void
    {
        $now = time();
        $f = new RecordFactory(LineValidatorTest::AGENT, LineValidatorTest::HOST, 'SIM-0001');
        $records = $f->oneOfEach((float) $now);
        $v = new LineValidator(LineValidatorTest::AGENT, LineValidatorTest::HOST, [], [], 1_048_576, 86_400, $now);
        $b = new RowBuilder(1, 1, 1);
        $seen = [];
        foreach ($records as $i => $rec) {
            $p = $v->check($i, json_encode($rec));
            self::assertInstanceOf(ParsedLine::class, $p, $rec['record_type'] . ': ' . ($p->reason ?? ''));
            $row = $b->build($p)->row;
            self::assertArrayNotHasKey('extra', $row, $rec['record_type'] . ' has fields with no column: ' . ($row['extra'] ?? ''));
            $seen[$rec['record_type']] = true;
        }
        self::assertSame([], array_values(array_diff(array_keys(RecordTypes::TYPES), array_keys($seen))), 'every record type is covered');
    }

    public function testSeqIncreasesAndTsHasSevenDigits(): void
    {
        $f = new RecordFactory(LineValidatorTest::AGENT, LineValidatorTest::HOST, 'SIM-0001', 100);
        $a = $f->etwStatus(1_794_650_400.5);
        $b = $f->etwStatus(1_794_650_401.0);
        self::assertSame(100, $a['seq']);
        self::assertSame(101, $b['seq']);
        self::assertSame('2026-11-14T10:00:00.5000000Z', $a['ts']);
    }
}
