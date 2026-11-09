<?php

namespace JumPHP\Tauri;

/** A call from a page: jumphp.invoke(name, payload). Given to the command's handler after the payload. */
final class Invocation
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly mixed $payload,
        public readonly string $window,
    ) {
    }
}
