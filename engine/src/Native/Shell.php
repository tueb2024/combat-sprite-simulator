<?php

namespace JumPHP\Native;

/**
 * Opens things with the system's programs: a link in the browser, a file with its program, a file shown in the
 * file manager. Returns false when this target cannot.
 */
final class Shell
{
    public static function openUrl(string $url): bool
    {
        return Native::provider()->openUrl($url);
    }

    public static function openPath(string $path): bool
    {
        return Native::provider()->openPath($path);
    }

    public static function reveal(string $path): bool
    {
        return Native::provider()->reveal($path);
    }
}
