<?php

declare(strict_types=1);

namespace Aio\Ingest\Schema;

/**
 * The columns of each table, generated from db/migrations by
 * tools/gen-table-map.php (MS-DAT-01).
 */
final class TableMap
{
    /** @var array<string, array{columns: array<string, array{type: string, length: ?int, unsigned: bool, nullable: bool, has_default: bool, generated: bool, enum: ?list<string>}>}>|null */
    private static ?array $map = null;

    /** @return array<string, array{type: string, length: ?int, unsigned: bool, nullable: bool, has_default: bool, generated: bool, enum: ?list<string>}> */
    public static function columns(string $table): array
    {
        self::$map ??= require __DIR__ . '/table_map.php';
        if (!isset(self::$map[$table])) {
            throw new \OutOfBoundsException("table $table is not in the table map");
        }
        return self::$map[$table]['columns'];
    }

    /** @return list<string> */
    public static function tables(): array
    {
        self::$map ??= require __DIR__ . '/table_map.php';
        return array_keys(self::$map);
    }
}
