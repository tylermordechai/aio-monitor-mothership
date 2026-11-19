<?php

declare(strict_types=1);

namespace Aio\Ingest;

use Aio\Util\Time;

/**
 * Envelope checks for one line: ingest-api.md §3.4 rules 1 to 8 (MS-ING-02, 05; M-007).
 * Rule 9 (the record's own fields) is RowBuilder's job.
 *
 * One deviation from the table's order, recorded as M-045: a type on the
 * provisional list is quarantined as "provisional type, no table yet" even
 * though it is unknown, because the list exists for types that have no
 * table yet (§9). Checking "unknown" first would make the list useless.
 */
final class LineValidator
{
    public const PROVISIONAL_REASON = 'provisional type, no table yet';

    private const ENVELOPE_STRINGS = ['agent_id', 'host_id', 'hostname', 'agent_version', 'record_type'];

    /** @var array<string, true> */
    private array $provisional;

    /**
     * @param string $agentId the certificate's agent ID (MS-ING-02)
     * @param string $hostGuid the bound host's MachineGuid (§2.5)
     * @param list<string> $provisional record types on the provisional list
     * @param array<string, int> $keepDays retention per table, from retention_policies
     */
    public function __construct(
        private readonly string $agentId,
        private readonly string $hostGuid,
        array $provisional,
        private readonly array $keepDays,
        private readonly int $maxLineBytes,
        private readonly int $maxFutureS,
        private readonly int $now,
    ) {
        $this->provisional = array_fill_keys($provisional, true);
    }

    /**
     * @return ParsedLine|QuarantinedLine
     * @throws AgentIdMismatch when the line carries another agent's ID (the whole request is refused)
     */
    public function check(int $index, string $raw): ParsedLine|QuarantinedLine
    {
        // Rule 1.
        if (strlen($raw) > $this->maxLineBytes) {
            return new QuarantinedLine($index, $raw, 'line_too_long');
        }
        // Rule 2: valid UTF-8 JSON object. Objects stay objects so {} and []
        // keep their meaning when stored as JSON again.
        try {
            $obj = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new QuarantinedLine($index, $raw, 'invalid_json');
        }
        if (!$obj instanceof \stdClass) {
            return new QuarantinedLine($index, $raw, 'invalid_json');
        }
        // §3.2 step 3: any line with an agent_id must carry the certificate's.
        if (isset($obj->agent_id) && is_string($obj->agent_id) && $obj->agent_id !== $this->agentId) {
            throw new AgentIdMismatch();
        }
        $seq = (isset($obj->seq) && is_int($obj->seq) && $obj->seq >= 1) ? $obj->seq : null;
        $type = (isset($obj->record_type) && is_string($obj->record_type)) ? $obj->record_type : null;
        $sv = (isset($obj->schema_version) && is_int($obj->schema_version)) ? $obj->schema_version : null;
        $q = new QuarantinedLine($index, $raw, '', $seq, $type === null ? null : mb_substr($type, 0, 64), $sv);

        // Rule 3.
        if (!property_exists($obj, 'schema_version') || $obj->schema_version !== 1) {
            return $q->withReason('unknown_schema_version');
        }
        // Rule 4.
        foreach (self::ENVELOPE_STRINGS as $f) {
            if (!isset($obj->$f) || !is_string($obj->$f) || $obj->$f === '') {
                return $q->withReason("invalid_envelope:$f");
            }
        }
        if ($seq === null) {
            return $q->withReason('invalid_envelope:seq');
        }
        $ts = isset($obj->ts) && is_string($obj->ts) ? Time::parse($obj->ts, true) : null;
        if ($ts === null || $ts['zero']) {
            return $q->withReason('invalid_envelope:ts');
        }
        if (!isset($obj->data) || !$obj->data instanceof \stdClass) {
            return $q->withReason('invalid_envelope:data');
        }
        // Rule 5.
        if (strtolower($obj->host_id) !== strtolower($this->hostGuid)) {
            return $q->withReason('host_id_mismatch');
        }
        // Rules 6 and 7 (see the class comment for the order).
        if (isset($this->provisional[$type])) {
            return $q->withReason(self::PROVISIONAL_REASON);
        }
        $spec = RecordTypes::get($type);
        if ($spec === null) {
            return $q->withReason('unknown_record_type');
        }
        // Rule 8.
        if ($ts['epoch'] > $this->now + $this->maxFutureS) {
            return $q->withReason('ts_out_of_range');
        }
        $keep = $this->keepDays[$spec['table']] ?? null;
        if ($keep !== null && $ts['epoch'] < $this->now - $keep * 86_400) {
            return $q->withReason('ts_out_of_range');
        }
        return new ParsedLine(
            $index,
            $raw,
            $type,
            $seq,
            $ts['db'],
            $ts['epoch'],
            $obj->hostname,
            $obj->agent_version,
            $obj->data,
        );
    }
}
