<?php

declare(strict_types=1);

namespace Aio\Api;

use Aio\AgentConfig\ConfigService;
use Aio\Auth\ClientCertAuth;
use Aio\Enrol\EnrolService;
use Aio\Enrol\RenewService;
use Aio\Config;
use Aio\Db;
use Aio\Heartbeat\HeartbeatService;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Ingest\IngestService;
use Aio\Ingest\Throttle;
use Aio\Ingest\WriteWarning;
use Aio\Pki\CertSigner;
use Aio\Pki\SignerClient;
use Aio\Pki\SignerUnavailable;
use PDO;

/**
 * The agent API on 8443 (ingest-api.md §1). Milestone 2 serves ingest and the
 * heartbeat (M-043); milestone 3 adds enrol (M-062), renew (M-064) and config (M-065).
 *
 * Errors use the §7 shape. Logs name the error class and code only, never
 * request content, tokens or keys (MS-SEC-10).
 */
final class AgentApi
{
    private const ROUTES = [
        '/api/v1/ingest' => 'POST',
        '/api/v1/heartbeat' => 'POST',
        '/api/v1/enroll' => 'POST',
        '/api/v1/renew' => 'POST',
        '/api/v1/config' => 'GET',
    ];

    /**
     * @param \Closure(): PDO $connect
     * @param \Closure(): CertSigner|null $signer the signing service (M-060); tests pass their own
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $connect,
        private readonly ?\Closure $signer = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config, static fn (): PDO => Db::connect(
            $config->string('db', 'ingest_cnf'),
            $config->string('db', 'name'),
        ));
    }

    /** POST /api/v1/enroll: no client certificate; the one-time token is the credential (§2). */
    private function enrol(Request $req): Response
    {
        $caPem = $this->caPem();
        try {
            $pdo = ($this->connect)();
        } catch (\PDOException $e) {
            self::log('db_connect', $e);
            throw ApiError::databaseUnavailable();
        }
        return (new EnrolService($pdo, $this->config, $this->signer(), $caPem))->handle($req);
    }

    private function signer(): CertSigner
    {
        return $this->signer !== null ? ($this->signer)() : new SignerClient();
    }

    private function caPem(): string
    {
        $pem = @file_get_contents($this->config->string('enrol', 'agent_ca_public'));
        if ($pem === false) {
            throw new \RuntimeException('agent CA certificate unreadable');
        }
        return $pem;
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
            if ($req->path === '/api/v1/enroll') {
                return $this->enrol($req);
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
            return match ($req->path) {
                '/api/v1/ingest' => (new IngestService($pdo, $this->config))->handle($req, $agent),
                // §2.6, M-064: a new certificate for the agent the current one names.
                '/api/v1/renew' => (new RenewService($pdo, $this->signer(), $this->caPem()))->handle($req, $agent),
                // §5, M-065: read-only, the settings of the agent's group.
                '/api/v1/config' => (new ConfigService($pdo, $this->config))->handle($req, $agent),
                default => (new HeartbeatService($pdo, $this->config))->handle($req, $agent),
            };
        } catch (ApiError $e) {
            return Response::fromError($e);
        } catch (\PDOException $e) {
            self::log('database', $e);
            return Response::fromError(ApiError::databaseUnavailable());
        } catch (SignerUnavailable $e) {
            // Nothing was committed and the token is unused: the agent retries.
            self::log('signer', $e);
            return Response::fromError(ApiError::internal());
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
