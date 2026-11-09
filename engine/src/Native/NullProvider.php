<?php

namespace JumPHP\Native;

/** No native feature (headless runs, a server...). */
final class NullProvider extends BaseProvider
{
    public function name(): string
    {
        return 'none';
    }
}
