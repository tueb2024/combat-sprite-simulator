<?php

namespace JumPHP\Http;

/** An HTTP answer (any status: check ok()). Header names are lower case. */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly string $url = '',
    ) {
    }

    /** 2xx */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** The body decoded as JSON (arrays). @throws \JsonException */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
