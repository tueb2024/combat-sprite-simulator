<?php

namespace JumPHP\Renderer;

/**
 * Old TV look: dark scanlines every other line of the view, drawn over the frame.
 * Call it at the end of a scene's render(): CrtFilter::apply($r);
 */
final class CrtFilter
{
    public static function apply(Renderer $r, int $strength = 60): void
    {
        $w = $r->getWidth();
        for ($y = 1, $h = $r->getHeight(); $y < $h; $y += 2) {
            $r->fillRect(0, $y, $w, 1, [0, 0, 0, $strength]);
        }
    }
}
