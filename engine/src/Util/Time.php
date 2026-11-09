<?php

namespace JumPHP\Util;

/**
 * Clock helpers that are safe in the browser build, where PHP_INT_SIZE is 4:
 * (int)(microtime(true) * 1000) overflows there, so everything stays in float seconds.
 */
final class Time
{
    private static ?float $origin = null;

    /** Seconds since the first call (float, monotonic when hrtime is available). */
    public static function now(): float
    {
        $t = self::raw();
        self::$origin ??= $t;
        return $t - self::$origin;
    }

    private static function raw(): float
    {
        if (function_exists('hrtime')) {
            $ns = hrtime(true); // float on 32-bit builds
            return (float)$ns / 1e9;
        }
        // keep the magnitude small: a day of seconds fits a float with microsecond precision
        return fmod(microtime(true), 86400.0);
    }
}
