<?php

declare(strict_types=1);

namespace Aio\Api;

use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Db;
use Aio\Heartbeat\HeartbeatService;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Ingest\IngestService;
use Aio\Ingest\Throttle;
use Aio\Ingest\WriteWarning;
use PDO;

/**
 * The agent API on 8443 (ingest-api.md §1). Milestone 2 serves ingest and the
 * heartbeat (M-043); enrol, config and renew come with milestone 3.
 *
 * Errors use the §7 shape. Logs name the error class and code only, never
 * request content, tokens or keys (MS-SEC-10).
 */
final class AgentApi
{
    private const ROUTES = [
        '/api/v1/ingest' => 'POST',
        '/api/v1/heartbeat' => 'POST',
    ];

    /** @param \Closure(): PDO $connect */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $connect,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config, static fn (): PDO => Db::connect(
            $config->string('db', 'ingest_cnf'),
            $config->string('db', 'name'),
        ));
    }

    public function handle(Request $req): Response
    {
        $throttle = new Throttle($this->config);
        try {
            $method = self::ROUTES[$req->path] ?? null;
            if ($method === null) {
                throw ApiError::notFound();
            }
            if ($req->method !== $method) {
                throw ApiError::methodNotAllowed();
            }
            // Cheap refusals first: no certificate, no capacity, no disk.
            if ($req->clientVerify !== 'SUCCESS') {
                throw ApiError::clientCertificateRequired();
            }
            if ($req->path === '/api/v1/ingest') {
                $throttle->checkDisk();
                $throttle->acquireSlot();
            }
            try {
                $pdo = ($this->connect)();
            } catch (\PDOException $e) {
                self::log('db_connect', $e);
                throw ApiError::databaseUnavailable();
            }
            $agent = (new ClientCertAuth($pdo))->authenticate($req);
            $throttle->checkAgentRate($agent->agentPk);
            return $req->path === '/api/v1/ingest'
                ? (new IngestService($pdo, $this->config))->handle($req, $agent)
                : (new HeartbeatService($pdo, $this->config))->handle($req, $agent);
        } catch (ApiError $e) {
            return Response::fromError($e);
        } catch (\PDOException $e) {
            self::log('database', $e);
            return Response::fromError(ApiError::databaseUnavailable());
        } catch (WriteWarning $e) {
            self::log('write_warning', $e);
            return Response::fromError(ApiError::internal());
        } catch (\Throwable $e) {
            self::log('internal', $e);
            return Response::fromError(ApiError::internal());
        } finally {
            $throttle->release();
        }
    }

    private static function log(string $what, \Throwable $e): void
    {
        // Class, code and location only: a PDO message can quote a value.
        error_log(sprintf('aio agent-api %s: %s code=%s at %s:%d', $what, $e::class, (string) $e->getCode(), basename($e->getFile()), $e->getLine()));
    }
}
