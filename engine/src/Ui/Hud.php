<?php

namespace JumPHP\Ui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * Small drawing helpers for heads-up displays and dialogs.
 */
final class Hud
{
    /** A translucent box with a border (dialogs, pause screens). */
    public static function panel(Renderer $r, float $x, float $y, float $w, float $h, array $fill = [0, 0, 40, 200], array $border = [255, 255, 255]): void
    {
        $r->fillRect($x, $y, $w, $h, $fill);
        $r->drawRect($x, $y, $w, $h, $border);
    }

    /** A gauge (life, time, loading): $ratio 0..1. */
    public static function bar(Renderer $r, float $x, float $y, float $w, float $h, float $ratio, array $color = [80, 220, 80], array $back = [40, 40, 40]): void
    {
        $r->fillRect($x, $y, $w, $h, $back);
        $r->fillRect($x, $y, $w * max(0.0, min(1.0, $ratio)), $h, $color);
    }

    /** Text in a panel sized to it, centred on ($cx, $cy). */
    public static function message(Renderer $r, TextDrawer $font, string $text, float $cx, float $cy, float $scale = 1.0): void
    {
        $w = $font->width($text, $scale) + 16 * $scale;
        $lines = substr_count($text, "\n") + 1;
        $h = $font->lineHeight($scale) * $lines + 10 * $scale;
        self::panel($r, $cx - $w / 2, $cy - $h / 2, $w, $h);
        $font->draw($r, $text, $cx, $cy - $h / 2 + 6 * $scale, $scale, [255, 255, 255], 'center');
    }

    /** Blinks: true half of the time ($period seconds). */
    public static function blink(float $time, float $period = 0.8): bool
    {
        return fmod($time, $period) < $period / 2;
    }
}
