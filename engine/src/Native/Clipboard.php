<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/**
 * The system's clipboard (text).
 *
 *   Clipboard::copy('https://jumphp.net/pong');
 *   Clipboard::paste()->then(fn (string $text) => ...);
 */
final class Clipboard
{
    /** @return bool false when this target has none */
    public static function copy(string $text): bool
    {
        return Native::provider()->copy($text);
    }

    /** @return Task string */
    public static function paste(): Task
    {
        return Native::provider()->paste();
    }
}
