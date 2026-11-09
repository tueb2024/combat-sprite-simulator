<?php

namespace JumPHP\Native;

/** A native feature this target does not have (the Task of the call fails with it; Native::supports() says it first). */
final class UnsupportedFeature extends \RuntimeException
{
    public function __construct(public readonly string $feature, string $provider)
    {
        parent::__construct("native feature '$feature' is not available here ($provider)");
    }
}
