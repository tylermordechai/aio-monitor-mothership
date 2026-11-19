<?php

declare(strict_types=1);

namespace Aio\Ingest;

use Aio\Ingest\Schema\TableMap;
use Aio\Util\Ip;
use Aio\Util\Time;

/**
 * Maps a validated line to a row of its table (ingest-api.md §3.4 rule 9, §9;
 * MS-ING-05, M-007, M-010).
 *
 * - A data field whose name is a column of the table is type-checked against
 *   that column and stored there.
 * - Unknown top-level fields are kept in `extra`, never quarantined (D-073).
 * - `<x>_ip` columns are filled from `<x>_addr` (M-010).
 * - Required fields are the record type's list (§9) plus every NOT NULL
 *   column without a default; a line without one is quarantined.
 */
final class RowBuilder
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const INT_RANGES = [
        'tinyint' => [-128, 127, 255],
        'smallint' => [-32768, 32767, 65535],
        'mediumint' => [-8388608, 8388607, 16777215],
        'int' => [-2147483648, 2147483647, 4294967295],
        'integer' => [-2147483648, 2147483647, 4294967295],
        'bigint' => [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX],
    ];

    /** @var array<string, array{fill: array<string, true>, consumed: array<string, string>, required: list<array{0:string,1:string}>}> */
    private array $plans = [];

    public function __construct(
        private readonly int $agentPk,
        private readonly int $hostPk,
        private readonly int $batchPk,
    ) {
    }

    /** @throws FieldError */
    public function build(ParsedLine $line): BuiltRow
    {
        $spec = RecordTypes::get($line->recordType);
        if ($spec === null) {
            throw new \LogicException('build() needs a known record type');
        }
        $table = $spec['table'];
        $kind = $spec['kind'];
        $cols = TableMap::columns($table);
        $plan = $this->plan($line->recordType, $spec, $cols);
        $data = $line->data;

        // Required fields first, in the order of §9's list (one reason per line).
        foreach ($plan['required'] as [$field, $column]) {
            if (!property_exists($data, $field) || $data->$field === null) {
                throw FieldError::missing($field);
            }
        }

        $row = [];
        $extra = [];
        $commandLine = null;
        foreach (get_object_vars($data) as $field => $value) {
            $field = (string) $field;
            if (isset($plan['consumed'][$field])) {
                $target = $plan['consumed'][$field];
                if ($target === '@command_line') {
                    if ($value !== null) {
                        if (!is_string($value) || strlen($value) > 65_535) {
                            throw FieldError::invalid($field);
                        }
                        $commandLine = $value;
                    }
                } elseif ($target === '@key') {
                    if ($value !== null) {
                        if (!is_string($value) || $value === '' || mb_strlen($value) > 1024) {
                            throw FieldError::invalid($field);
                        }
                        $row['item_key'] = $value;
                        $row['key_source'] = 'agent';
                    }
                } else {
                    $row[$target] = $this->convert($field, $value, $cols[$target]);
                }
                continue;
            }
            if (isset($cols[$field]) && !$cols[$field]['generated'] && !isset($plan['fill'][$field])) {
                $row[$field] = $this->convert($field, $value, $cols[$field]);
                continue;
            }
            $extra[$field] = $value;
        }

        // Envelope and ingest-filled columns.
        $fill = [
            'agent_pk' => $this->agentPk,
            'host_pk' => $this->hostPk,
            'seq' => $line->seq,
            'ts' => $line->ts,
            'batch_pk' => $this->batchPk,
            'record_type' => $line->recordType,
        ];
        foreach ($fill as $c => $v) {
            if (isset($cols[$c])) {
                $row[$c] = $v;
            }
        }
        if ($kind === 'item' && !isset($row['item_key'])) {
            $row['item_key'] = ItemKeys::derive($line->recordType, $data);
            $row['key_source'] = 'derived';
        }
        if ($kind === 'executable' || $kind === 'gap') {
            unset($row['seq'], $row['ts']);
            $row['first_seq'] = $line->seq;
            $row['last_seq'] = $line->seq;
            if ($kind === 'executable') {
                $row['first_seen'] = $line->ts;
                $row['last_seen'] = $line->ts;
            } else {
                $row['first_ts'] = $line->ts;
                $row['last_ts'] = $line->ts;
            }
        }
        if ($kind === 'process') {
            $row['has_command_line'] = $commandLine === null ? 0 : 1;
        }
        // <x>_ip from <x>_addr.
        foreach ($cols as $c => $meta) {
            if (str_ends_with($c, '_ip') && in_array($meta['type'], ['varbinary', 'binary'], true)) {
                $addr = substr($c, 0, -3) . '_addr';
                if (isset($row[$addr]) && is_string($row[$addr])) {
                    $row[$c] = Ip::toBinary($row[$addr]);
                }
            }
        }
        if ($extra !== [] && isset($cols['extra'])) {
            $row['extra'] = json_encode((object) $extra, self::JSON_FLAGS);
        }
        // A NOT NULL column still empty here means a required field that the
        // data had as null or that the agent's format calls differently.
        foreach ($plan['required'] as [$field, $column]) {
            if (!isset($row[$column])) {
                throw FieldError::missing($field);
            }
        }
        return new BuiltRow($line, $table, $kind, $row, $commandLine);
    }

    /**
     * @param array{table: string, required: list<string>, kind: string} $spec
     * @param array<string, array<string, mixed>> $cols
     * @return array{fill: array<string, true>, consumed: array<string, string>, required: list<array{0:string,1:string}>}
     */
    private function plan(string $type, array $spec, array $cols): array
    {
        if (isset($this->plans[$type])) {
            return $this->plans[$type];
        }
        $kind = $spec['kind'];
        $fill = array_fill_keys(['agent_pk', 'host_pk', 'seq', 'ts', 'batch_pk', 'record_type', 'extra'], true);
        $consumed = [];
        if ($kind === 'item') {
            $fill += array_fill_keys(['item_key', 'key_source'], true);
            $consumed['key'] = '@key';
        }
        if ($kind === 'executable') {
            $fill += array_fill_keys(['id', 'first_seen', 'last_seen', 'first_seq', 'last_seq'], true);
        }
        if ($kind === 'gap') {
            $fill += array_fill_keys(['id', 'gap_start', 'gap_end', 'first_seq', 'last_seq', 'first_ts', 'last_ts'], true);
            $consumed['start'] = 'gap_start';
            $consumed['end'] = 'gap_end';
        }
        if ($kind === 'process') {
            $fill['has_command_line'] = true;
            $consumed['command_line'] = '@command_line';
        }
        $required = [];
        $seen = [];
        foreach ($spec['required'] as $f) {
            $col = $consumed[$f] ?? $f;
            $required[] = [$f, $col];
            $seen[$col] = true;
        }
        foreach ($cols as $c => $meta) {
            if ($meta['nullable'] || $meta['has_default'] || $meta['generated'] || isset($fill[$c]) || isset($seen[$c])) {
                continue;
            }
            if (str_ends_with($c, '_ip')) {
                continue;
            }
            $field = array_search($c, $consumed, true);
            $required[] = [$field === false ? $c : (string) $field, $c];
        }
        return $this->plans[$type] = ['fill' => $fill, 'consumed' => $consumed, 'required' => $required];
    }

    /**
     * @param array{type: string, length: ?int, unsigned: bool, nullable: bool, enum: ?list<string>} $col
     * @throws FieldError
     */
    private function convert(string $field, mixed $v, array $col): int|string|null
    {
        if ($v === null) {
            return null;
        }
        $type = $col['type'];
        switch ($type) {
            case 'bool':
                if (!is_bool($v)) {
                    throw FieldError::invalid($field);
                }
                return $v ? 1 : 0;
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'bigint':
                if (!is_int($v)) {
                    throw FieldError::invalid($field);
                }
                [$min, $max, $umax] = self::INT_RANGES[$type];
                if ($col['unsigned'] ? ($v < 0 || $v > $umax) : ($v < $min || $v > $max)) {
                    throw FieldError::invalid($field);
                }
                return $v;
            case 'double':
            case 'float':
            case 'decimal':
                if (!is_int($v) && !is_float($v)) {
                    throw FieldError::invalid($field);
                }
                return (string) $v;
            case 'varchar':
            case 'char':
                if (!is_string($v) || mb_strlen($v) > (int) $col['length']) {
                    throw FieldError::invalid($field);
                }
                return $v;
            case 'text':
                if (!is_string($v) || strlen($v) > 65_535) {
                    throw FieldError::invalid($field);
                }
                return $v;
            case 'mediumtext':
                if (!is_string($v) || strlen($v) > 16_777_215) {
                    throw FieldError::invalid($field);
                }
                return $v;
            case 'enum':
                if (!is_string($v) || !in_array($v, (array) $col['enum'], true)) {
                    throw FieldError::invalid($field);
                }
                return $v;
            case 'datetime':
                if (!is_string($v)) {
                    throw FieldError::invalid($field);
                }
                $t = Time::parse($v);
                if ($t === null) {
                    throw FieldError::invalid($field);
                }
                if ($t['zero']) {
                    if (!$col['nullable']) {
                        throw FieldError::invalid($field);
                    }
                    return null;
                }
                return $t['db'];
            case 'json':
                try {
                    return json_encode($v, self::JSON_FLAGS);
                } catch (\JsonException) {
                    throw FieldError::invalid($field);
                }
            default:
                // Binary and other columns are never filled from the agent's data.
                throw FieldError::invalid($field);
        }
    }
}
