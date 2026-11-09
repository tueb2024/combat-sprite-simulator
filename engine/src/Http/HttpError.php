<?php

namespace JumPHP\Http;

/** The request got no answer at all: network down, DNS, timeout, refused, CORS (browser). */
final class HttpError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $url = '')
    {
        parent::__construct($message);
    }
}
