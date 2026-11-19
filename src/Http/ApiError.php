<?php

declare(strict_types=1);

namespace Aio\Http;

/**
 * An error answer in the one shape of ingest-api.md §7 (MS-ING-09, M-009).
 * The message never repeats request content.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function clientCertificateRequired(): self
    {
        return new self(401, 'client_certificate_required', 'A client certificate from the agent CA is required', false);
    }

    public static function certificateUnknown(): self
    {
        return new self(403, 'certificate_unknown', 'The client certificate is not registered', false);
    }

    public static function agentRevoked(): self
    {
        return new self(403, 'agent_revoked', 'This agent or its certificate is revoked', false);
    }

    public static function agentIdMismatch(): self
    {
        return new self(403, 'agent_id_mismatch', 'A line carries an agent_id other than the certificate\'s; nothing was stored', false);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Unknown path', false);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'method_not_allowed', 'Method not allowed', false);
    }

    public static function lengthRequired(): self
    {
        return new self(411, 'length_required', 'Content-Length is required', false);
    }

    public static function batchTooLarge(string $which): self
    {
        return new self(413, 'batch_too_large', "Batch over the $which limit; split it", false);
    }

    public static function unsupportedMediaType(string $what): self
    {
        return new self(415, 'unsupported_media_type', $what, false);
    }

    public static function badGzip(): self
    {
        return new self(400, 'bad_gzip', 'The body is not valid gzip', false);
    }

    public static function emptyBatch(): self
    {
        return new self(400, 'empty_batch', 'The batch has no lines', false);
    }

    public static function invalidJson(): self
    {
        return new self(400, 'invalid_json', 'The body is not a JSON object', false);
    }

    public static function slowDown(int $retryAfter): self
    {
        return new self(429, 'slow_down', 'Too many requests; retry later', true, $retryAfter);
    }

    public static function databaseUnavailable(): self
    {
        return new self(503, 'database_unavailable', 'The database is unavailable; nothing was acknowledged', true, 30);
    }

    public static function storageFull(): self
    {
        return new self(503, 'storage_full', 'Storage is nearly full; keep buffering', true, 300);
    }

    public static function internal(): self
    {
        return new self(500, 'internal_error', 'Internal error; nothing was committed', true);
    }

    /** @return array{error: array{code: string, message: string, retryable: bool}} */
    public function body(): array
    {
        return ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage(), 'retryable' => $this->retryable]];
    }
}
