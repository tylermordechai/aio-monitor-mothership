<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Ingest\Schema\TableMap;
use PHPUnit\Framework\TestCase;

/** The generated table map equals the migrated database (MS-DAT-01). */
final class TableMapMatchesDatabaseTest extends TestCase
{
    public function testColumnsMatchInformationSchema(): void
    {
        $pdo = TestDb::pdo($this);
        $rows = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, EXTRA
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'aio_test' ORDER BY TABLE_NAME, ORDINAL_POSITION"
        )->fetchAll();
        $db = [];
        foreach ($rows as $r) {
            $db[$r['TABLE_NAME']][$r['COLUMN_NAME']] = $r;
        }
        self::assertEqualsCanonicalizing(array_keys($db), TableMap::tables(), 'same tables');
        foreach (TableMap::tables() as $t) {
            $map = TableMap::columns($t);
            self::assertSame(array_keys($db[$t]), array_keys($map), "$t: same columns in the same order");
            foreach ($map as $c => $m) {
                $d = $db[$t][$c];
                $type = $m['type'] === 'bool' ? 'tinyint' : $m['type'];
                self::assertSame($type, $d['DATA_TYPE'] === 'longtext' && $m['type'] === 'json' ? 'json' : $d['DATA_TYPE'], "$t.$c type");
                self::assertSame($m['nullable'], $d['IS_NULLABLE'] === 'YES', "$t.$c nullable");
                self::assertSame($m['generated'], str_contains(strtoupper($d['EXTRA']), 'GENERATED'), "$t.$c generated");
                self::assertSame($m['unsigned'], str_contains($d['COLUMN_TYPE'], 'unsigned'), "$t.$c unsigned");
            }
        }
    }
}
