<?php

namespace JumPHP\Kernel;

/**
 * Where the game runs. PHP_OS says "Linux" in the browser too: the page defines JUMPHP_WASM instead.
 * Settings come from constants defined by the page prelude (browser) or environment variables (desktop):
 * JUMPHP_NET_URL, JUMPHP_ROOM, JUMPHP_LANG, JUMPHP_DEBUG, JUMPHP_MOBILE, JUMPHP_MAX_FRAMES...
 */
final class Platform
{
    public static function isWasm(): bool
    {
        return defined('JUMPHP_WASM') && JUMPHP_WASM;
    }

    public static function isDesktop(): bool
    {
        return !self::isWasm();
    }

    public static function isMobile(): bool
    {
        return (bool)self::setting('JUMPHP_MOBILE', false);
    }

    /** The native phone app (Android: JUMPHP_ANDROID, iPhone / iPad: JUMPHP_IOS): always full screen, the game's orientation. */
    public static function isPhoneApp(): bool
    {
        return (bool)self::setting('JUMPHP_ANDROID', false) || (bool)self::setting('JUMPHP_IOS', false);
    }

    /** A path from the root of a disk ('/x', '\\x', 'C:\\x', 'C:/x') or a stream ('phar://...'): not under the game's folder. */
    public static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^([a-zA-Z]:[\\\\/]|[a-z][a-z0-9+.-]*://)#', $path) === 1);
    }

    /** Headless run: smoke test, screenshot or a game test (no window, dummy audio). */
    public static function isHeadless(): bool
    {
        return self::setting('JUMPHP_SMOKE') !== null || self::setting('JUMPHP_SCREENSHOT') !== null
            || self::setting('JUMPHP_TEST') !== null;
    }

    /** A constant, else an environment variable, else $default. */
    public static function setting(string $name, mixed $default = null): mixed
    {
        if (defined($name)) {
            return constant($name);
        }
        $env = getenv($name);
        return ($env === false || $env === '') ? $default : $env;
    }
}
