<?php

declare(strict_types=1);

namespace Aio\Http;

/**
 * What the API needs from a request, taken from $_SERVER once, so the
 * services can be tested without a web server.
 */
final class Request
{
    /** @param array<string, string> $headers lower-case names */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $remoteAddr,
        public readonly ?string $clientVerify,
        public readonly ?string $clientCertPem,
        public readonly ?string $clientSerial,
        public readonly ?string $clientCn,
        private readonly \Closure $bodyReader,
    ) {
    }

    /** @param array<string, mixed> $server */
    public static function fromGlobals(array $server): self
    {
        $headers = [];
        foreach ($server as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH']) && $server['CONTENT_LENGTH'] !== '') {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $s = static fn (string $k): ?string => isset($server[$k]) && $server[$k] !== '' ? (string) $server[$k] : null;
        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $headers,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            $s('SSL_CLIENT_VERIFY'),
            $s('SSL_CLIENT_CERT'),
            $s('SSL_CLIENT_M_SERIAL'),
            $s('SSL_CLIENT_S_DN_CN'),
            static fn (int $max): string|false => file_get_contents('php://input', false, null, 0, $max),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Reads at most $max bytes of the body (+1, so the caller can tell it was longer). */
    public function body(int $max): string
    {
        $b = ($this->bodyReader)($max + 1);
        return $b === false ? '' : $b;
    }

    /** @param array<string, string> $headers */
    public static function forTest(
        string $method,
        string $path,
        array $headers,
        string $body,
        ?string $certPem = null,
        string $verify = 'SUCCESS',
        string $remoteAddr = '192.168.0.10',
    ): self {
        return new self(
            $method,
            $path,
            array_change_key_case($headers, CASE_LOWER),
            $remoteAddr,
            $certPem === null ? 'NONE' : $verify,
            $certPem,
            null,
            null,
            static fn (int $max): string => substr($body, 0, $max),
        );
    }
}
