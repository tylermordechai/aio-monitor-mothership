<?php

declare(strict_types=1);

namespace Aio\Web;

/**
 * A dashboard response. The security headers (CSP, frame and referrer
 * policy, no caching) are set by Apache for every answer (M-054); this class
 * only adds the content type and, for the page-time check, X-Render-Ms.
 */
final class WebResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
            ['Content-Type' => 'application/json'],
        );
    }

    /** 303 See Other: after a form post, the browser fetches the target with GET. */
    public static function redirect(string $to): self
    {
        return new self(303, '', ['Location' => $to]);
    }

    public function with(string $name, string $value): self
    {
        return new self($this->status, $this->body, [$name => $value] + $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
