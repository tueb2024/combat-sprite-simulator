<?php

namespace JumPHP\Native;

/** A notification of the system (desktop apps). @see Native */
final class Notification
{
    /** @return bool false when this target has none */
    public static function show(string $title, string $body = ''): bool
    {
        return Native::provider()->notify($title, $body);
    }
}
