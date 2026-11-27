<?php

declare(strict_types=1);

namespace Aio\Http;

/** A JSON response (ingest-api.md §1: every answer is JSON). */
final class Response
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = [],
    ) {
    }

    public static function fromError(ApiError $e): self
    {
        $headers = [];
        if ($e->retryAfter !== null) {
            $headers['Retry-After'] = (string) $e->retryAfter;
        }
        return new self($e->status, $e->body(), $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        if ($this->status === 304) {
            // Not Modified has no body (§5).
            return;
        }
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
