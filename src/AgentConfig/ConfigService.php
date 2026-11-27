<?php

declare(strict_types=1);

namespace Aio\AgentConfig;

use Aio\Auth\AgentContext;
use Aio\Config;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Util\Time;
use PDO;

/**
 * GET /api/v1/config (ingest-api.md §5; MS-ING-07, AG-CFG-01..03, M-065).
 * Read-only for the agent: it only changes agent settings, never the host
 * (AG-SEC-07). The settings of the agent's host group, checked again here
 * (AgentSettings), with an ETag; If-None-Match with the current ETag gets
 * 304 and no body. The heartbeat answers with the same ETag (§4).
 */
final class ConfigService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /**
     * The settings for a group, with their ETag.
     *
     * @return array{config: array<string, mixed>, etag: string, rejected: list<string>}
     */
    public function forGroup(int $groupId): array
    {
        $s = $this->pdo->prepare(
            'SELECT g.name, c.config_version, c.settings, c.exclusions, c.redaction_rules, c.detection_list, c.replace_builtin_detection
               FROM host_groups g LEFT JOIN agent_config c ON c.group_id = g.id WHERE g.id = ?'
        );
        $s->execute([$groupId]);
        $r = $s->fetch();
        $row = is_array($r) && $r['config_version'] !== null ? $r : null;
        $b = AgentSettings::build(
            $row,
            is_array($r) ? (string) $r['name'] : '',
            $this->config->int('agent_config', 'max_exclusions'),
            $this->config->int('agent_config', 'max_redaction_rules'),
        );
        return ['config' => $b['config'], 'etag' => AgentSettings::etag($groupId, $b['config']), 'rejected' => $b['rejected']];
    }

    public function handle(Request $req, AgentContext $agent): Response
    {
        $c = $this->forGroup($agent->groupId);
        if ($c['rejected'] !== []) {
            // Place and reason only, never a value (MS-SEC-10).
            error_log("aio agent-api config group_id={$agent->groupId}: left out " . implode('; ', array_slice($c['rejected'], 0, 20)));
        }
        $headers = ['ETag' => $c['etag']];
        $inm = $req->header('if-none-match');
        if ($inm !== null && in_array($c['etag'], array_map('trim', explode(',', $inm)), true)) {
            return new Response(304, [], $headers);
        }
        // generated_at is outside the ETag: it only says when this copy was made.
        return new Response(200, ['generated_at' => Time::rfc3339()] + $c['config'], $headers);
    }
}
