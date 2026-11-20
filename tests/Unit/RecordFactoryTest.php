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

    /** M-050: a laptop's inventory has no server software and still builds cleanly. */
    public function testLaptopInventoryHasNoServerSoftware(): void
    {
        $now = time();
        $f = new RecordFactory(LineValidatorTest::AGENT, LineValidatorTest::HOST, 'SIM-0003');
        $records = $f->inventory((float) $now, 5, 5, false);
        $types = array_count_values(array_column($records, 'record_type'));
        foreach (['inv_role', 'inv_iis', 'inv_iis_site', 'inv_iis_app_pool', 'inv_sql_instance', 'inv_server_app'] as $t) {
            self::assertArrayNotHasKey($t, $types);
        }
        self::assertSame(2, $types['inventory_snapshot'], 'start and end markers');
        self::assertSame(1, $types['host_facts']);
        $v = new LineValidator(LineValidatorTest::AGENT, LineValidatorTest::HOST, [], [], 1_048_576, 86_400, $now);
        $b = new RowBuilder(1, 1, 1);
        foreach ($records as $i => $rec) {
            $p = $v->check($i, json_encode($rec));
            self::assertInstanceOf(ParsedLine::class, $p);
            self::assertArrayNotHasKey('extra', $b->build($p)->row, $rec['record_type']);
        }
        $facts = array_values(array_filter($records, static fn ($r) => $r['record_type'] === 'host_facts'))[0];
        self::assertSame('Windows 11 Pro', $facts['data']['os_product_name']);
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
