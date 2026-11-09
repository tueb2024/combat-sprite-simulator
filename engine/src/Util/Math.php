<?php

namespace JumPHP\Util;

final class Math
{
    public static function clamp(float $value, float $min, float $max): float
    {
        return $value < $min ? $min : ($value > $max ? $max : $value);
    }

    public static function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }

    /** Moves $value towards $target by at most $delta (never overshoots). */
    public static function approach(float $value, float $target, float $delta): float
    {
        return $value < $target ? min($value + $delta, $target) : max($value - $delta, $target);
    }

    public static function sign(float $value): int
    {
        return $value > 0 ? 1 : ($value < 0 ? -1 : 0);
    }

    /** Integer division that floors towards -infinity (tile coordinates of negative pixels). */
    public static function floorDiv(int|float $value, int $divisor): int
    {
        return (int)floor($value / $divisor);
    }
}
